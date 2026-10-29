// Підписи інтерфейсу адмінки (російською)

export const CATEGORY_LABELS = {
  love: '❤️ Любовь',
  money: '💰 Деньги',
  luck: '🍀 Удача',
  work: '💼 Работа',
  travel: '✈️ Путешествия',
  mood: '☀️ День / Настроение',
};

export const LANG_LABELS = {
  lv: 'Латышский',
  en: 'Английский',
  ru: 'Русский',
};

const FIELD_ERRORS = {
  required: 'Обязательное поле',
  invalid: 'Недопустимое значение',
  too_long: 'Слишком длинный текст (максимум 500 символов)',
  empty: 'Заполните текст хотя бы на одном языке',
};

export function fieldErrorText(code) {
  return FIELD_ERRORS[code] ?? 'Ошибка';
}

// Опис помилок рядка імпорту: { category: 'invalid', 'text.lv': 'too_long' } → текст
export function describeFieldErrors(fields) {
  return Object.entries(fields)
    .map(([field, code]) => {
      if (field === 'category') return code === 'required' ? 'не указана категория' : 'неизвестная категория';
      if (field === 'text') return 'нет текста ни на одном языке';
      const lang = field.split('.')[1]?.toUpperCase();
      return code === 'too_long' ? `текст ${lang} длиннее 500 символов` : `ошибка в тексте ${lang}`;
    })
    .join(', ');
}
