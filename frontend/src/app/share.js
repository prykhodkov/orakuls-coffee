// Кнопка «Поділитися»: текст послання + посилання на сайт.
// На телефоні — системне меню (Web Share API), де його немає (комп'ютер) — копіювання в буфер.

// Лапки за типографікою кожної мови
const QUOTES = {
  lv: ['„', '”'],
  en: ['“', '”'],
  ru: ['«', '»'],
};

// Адреса сайту: VITE_SITE_URL, інакше поточний origin. У демо з диска (file://) origin — "null",
// тоді посилання не додаємо
function siteUrl() {
  const url = (import.meta.env.VITE_SITE_URL || location.origin).replace(/\/$/, '');
  return /^https?:\/\//.test(url) ? url : '';
}

export function buildShareText(text, lang) {
  const [open, close] = QUOTES[lang] ?? QUOTES.en;
  // Посилання — всередині тексту, а не окремим полем url: частина застосунків
  // при наявності url відкидає text і надсилає лише посилання
  return [`${open}${text}${close}`, '— Orākuls Coffee', siteUrl()].filter(Boolean).join('\n');
}

// Повертає 'shared' | 'copied' | 'cancelled' | 'failed'
export async function shareMessage(text, lang) {
  const shareText = buildShareText(text, lang);

  if (navigator.share) {
    try {
      await navigator.share({ text: shareText });
      return 'shared';
    } catch (error) {
      if (error?.name === 'AbortError') return 'cancelled';
      // інша помилка (напр. заборонено в цьому контексті) — пробуємо копіювання
    }
  }

  return (await copyText(shareText)) ? 'copied' : 'failed';
}

async function copyText(value) {
  try {
    await navigator.clipboard.writeText(value);
    return true;
  } catch {
    // Clipboard API недоступний (http, старий браузер) — старий спосіб
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.append(textarea);
    textarea.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch {
      ok = false;
    }
    textarea.remove();
    return ok;
  }
}
