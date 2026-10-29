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

// Повертає { rows: [{ rowNumber, item, fields }] }, де rowNumber — номер рядка як в Excel,
// fields — помилки валідації (null, якщо рядок валідний)
export async function parseSheetFile(file) {
  const { rows, firstRow } = await readWorkbook(file);

  const headerIndex = rows.findIndex((row) => row.some((cell) => String(cell).trim()));
  if (headerIndex === -1) throw new SheetError('Файл пустой.');

  const header = rows[headerIndex].map(normalize);
  const columns = {};
  for (const [key, aliases] of Object.entries(COLUMN_ALIASES)) {
    columns[key] = header.findIndex((cell) => aliases.some((alias) => normalize(alias) === cell));
  }
  const missingColumns = Object.keys(columns).filter((key) => columns[key] === -1);
  if (missingColumns.includes('category') || missingColumns.length === LANGS.length) {
    throw new SheetError(
      `В первой строке не найдены заголовки: ${missingColumns.join(', ')}. Нужны столбцы category, lv, en, ru.`,
    );
  }

  const cell = (row, key) => (columns[key] === -1 ? '' : String(row[columns[key]] ?? ''));

  const result = [];
  rows.slice(headerIndex + 1).forEach((row, i) => {
    if (!row.some((value) => String(value).trim())) return;
    const input = {
      category: resolveCategory(cell(row, 'category')),
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
