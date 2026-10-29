// Єдина точка входу до API. VITE_API_MOCK підставляється під час збірки,
// тож у продакшн-бандл мок не потрапляє.
import * as mock from './mock/index.js';
import * as real from './real.js';

export const api = import.meta.env.VITE_API_MOCK === 'true' ? mock : real;

export { ApiError } from './http.js';
export { getToken, setToken, clearToken } from './token.js';
