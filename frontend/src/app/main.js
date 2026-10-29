import '../styles/app.scss';
import { api } from '../shared/api/index.js';
import { getRecent, getSeen, rememberSeen, saveRecent } from './history.js';
import { applyTranslations } from './i18n.js';
import { startSky } from './sky.js';

const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
// Мінімальний час «пошуку» послання — щоб анімація встигла відіграти
const REVEAL_MIN_MS = reducedMotion ? 600 : 2600;

const screens = document.querySelectorAll('[data-screen]');
const messageText = document.querySelector('[data-message-text]');
const errorText = document.querySelector('[data-error]');

let lang = null;
let busy = false;

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

document.querySelectorAll('[data-lang]').forEach((button) => {
  button.addEventListener('click', () => selectLang(button.dataset.lang));
});
document.querySelectorAll('[data-action="reveal"]').forEach((button) => {
  button.addEventListener('click', revealMessage);
});

startSky(document.querySelector('.sky'));
show('lang');
