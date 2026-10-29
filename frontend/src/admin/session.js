import { ApiError, clearToken } from '../shared/api/index.js';
import { toast } from './ui.js';

let logoutHandler = () => {};

export function onLogout(handler) {
  logoutHandler = handler;
}

export function logout() {
  clearToken();
  logoutHandler();
}

export function errorMessage(error) {
  if (error instanceof ApiError && error.code === 'network_error') {
    return 'Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.';
  }
  return 'Что-то пошло не так. Попробуйте ещё раз.';
}

// Загальна обробка помилок API: 401 → на форму входу, решта → тост
export function handleError(error) {
  if (error instanceof ApiError && error.status === 401) {
    toast('Сессия истекла — войдите снова', 'error');
    logout();
    return;
  }
  console.error(error);
  toast(errorMessage(error), 'error');
}
