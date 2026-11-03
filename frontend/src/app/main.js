import '../styles/app.scss';
import { api } from '../shared/api/index.js';
import { getRecent, getSeen, rememberSeen, saveRecent } from './history.js';
import { applyTranslations, STRINGS } from './i18n.js';
import { shareMessage } from './share.js';
import { startSky } from './sky.js';

const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
// Мінімальний час «пошуку» послання — щоб анімація встигла відіграти
const REVEAL_MIN_MS = reducedMotion ? 600 : 2600;

const screens = document.querySelectorAll('[data-screen]');
const messageText = document.querySelector('[data-message-text]');
const errorText = document.querySelector('[data-error]');
const shareLabel = document.querySelector('[data-share-label]');

// Скільки показувати «Скопировано» на кнопці
const COPIED_MS = 2000;

let lang = null;
let busy = false;
let currentText = '';
let copiedTimer = null;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function show(name) {
  screens.forEach((screen) => {
    const active = screen.dataset.screen === name;
    screen.classList.toggle('is-active', active);
    screen.inert = !active;
  });
}

function selectLang(code) {
  lang = code;
  document.documentElement.lang = code;
  applyTranslations(code);
  errorText.hidden = true;
  show('intro');
}

async function revealMessage() {
  if (busy) return;
  busy = true;
  errorText.hidden = true;
  show('reveal');

  try {
    // Повторний скан того ж стаканчика протягом 20 хв показує те саме послання
    const recent = getRecent(lang);
    const [message] = await Promise.all([
      recent ?? api.getMessage({ lang, exclude: getSeen() }),
      sleep(REVEAL_MIN_MS),
    ]);
    if (!recent) {
      rememberSeen(message.id);
      saveRecent(message);
    }
    currentText = message.text;
    messageText.textContent = message.text;
    messageText.classList.toggle('message__text--long', message.text.length > 160);
    show('message');
  } catch {
    errorText.hidden = false;
    show('intro');
  } finally {
    busy = false;
  }
}

async function share() {
  if (!currentText) return;
  const result = await shareMessage(currentText, lang);
  if (result !== 'copied') return;
  // На комп'ютері тексту в системному меню немає — показуємо, що його скопійовано
  shareLabel.textContent = STRINGS[lang].copied;
  clearTimeout(copiedTimer);
  copiedTimer = setTimeout(() => {
    shareLabel.textContent = STRINGS[lang].share;
  }, COPIED_MS);
}

document.querySelectorAll('[data-lang]').forEach((button) => {
  button.addEventListener('click', () => selectLang(button.dataset.lang));
});
document.querySelectorAll('[data-action="reveal"]').forEach((button) => {
  button.addEventListener('click', revealMessage);
});
document.querySelector('[data-action="share"]').addEventListener('click', share);

// Лічильник відкриттів (F-005). QR на стаканчиках веде на адресу з ?qr — так відрізняємо сканування
// від переходів за посиланням, яким поділились. Параметр прибираємо з адреси, щоб він не потрапив у закладки.
function trackVisit() {
  const params = new URLSearchParams(location.search);
  const source = params.has('qr') ? 'qr' : 'direct';
  if (source === 'qr') {
    params.delete('qr');
    const query = params.toString();
    history.replaceState(null, '', location.pathname + (query ? `?${query}` : '') + location.hash);
  }
  // Статистика не має заважати посланню: помилки ігноруємо
  api.trackVisit({ source }).catch(() => {});
}

trackVisit();
startSky(document.querySelector('.sky'));
show('lang');
