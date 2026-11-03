// Розбір файлу CSV / Excel у фрази. Формат — docs/API.md → «Масовий імпорт».
import { CATEGORIES, LANGS } from '../shared/constants.js';
import { validatePhrase } from '../shared/validation.js';
import { CATEGORY_LABELS } from './labels.js';

export class SheetError extends Error {}

// Лишаємо тільки літери: «❤️ Любовь» → «любовь», «День / Настроение» → «деньнастроение»
const normalize = (value) => String(value ?? '').toLowerCase().replace(/[^\p{L}]+/gu, '');

const CATEGORY_ALIASES = {
  love: ['love', 'любовь', 'mīlestība'],
  money: ['money', 'деньги', 'nauda'],
  luck: ['luck', 'удача', 'veiksme'],
  work: ['work', 'работа', 'darbs'],
  travel: ['travel', 'путешествия', 'ceļojumi'],
  mood: ['mood', 'день', 'настроение', 'day', 'day / mood', 'diena', 'noskaņojums', 'diena / noskaņojums'],
};

const CATEGORY_BY_ALIAS = new Map(
  CATEGORIES.flatMap((category) =>
    [...CATEGORY_ALIASES[category], CATEGORY_LABELS[category]].map((alias) => [normalize(alias), category]),
  ),
);

const COLUMN_ALIASES = {
  category: ['category', 'категория', 'kategorija'],
  lv: ['lv', 'latviešu', 'латышский', 'latvian'],
  en: ['en', 'english', 'английский', 'angļu'],
  ru: ['ru', 'русский', 'krievu', 'russian'],
};

function resolveCategory(value) {
  return CATEGORY_BY_ALIAS.get(normalize(value)) ?? String(value ?? '').trim();
}

function decodeUtf8(buffer) {
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(buffer);
  } catch {
    throw new SheetError(
      'Файл не в кодировке UTF-8. В Excel сохраните его как «CSV UTF-8 (разделитель — запятая)» или загрузите сам файл .xlsx.',
    );
  }
}

// Роздільник визначаємо за першим рядком: Excel у різних локалях зберігає CSV з «,» або «;»
function detectDelimiter(text) {
  const firstLine = text.split(/\r?\n/, 1)[0];
  const counts = [',', ';', '\t'].map((d) => [d, firstLine.split(d).length - 1]);
  counts.sort((a, b) => b[1] - a[1]);
  return counts[0][1] > 0 ? counts[0][0] : ',';
}

async function readWorkbook(file) {
  const XLSX = await import('xlsx');
  const buffer = await file.arrayBuffer();
  const isCsv = /\.csv$/i.test(file.name) || file.type === 'text/csv';

  let workbook;
  if (isCsv) {
    const text = decodeUtf8(buffer);
    workbook = XLSX.read(text, { type: 'string', FS: detectDelimiter(text), raw: true });
  } else {
    try {
      workbook = XLSX.read(buffer, { type: 'array' });
    } catch {
      throw new SheetError('Не удалось прочитать файл. Убедитесь, что это файл Excel (.xlsx, .xls) или CSV.');
    }
  }

  const sheet = workbook.Sheets[workbook.SheetNames[0]];
  if (!sheet?.['!ref']) throw new SheetError('Файл пустой.');

  const firstRow = XLSX.utils.decode_range(sheet['!ref']).s.r;
  const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', raw: false, blankrows: true });
  return { rows, firstRow };
}

function findColumns(row) {
  const header = row.map(normalize);
  return Object.fromEntries(
    Object.entries(COLUMN_ALIASES).map(([key, aliases]) => [
      key,
      header.findIndex((cell) => aliases.some((alias) => normalize(alias) === cell)),
    ]),
  );
}

const isBlank = (row) => !row.some((value) => String(value).trim());

// Підтримуються два формати:
// 1) таблиця зі стовпцем category: «category | lv | en | ru»;
// 2) розділи без стовпця category (так прийшов файл від клієнта): рядок-назва
//    «❤️ ЛЮБОВЬ — 100», під ним заголовки «№ | Русский | Latviešu | English» і фрази;
//    категорія береться з назви розділу, повторні рядки заголовків пропускаються.
// Повертає { rows: [{ rowNumber, item, fields }], missingColumns }, де rowNumber — номер рядка
// як в Excel, fields — помилки валідації (null, якщо рядок валідний)
export async function parseSheetFile(file) {
  const { rows, firstRow } = await readWorkbook(file);
  if (rows.every(isBlank)) throw new SheetError('Файл пустой.');

  // Рядок заголовків — перший, де знайдено хоча б один стовпець мови
  const langCount = (row) => LANGS.filter((lang) => findColumns(row)[lang] !== -1).length;
  const headerIndex = rows.findIndex((row) => langCount(row) > 0);
  if (headerIndex === -1) {
    throw new SheetError(
      'Не найдена строка с заголовками столбцов. Нужны столбцы lv, en, ru (или Latviešu, English, Русский) и category — либо столбцом, либо названиями разделов.',
    );
  }

  const columns = findColumns(rows[headerIndex]);
  const headerKey = rows[headerIndex].map(normalize).join('|');
  const missingColumns = LANGS.filter((lang) => columns[lang] === -1);
  const hasCategoryColumn = columns.category !== -1;

  const cell = (row, key) => (columns[key] === -1 ? '' : String(row[columns[key]] ?? ''));
  const langCellsEmpty = (row) => LANGS.every((lang) => !cell(row, lang).trim());
  const filledCells = (row) => row.filter((value) => String(value).trim());

  // Назва розділу: єдина заповнена клітинка в рядку, переклади порожні
  const sectionTitle = (row) => {
    if (hasCategoryColumn || !langCellsEmpty(row)) return null;
    const filled = filledCells(row);
    return filled.length === 1 ? String(filled[0]) : null;
  };

  // Назва розділу може стояти й над першим рядком заголовків
  let section = '';
  for (const row of rows.slice(0, headerIndex)) {
    const title = sectionTitle(row);
    if (title !== null) section = resolveCategory(title);
  }

  const result = [];
  rows.slice(headerIndex + 1).forEach((row, i) => {
    if (isBlank(row)) return;
    if (row.map(normalize).join('|') === headerKey) return;
    const title = sectionTitle(row);
    if (title !== null) {
      section = resolveCategory(title);
      return;
    }

    const input = {
      category: hasCategoryColumn ? resolveCategory(cell(row, 'category')) : section,
      text: Object.fromEntries(LANGS.map((lang) => [lang, cell(row, lang)])),
    };
    const { value, fields } = validatePhrase(input);
    result.push({
      rowNumber: firstRow + headerIndex + i + 2,
      item: { category: value.category, text: value.text },
      fields,
    });
  });

  if (!result.length) throw new SheetError('В файле нет ни одной фразы — только заголовки.');
  return { rows: result, missingColumns };
}
