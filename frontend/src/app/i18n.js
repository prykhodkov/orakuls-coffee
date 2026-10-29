// Тексти інтерфейсу. TODO: латиську версію має вичитати носій мови.
export const STRINGS = {
  lv: {
    tagline: 'Kafija ar vēstījumu',
    introTitle: 'Tavs vēstījums jau gaida',
    getMessage: 'Saņemt manu vēstījumu',
    revealing: 'Zvaigznes meklē tavu vēstījumu…',
    messageLabel: 'Tavs vēstījums',
    error: 'Neizdevās saņemt vēstījumu. Pamēģini vēlreiz.',
  },
  en: {
    tagline: 'Coffee with a message',
    introTitle: 'Your message is already waiting',
    getMessage: 'Get my message',
    revealing: 'The stars are finding your message…',
    messageLabel: 'Your message',
    error: 'Couldn’t get your message. Please try again.',
  },
  ru: {
    tagline: 'Кофе с посланием',
    introTitle: 'Твоё послание уже ждёт',
    getMessage: 'Получить моё послание',
    revealing: 'Звёзды ищут твоё послание…',
    messageLabel: 'Твоё послание',
    error: 'Не удалось получить послание. Попробуй ещё раз.',
  },
};

export function applyTranslations(lang, root = document) {
  const strings = STRINGS[lang];
  root.querySelectorAll('[data-i18n]').forEach((el) => {
    el.textContent = strings[el.dataset.i18n];
  });
}
