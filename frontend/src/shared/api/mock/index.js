// Мок-реалізація контракту docs/API.md — ті самі функції, що й у real.js.
// Логін в адмінку: admin / admin
import { CATEGORIES, LANGS } from '../../constants.js';
import { validatePhrase } from '../../validation.js';
import { ApiError } from '../http.js';
import { getToken } from '../token.js';
import { load, save } from './store.js';

const MOCK_LOGIN = 'admin';
const MOCK_PASSWORD = 'admin';
const MOCK_TOKEN = 'mock-token';

const delay = (ms = 250) => new Promise((resolve) => setTimeout(resolve, ms));

function requireAuth() {
  if (getToken() !== MOCK_TOKEN) {
    throw new ApiError(401, { code: 'unauthorized', message: 'Unauthorized' });
  }
}

function findOr404(db, id) {
  const phrase = db.phrases.find((p) => p.id === Number(id));
  if (!phrase) throw new ApiError(404, { code: 'not_found', message: 'Phrase not found' });
  return phrase;
}

function validateOr422(input) {
  const { value, fields } = validatePhrase(input);
  if (fields) throw new ApiError(422, { code: 'validation_error', message: 'Validation failed', fields });
  return value;
}

// ---- Публічна частина ----

export async function getMessage({ lang, exclude = [] }) {
  await delay();
  if (!LANGS.includes(lang)) throw new ApiError(400, { code: 'bad_request', message: 'Unknown lang' });

  const available = load().phrases.filter((p) => p.isActive && p.text[lang]);
  const fresh = available.filter((p) => !exclude.includes(p.id));
  const pool = fresh.length ? fresh : available;
  if (!pool.length) throw new ApiError(404, { code: 'not_found', message: 'No phrases' });

  const phrase = pool[Math.floor(Math.random() * pool.length)];
  return { id: phrase.id, lang, text: phrase.text[lang] };
}

// ---- Адмінка ----

export async function login({ login, password }) {
  await delay();
  if (login !== MOCK_LOGIN || password !== MOCK_PASSWORD) {
    throw new ApiError(401, { code: 'unauthorized', message: 'Invalid credentials' });
  }
  const expiresAt = new Date(Date.now() + 24 * 3600 * 1000).toISOString();
  return { token: MOCK_TOKEN, expiresAt };
}

export async function me() {
  await delay(100);
  requireAuth();
  return { login: MOCK_LOGIN };
}

export async function listPhrases({ category, search, missing, isActive, page = 1, perPage = 50 } = {}) {
  await delay();
  requireAuth();

  const query = search?.trim().toLowerCase();
  const items = load().phrases
    .filter((p) => !category || p.category === category)
    .filter((p) => !missing || !p.text[missing])
    .filter((p) => isActive === undefined || p.isActive === (String(isActive) === 'true'))
    .filter((p) => !query || LANGS.some((lang) => p.text[lang].toLowerCase().includes(query)))
    .sort((a, b) => b.id - a.id);

  page = Math.max(1, Number(page));
  perPage = Math.min(100, Math.max(1, Number(perPage)));
  return {
    items: items.slice((page - 1) * perPage, page * perPage),
    total: items.length,
    page,
    perPage,
  };
}

export async function getPhrase(id) {
  await delay();
  requireAuth();
  return findOr404(load(), id);
}

export async function createPhrase(input) {
  await delay();
  requireAuth();
  const value = validateOr422(input);
  const db = load();
  const now = new Date().toISOString();
  const phrase = { id: db.nextId++, ...value, createdAt: now, updatedAt: now };
  db.phrases.push(phrase);
  save(db);
  return phrase;
}

export async function updatePhrase(id, input) {
  await delay();
  requireAuth();
  const db = load();
  const phrase = findOr404(db, id);
  Object.assign(phrase, validateOr422(input), { updatedAt: new Date().toISOString() });
  save(db);
  return phrase;
}

export async function deletePhrase(id) {
  await delay();
  requireAuth();
  const db = load();
  const phrase = findOr404(db, id);
  db.phrases = db.phrases.filter((p) => p !== phrase);
  save(db);
  return null;
}

export async function importPhrases(items) {
  await delay(600);
  requireAuth();
  if (!Array.isArray(items) || items.length > 1000) {
    throw new ApiError(400, { code: 'bad_request', message: 'items must be an array of up to 1000 elements' });
  }

  const db = load();
  const now = new Date().toISOString();
  const errors = [];
  let created = 0;

  items.forEach((item, index) => {
    const { value, fields } = validatePhrase(item);
    if (fields) {
      errors.push({ index, fields });
      return;
    }
    db.phrases.push({ id: db.nextId++, ...value, isActive: true, createdAt: now, updatedAt: now });
    created++;
  });

  save(db);
  return { created, errors };
}

export async function getStats() {
  await delay();
  requireAuth();
  const { phrases } = load();
  return {
    total: phrases.length,
    active: phrases.filter((p) => p.isActive).length,
    byCategory: Object.fromEntries(
      CATEGORIES.map((c) => [c, phrases.filter((p) => p.category === c).length]),
    ),
    missing: Object.fromEntries(LANGS.map((lang) => [lang, phrases.filter((p) => !p.text[lang]).length])),
  };
}
