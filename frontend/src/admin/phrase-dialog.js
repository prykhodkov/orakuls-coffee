// Модалка створення / редагування фрази
import { api, ApiError } from '../shared/api/index.js';
import { CATEGORIES, LANGS, TEXT_MAX_LENGTH } from '../shared/constants.js';
import { validatePhrase } from '../shared/validation.js';
import { CATEGORY_LABELS, fieldErrorText, LANG_LABELS } from './labels.js';
import { errorMessage, handleError } from './session.js';
import { closeOnBackdrop, toast } from './ui.js';

export function initPhraseDialog({ onSaved }) {
  const dialog = document.querySelector('[data-phrase-dialog]');
  const form = dialog.querySelector('[data-phrase-form]');
  const title = dialog.querySelector('[data-phrase-title]');
  const formError = dialog.querySelector('[data-phrase-error]');
  const submit = form.querySelector('[type="submit"]');

  const categorySelect = form.querySelector('[data-category-select]');
  for (const category of CATEGORIES) {
    categorySelect.add(new Option(CATEGORY_LABELS[category], category));
  }

  form.querySelector('[data-text-fields]').innerHTML = LANGS.map((lang) => `
    <label class="field">
      <span class="field__label">
        ${lang.toUpperCase()} — ${LANG_LABELS[lang]}
        <span class="field__counter" data-counter="${lang}"></span>
      </span>
      <textarea class="input textarea" name="text.${lang}" rows="3" lang="${lang}"
                placeholder="Нет перевода — на этом языке фраза показываться не будет"></textarea>
      <span class="field__error" data-error-for="text.${lang}"></span>
    </label>
  `).join('');

  let editingId = null;

  function updateCounter(lang) {
    const length = form.elements[`text.${lang}`].value.trim().length;
    const counter = form.querySelector(`[data-counter="${lang}"]`);
    counter.textContent = `${length} / ${TEXT_MAX_LENGTH}`;
    counter.classList.toggle('is-over', length > TEXT_MAX_LENGTH);
  }

  function showErrors(fields = {}) {
    form.querySelectorAll('[data-error-for]').forEach((el) => {
      const code = fields[el.dataset.errorFor];
      el.textContent = code ? fieldErrorText(code) : '';
    });
    form.querySelectorAll('.input').forEach((input) => {
      input.classList.toggle('is-invalid', Boolean(fields[input.name]));
    });
  }

  function readForm() {
    return {
      category: form.elements.category.value,
      text: Object.fromEntries(LANGS.map((lang) => [lang, form.elements[`text.${lang}`].value])),
      isActive: form.elements.isActive.checked,
    };
  }

  // Коли поле змінюють — прибираємо його помилку
  function clearError(name) {
    form.querySelector(`[data-error-for="${name}"]`).textContent = '';
    form.elements[name].classList.remove('is-invalid');
  }

  form.addEventListener('change', (event) => {
    if (event.target.name === 'category') clearError('category');
  });

  form.addEventListener('input', (event) => {
    const lang = event.target.name?.startsWith('text.') && event.target.name.slice(5);
    if (!lang) return;
    updateCounter(lang);
    clearError(event.target.name);
    form.querySelector('[data-error-for="text"]').textContent = '';
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    formError.hidden = true;

    const { value, fields } = validatePhrase(readForm());
    showErrors(fields ?? {});
    if (fields) return;

    submit.disabled = true;
    try {
      if (editingId) await api.updatePhrase(editingId, value);
      else await api.createPhrase(value);
      dialog.close();
      toast(editingId ? `Фраза №${editingId} сохранена` : 'Фраза добавлена');
      onSaved();
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        showErrors(error.fields);
      } else if (error instanceof ApiError && error.status === 401) {
        dialog.close();
        handleError(error);
      } else {
        formError.textContent = errorMessage(error);
        formError.hidden = false;
      }
    } finally {
      submit.disabled = false;
    }
  });

  dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
  closeOnBackdrop(dialog);

  return {
    // phrase — для редагування; defaults — початкові значення для нової фрази
    open(phrase = null, defaults = {}) {
      editingId = phrase?.id ?? null;
      title.textContent = phrase ? `Фраза №${phrase.id}` : 'Новая фраза';
      form.elements.category.value = phrase?.category ?? defaults.category ?? '';
      for (const lang of LANGS) {
        form.elements[`text.${lang}`].value = phrase?.text[lang] ?? '';
        updateCounter(lang);
      }
      form.elements.isActive.checked = phrase?.isActive ?? true;
      showErrors();
      formError.hidden = true;
      dialog.showModal();
    },
  };
}
