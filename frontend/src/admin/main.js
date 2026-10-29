import '../styles/admin.scss';
import { api, ApiError, getToken, setToken } from '../shared/api/index.js';
import { initPhrases } from './phrases.js';
import { errorMessage, logout, onLogout } from './session.js';

const loginView = document.querySelector('[data-login]');
const loginForm = document.querySelector('[data-login-form]');
const loginError = document.querySelector('[data-login-error]');
const appView = document.querySelector('[data-app]');

let phrases = null;

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
  if (phrases) phrases.refresh();
  else phrases = initPhrases();
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
