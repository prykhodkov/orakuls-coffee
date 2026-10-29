// Сховище мок-бази в localStorage. Спільне для сторінки користувача й адмінки
// (один origin), тож зміни в адмінці видно на сайті без перезавантаження.
import { SEED } from './seed.js';

const KEY = 'orakuls-mock-db-v1';

// Якщо localStorage недоступний — база живе в пам'яті
let memoryDb = null;

function seed() {
  const now = new Date().toISOString();
  return {
    nextId: SEED.length + 1,
    phrases: SEED.map(([category, text], i) => ({
      id: i + 1,
      category,
      text,
      isActive: true,
      createdAt: now,
      updatedAt: now,
    })),
  };
}

// Щоразу читаємо свіжу копію: адмінка й сайт можуть бути відкриті в різних вкладках
export function load() {
  let db = null;
  try {
    db = JSON.parse(localStorage.getItem(KEY));
  } catch {
    db = memoryDb;
  }
  if (!db?.phrases) {
    db = seed();
    save(db);
  }
  return db;
}

export function save(db) {
  memoryDb = db;
  try {
    localStorage.setItem(KEY, JSON.stringify(db));
  } catch {
    // ignore
  }
}
