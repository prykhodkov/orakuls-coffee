import { CATEGORIES, LANGS, TEXT_MAX_LENGTH } from './constants.js';

// Правила з docs/API.md → «Сутність Phrase». Повертає { value, fields }:
// value — нормалізована фраза, fields — помилки (null, якщо все гаразд).
export function validatePhrase(input) {
  const fields = {};

  const category = input?.category;
  if (!category) fields.category = 'required';
  else if (!CATEGORIES.includes(category)) fields.category = 'invalid';

  const text = {};
  for (const lang of LANGS) {
    const raw = input?.text?.[lang] ?? '';
    if (typeof raw !== 'string') {
      fields[`text.${lang}`] = 'invalid';
      continue;
    }
    text[lang] = raw.trim();
    if (text[lang].length > TEXT_MAX_LENGTH) fields[`text.${lang}`] = 'too_long';
  }
  if (LANGS.every((lang) => !text[lang])) fields.text = 'empty';

  const isActive = input?.isActive ?? true;
  if (typeof isActive !== 'boolean') fields.isActive = 'invalid';

  return {
    value: { category, text, isActive },
    fields: Object.keys(fields).length ? fields : null,
  };
}
