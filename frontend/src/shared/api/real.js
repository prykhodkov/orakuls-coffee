// Реалізація контракту docs/API.md поверх fetch
import { request } from './http.js';

// ---- Публічна частина ----

export const getMessage = ({ lang, exclude = [] }) =>
  request('/message', { query: { lang, exclude: exclude.join(',') } });

// ---- Адмінка ----

export const login = ({ login, password }) =>
  request('/admin/auth/login', { method: 'POST', body: { login, password } });

export const me = () => request('/admin/auth/me', { auth: true });

export const listPhrases = (params = {}) =>
  request('/admin/phrases', { query: params, auth: true });

export const getPhrase = (id) => request(`/admin/phrases/${id}`, { auth: true });

export const createPhrase = (phrase) =>
  request('/admin/phrases', { method: 'POST', body: phrase, auth: true });

export const updatePhrase = (id, phrase) =>
  request(`/admin/phrases/${id}`, { method: 'PUT', body: phrase, auth: true });

export const deletePhrase = (id) =>
  request(`/admin/phrases/${id}`, { method: 'DELETE', auth: true });

export const importPhrases = (items) =>
  request('/admin/phrases/import', { method: 'POST', body: { items }, auth: true });

export const getStats = () => request('/admin/stats', { auth: true });
