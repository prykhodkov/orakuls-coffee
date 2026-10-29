const KEY = 'orakuls-admin-token';

// Запасне сховище в пам'яті — якщо localStorage недоступний (приватний режим тощо)
let memoryToken = null;

export function getToken() {
  try {
    return localStorage.getItem(KEY) ?? memoryToken;
  } catch {
    return memoryToken;
  }
}

export function setToken(token) {
  memoryToken = token;
  try {
    localStorage.setItem(KEY, token);
  } catch {
    // лишається лише в пам'яті до перезавантаження сторінки
  }
}

export function clearToken() {
  memoryToken = null;
  try {
    localStorage.removeItem(KEY);
  } catch {
    // ignore
  }
}
