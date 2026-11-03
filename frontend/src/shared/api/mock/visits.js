// Мок-лічильники відвідувань (F-005) у localStorage: по днях за часом Риги.
// Щоб у dev і демо було що показати, при першому запуску генерується історія за HISTORY_DAYS днів
// (вигадані числа, у продакшн не потрапляють).
import { LANGS } from '../../constants.js';
import { addDays, todayInRiga } from '../../dates.js';

const KEY = 'orakuls-mock-visits-v1';
const HISTORY_DAYS = 90;

let memory = null;

export const emptyDay = () => ({
  visits: 0,
  scans: 0,
  messages: 0,
  byLang: Object.fromEntries(LANGS.map((lang) => [lang, 0])),
});

// Детермінований генератор, щоб історія була однаковою при кожному скиданні
function random(seed) {
  let state = seed;
  return () => {
    state = (state * 1664525 + 1013904223) % 4294967296;
    return state / 4294967296;
  };
}

function generateHistory() {
  const next = random(42);
  const today = todayInRiga();
  const days = {};
  for (let i = HISTORY_DAYS; i >= 1; i--) {
    const date = addDays(today, -i);
    const weekday = new Date(`${date}T00:00:00Z`).getUTCDay();
    const weekend = weekday === 0 || weekday === 6;
    // Кав'ярня: більше гостей у вихідні, трохи росте з часом
    const base = (weekend ? 55 : 35) + (HISTORY_DAYS - i) * 0.2;
    const visits = Math.round(base * (0.7 + next() * 0.6));
    const scans = Math.round(visits * (0.8 + next() * 0.15));
    const messages = Math.round(visits * (0.75 + next() * 0.15));
    const lv = Math.round(messages * (0.45 + next() * 0.1));
    const en = Math.round((messages - lv) * (0.3 + next() * 0.2));
    days[date] = { visits, scans, messages, byLang: { lv, en, ru: messages - lv - en } };
  }
  return { days };
}

function load() {
  let db = null;
  try {
    db = JSON.parse(localStorage.getItem(KEY));
  } catch {
    db = memory;
  }
  if (!db?.days) {
    db = generateHistory();
    save(db);
  }
  return db;
}

function save(db) {
  memory = db;
  try {
    localStorage.setItem(KEY, JSON.stringify(db));
  } catch {
    // ignore
  }
}

function bump(update) {
  const db = load();
  const date = todayInRiga();
  db.days[date] ??= emptyDay();
  update(db.days[date]);
  save(db);
}

export function countVisit(source) {
  bump((day) => {
    day.visits++;
    if (source === 'qr') day.scans++;
  });
}

export function countMessage(lang) {
  bump((day) => {
    day.messages++;
    day.byLang[lang]++;
  });
}

export function getDay(date) {
  return load().days[date] ?? emptyDay();
}
