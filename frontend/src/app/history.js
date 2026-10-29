// Усе зберігається лише в браузері користувача (localStorage)

// ---- Показані фрази ----
// ID останніх показаних фраз — передаємо в exclude, щоб послання не повторювались
const SEEN_KEY = 'orakuls-seen';
const SEEN_LIMIT = 100;

export function getSeen() {
  const ids = read(SEEN_KEY, []);
  return Array.isArray(ids) ? ids : [];
}

export function rememberSeen(id) {
  const ids = [id, ...getSeen().filter((x) => x !== id)].slice(0, SEEN_LIMIT);
  write(SEEN_KEY, ids);
}

// ---- Поточне послання ----
// Протягом RECENT_WINDOW_MS повторний скан показує те саме послання (одна кава — одне послання)
const RECENT_KEY = 'orakuls-recent';
const RECENT_WINDOW_MS = 20 * 60 * 1000;

export function getRecent(lang) {
  const recent = read(RECENT_KEY, null);
  if (!recent?.message || recent.message.lang !== lang) return null;
  if (Date.now() - recent.at > RECENT_WINDOW_MS) return null;
  return recent.message;
}

export function saveRecent(message) {
  write(RECENT_KEY, { message, at: Date.now() });
}

function read(key, fallback) {
  try {
    const value = JSON.parse(localStorage.getItem(key));
    return value ?? fallback;
  } catch {
    return fallback;
  }
}

function write(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // приватний режим — працюємо без пам'яті
  }
}
