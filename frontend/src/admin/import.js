// Модалка імпорту: файл → перегляд → результат
import { api, ApiError } from '../shared/api/index.js';
import { LANGS } from '../shared/constants.js';
import { CATEGORY_LABELS, describeFieldErrors } from './labels.js';
import { parseSheetFile, SheetError } from './parse-sheet.js';
import { errorMessage, handleError } from './session.js';
import { closeOnBackdrop, escapeHtml, pluralRu } from './ui.js';

// Обмеження контракту на один запит
const CHUNK_SIZE = 1000;
const PREVIEW_ROWS = 5;

const phrasesWord = (n) => pluralRu(n, 'фраза', 'фразы', 'фраз');

export function initImportDialog({ onImported }) {
  const dialog = document.querySelector('[data-import-dialog]');
  const fileInput = dialog.querySelector('[data-import-file]');
  const dropzone = dialog.querySelector('[data-dropzone]');
  const fileError = dialog.querySelector('[data-import-error]');
  const summary = dialog.querySelector('[data-import-summary]');
  const preview = dialog.querySelector('[data-import-preview]');
  const resultText = dialog.querySelector('[data-import-result]');
  const resultErrors = dialog.querySelector('[data-import-result-errors]');
  const submit = dialog.querySelector('[data-import-submit]');
  const back = dialog.querySelector('[data-import-back]');

  let parsed = [];
  let imported = false;

  function showStep(step) {
    dialog.querySelectorAll('[data-import-step]').forEach((el) => {
      el.hidden = el.dataset.importStep !== step;
    });
    back.hidden = step !== 'preview';
    submit.hidden = step !== 'preview' || !parsed.some((row) => !row.fields);
  }

  function reset() {
    parsed = [];
    fileInput.value = '';
    fileError.hidden = true;
    showStep('file');
  }

  function renderErrorRows(rows) {
    return `
      <table class="mini-table mini-table--errors">
        <thead><tr><th>Строка</th><th>Проблема</th></tr></thead>
        <tbody>
          ${rows.map(({ rowNumber, fields }) => `
            <tr><td>${rowNumber}</td><td>${escapeHtml(describeFieldErrors(fields))}</td></tr>
          `).join('')}
        </tbody>
      </table>
    `;
  }

  function renderPreview(missingColumns) {
    const valid = parsed.filter((row) => !row.fields);
    const invalid = parsed.filter((row) => row.fields);

    summary.innerHTML = `
      В файле <b>${parsed.length}</b> ${phrasesWord(parsed.length)}:
      <b>${valid.length}</b> готовы к импорту${invalid.length ? `, <b class="text-danger">${invalid.length}</b> с ошибками — они будут пропущены` : ''}.
    `;

    preview.innerHTML = `
      ${missingColumns.length ? `
        <p class="notice">В файле нет столбцов: ${missingColumns.map((c) => c.toUpperCase()).join(', ')}. Эти переводы останутся пустыми.</p>
      ` : ''}
      ${invalid.length ? `<h3 class="subheading">Ошибки</h3>${renderErrorRows(invalid)}` : ''}
      ${valid.length ? `
        <h3 class="subheading">Первые фразы из файла</h3>
        <table class="mini-table">
          <thead><tr><th>Строка</th><th>Категория</th>${LANGS.map((l) => `<th>${l.toUpperCase()}</th>`).join('')}</tr></thead>
          <tbody>
            ${valid.slice(0, PREVIEW_ROWS).map(({ rowNumber, item }) => `
              <tr>
                <td>${rowNumber}</td>
                <td>${CATEGORY_LABELS[item.category]}</td>
                ${LANGS.map((lang) => `<td>${item.text[lang] ? escapeHtml(item.text[lang]) : '<span class="missing">—</span>'}</td>`).join('')}
              </tr>
            `).join('')}
          </tbody>
        </table>
      ` : ''}
    `;

    submit.textContent = `Импортировать ${valid.length} ${phrasesWord(valid.length)}`;
    showStep('preview');
  }

  async function handleFile(file) {
    if (!file) return;
    fileError.hidden = true;
    dropzone.classList.add('is-loading');
    try {
      const { rows, missingColumns } = await parseSheetFile(file);
      parsed = rows;
      renderPreview(missingColumns);
    } catch (error) {
      fileError.textContent = error instanceof SheetError ? error.message : 'Не удалось прочитать файл.';
      fileError.hidden = false;
      if (!(error instanceof SheetError)) console.error(error);
    } finally {
      dropzone.classList.remove('is-loading');
      fileInput.value = '';
    }
  }

  async function runImport() {
    const valid = parsed.filter((row) => !row.fields);
    let created = 0;
    const errors = [];
    let failedAt = null;

    submit.disabled = true;
    back.disabled = true;
    submit.textContent = 'Импортируем…';

    for (let start = 0; start < valid.length; start += CHUNK_SIZE) {
      const chunk = valid.slice(start, start + CHUNK_SIZE);
      try {
        const result = await api.importPhrases(chunk.map((row) => row.item));
        created += result.created;
        for (const { index, fields } of result.errors) {
          errors.push({ rowNumber: chunk[index].rowNumber, fields });
        }
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          dialog.close();
          handleError(error);
          return;
        }
        failedAt = { rowNumber: chunk[0].rowNumber, message: errorMessage(error) };
        break;
      }
    }

    submit.disabled = false;
    back.disabled = false;
    if (created) imported = true;

    const skipped = parsed.length - valid.length + errors.length;
    resultText.innerHTML = `
      Добавлено <b>${created}</b> ${phrasesWord(created)}.
      ${skipped ? `Пропущено с ошибками: <b class="text-danger">${skipped}</b>.` : ''}
      ${failedAt ? `<br><span class="text-danger">Импорт прерван на строке ${failedAt.rowNumber}: ${escapeHtml(failedAt.message)} Строки, начиная с этой, не добавлены.</span>` : ''}
    `;
    const allErrors = [...parsed.filter((row) => row.fields), ...errors].sort((a, b) => a.rowNumber - b.rowNumber);
    resultErrors.innerHTML = allErrors.length ? renderErrorRows(allErrors) : '';
    showStep('result');
  }

  fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));
  dropzone.addEventListener('dragenter', () => dropzone.classList.add('is-dragover'));
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
  dropzone.addEventListener('drop', () => dropzone.classList.remove('is-dragover'));

  submit.addEventListener('click', runImport);
  back.addEventListener('click', reset);
  dialog.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
  closeOnBackdrop(dialog);

  dialog.addEventListener('close', () => {
    if (imported) onImported();
    imported = false;
  });

  return {
    open() {
      reset();
      dialog.showModal();
    },
  };
}
