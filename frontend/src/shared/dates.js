// Дати статистики — календарні дні за часом Риги (клієнт у Латвії), формат YYYY-MM-DD.
// Арифметика — через UTC-північ, щоб перехід на літній/зимовий час не зсував дні.

export const STATS_TIMEZONE = 'Europe/Riga';

// Максимальна довжина періоду в днях (див. F-005)
export const MAX_PERIOD_DAYS = 366;

// Поточна дата в Ризі; 'sv-SE' форматує саме як YYYY-MM-DD
export function todayInRiga(now = new Date()) {
  return now.toLocaleDateString('sv-SE', { timeZone: STATS_TIMEZONE });
}

export function isIsoDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value ?? '')) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

export function addDays(isoDate, days) {
  const date = new Date(`${isoDate}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

// Кількість днів у періоді включно з обома кінцями
export function daysBetween(from, to) {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) + 1;
}

// Перший день місяця, зсунутого на offset місяців від дати
export function monthStart(isoDate, offset = 0) {
  const date = new Date(`${isoDate.slice(0, 7)}-01T00:00:00Z`);
  date.setUTCMonth(date.getUTCMonth() + offset);
  return date.toISOString().slice(0, 10);
}

// Усі дати періоду по порядку
export function eachDay(from, to) {
  const days = [];
  for (let day = from; day <= to; day = addDays(day, 1)) days.push(day);
  return days;
}
