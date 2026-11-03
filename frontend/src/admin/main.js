import '../styles/admin.scss';
import { api, ApiError, getToken, setToken } from '../shared/api/index.js';
import { initPhrases } from './phrases.js';
import { errorMessage, logout, onLogout } from './session.js';
import { initVisits } from './visits.js';

const loginView = document.querySelector('[data-login]');
const loginForm = document.querySelector('[data-login-form]');
const loginError = document.querySelector('[data-login-error]');
const appView = document.querySelector('[data-app]');

const TABS = ['phrases', 'visits'];

let phrases = null;
let visits = null;

// Вкладка — у хеші адреси (#visits), щоб оновлення сторінки її не скидало
const currentTab = () => (TABS.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'phrases');

function showTab() {
  const tab = currentTab();
  document.querySelectorAll('[data-view]').forEach((view) => {
    view.hidden = view.dataset.view !== tab;
  });
  document.querySelectorAll('[data-tab]').forEach((link) => {
    if (link.dataset.tab === tab) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });

  // Розділи завантажуються, лише коли їх відкрили
  if (tab === 'phrases') {
    if (phrases) phrases.refresh();
    else phrases = initPhrases();
  } else if (visits) {
    visits.refresh();
  } else {
    visits = initVisits();
  }
}

window.addEventListener('hashchange', () => {
  if (!appView.hidden) showTab();
});

function showLogin(message = '') {
  appView.hidden = true;
  loginView.hidden = false;
  loginForm.reset();
  loginError.textContent = message;
  loginError.hidden = !message;
  loginForm.elements.login.focus();
}

function showApp() {
  loginView.hidden = true;
  appView.hidden = false;
  showTab();
}

loginForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const login = loginForm.elements.login.value.trim();
  const password = loginForm.elements.password.value;
  if (!login || !password) {
    loginError.textContent = 'Введите логин и пароль';
    loginError.hidden = false;
    return;
  }

  const submit = loginForm.querySelector('[type="submit"]');
  submit.disabled = true;
  loginError.hidden = true;
  try {
    const { token } = await api.login({ login, password });
    setToken(token);
    showApp();
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) loginError.textContent = 'Неверный логин или пароль';
    else if (error instanceof ApiError && error.status === 429) loginError.textContent = 'Слишком много попыток. Подождите немного и попробуйте снова.';
    else loginError.textContent = errorMessage(error);
    loginError.hidden = false;
  } finally {
    submit.disabled = false;
  }
});

document.querySelector('[data-logout]').addEventListener('click', logout);
onLogout(() => showLogin());

// Старт: якщо токен є — перевіряємо, що він ще дійсний
async function start() {
  if (!getToken()) {
    showLogin();
    return;
  }
  try {
    await api.me();
    showApp();
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) logout();
    else showLogin(errorMessage(error));
  }
}

start();
