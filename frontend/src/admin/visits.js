// Розділ «Статистика»: відкриття сайту, сканування QR і видані послання за період (F-005)
import { api } from '../shared/api/index.js';
import { LANGS } from '../shared/constants.js';
import { addDays, daysBetween, isIsoDate, MAX_PERIOD_DAYS, monthStart, todayInRiga } from '../shared/dates.js';
import { handleError } from './session.js';
import { escapeHtml } from './ui.js';

const DEFAULT_PRESET = '30';

const view = document.querySelector('[data-view="visits"]');
const presetsEl = view.querySelector('[data-presets]');
const rangeForm = view.querySelector('[data-range-form]');
const rangeError = view.querySelector('[data-range-error]');
const titleEl = view.querySelector('[data-visits-title]');
const tilesEl = view.querySelector('[data-visits-tiles]');
const chartEl = view.querySelector('[data-visits-chart]');
const tableEl = view.querySelector('[data-visits-table]');

const numberFormat = new Intl.NumberFormat('ru-RU');
const fmt = (n) => numberFormat.format(n);
// Дати — календарні дні (YYYY-MM-DD), тож форматуємо в UTC, щоб пояс браузера їх не зсунув
const dayFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const dayLongFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', weekday: 'short', timeZone: 'UTC' });
const titleFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const toDate = (iso) => new Date(`${iso}T00:00:00Z`);
const isWeekend = (iso) => [0, 6].includes(toDate(iso).getUTCDay());

let period = null;
let preset = null;
let requestId = 0;

export function initVisits() {
  presetsEl.addEventListener('click', (event) => {
    const button = event.target.closest('[data-preset]');
    if (button) selectPreset(button.dataset.preset);
  });

  rangeForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const from = rangeForm.elements.from.value;
    const to = rangeForm.elements.to.value;
    const error = validateRange(from, to);
    rangeError.textContent = error;
    rangeError.hidden = !error;
    if (error) return;
    preset = null;
    setPeriod({ from, to });
  });

  selectPreset(DEFAULT_PRESET);
  return { refresh: () => period && load() };
}

function validateRange(from, to) {
  if (!isIsoDate(from) || !isIsoDate(to)) return 'Укажите обе даты.';
  if (from > to) return 'Дата «с» должна быть не позже даты «по».';
  if (daysBetween(from, to) > MAX_PERIOD_DAYS) return `Период — не больше ${MAX_PERIOD_DAYS} дней.`;
  return '';
}

function presetRange(name) {
  const today = todayInRiga();
  switch (name) {
    case 'today': return { from: today, to: today };
    case 'yesterday': return { from: addDays(today, -1), to: addDays(today, -1) };
    case '7': return { from: addDays(today, -6), to: today };
    case '30': return { from: addDays(today, -29), to: today };
    case 'month': return { from: monthStart(today), to: today };
    case 'prev-month': return { from: monthStart(today, -1), to: addDays(monthStart(today), -1) };
    default: return null;
  }
}

function selectPreset(name) {
  preset = name;
  rangeError.hidden = true;
  setPeriod(presetRange(name));
}

function setPeriod(next) {
  period = next;
  rangeForm.elements.from.value = period.from;
  rangeForm.elements.to.value = period.to;
  rangeForm.elements.from.max = rangeForm.elements.to.max = todayInRiga();
  presetsEl.querySelectorAll('[data-preset]').forEach((button) => {
    button.classList.toggle('is-active', button.dataset.preset === preset);
    button.setAttribute('aria-pressed', String(button.dataset.preset === preset));
  });
  load();
}

async function load() {
  const id = ++requestId;
  view.classList.add('is-loading');
  try {
    const data = await api.getVisits(period);
    if (id !== requestId) return;
    render(data);
  } catch (error) {
    if (id === requestId) handleError(error);
  } finally {
    if (id === requestId) view.classList.remove('is-loading');
  }
}

// ---- Відмальовка ----

function periodTitle({ from, to }) {
  if (from === to) return dayLongFormat.format(toDate(from));
  return `${titleFormat.format(toDate(from))} — ${titleFormat.format(toDate(to))}`;
}

const percent = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);

function render(data) {
  const { total, days } = data;
  const dayCount = days.length;

  titleEl.textContent = periodTitle(data);

  const perDay = (value) => (dayCount > 1 ? `<span class="stat__note">в среднем ${fmt(Math.round(value / dayCount))} в день</span>` : '');
  tilesEl.innerHTML = `
    <div class="stat stat--total">
      <span class="stat__label">Сканирования QR</span>
      <span class="stat__value">${fmt(total.scans)}</span>
      ${perDay(total.scans)}
    </div>
    <div class="stat">
      <span class="stat__label">Открытия сайта</span>
      <span class="stat__value">${fmt(total.visits)}</span>
      <span class="stat__note">не с QR: ${fmt(Math.max(0, total.visits - total.scans))}</span>
    </div>
    <div class="stat">
      <span class="stat__label">Выдано посланий</span>
      <span class="stat__value">${fmt(total.messages)}</span>
      ${perDay(total.messages)}
    </div>
    <div class="stat stat--missing">
      <span class="stat__label">Послания по языкам</span>
      <span class="stat__langs">
        ${LANGS.map((lang) => `
          <span class="stat__lang stat__lang--static">
            ${lang.toUpperCase()} <b>${fmt(total.byLang[lang])}</b>
            <small>${percent(total.byLang[lang], total.messages)}%</small>
          </span>
        `).join('')}
      </span>
    </div>
  `;

  renderChart(days);
  renderTable(days, total);
}

function renderChart(days) {
  const max = Math.max(...days.map((d) => d.visits), 0);
  if (!max) {
    chartEl.innerHTML = '<p class="chart__empty">За этот период открытий не было.</p>';
    return;
  }
  // Підписи осі X: не більше ~8, щоб не налазили
  const step = Math.ceil(days.length / 8);
  const top = niceMax(max);

  chartEl.innerHTML = `
    <div class="chart__plot" style="--bars: ${days.length}">
      <div class="chart__grid" aria-hidden="true">
        <span style="bottom: 100%">${fmt(top)}</span>
        <span style="bottom: 50%">${fmt(top / 2)}</span>
        <span style="bottom: 0">0</span>
      </div>
      <div class="chart__bars" role="list">
        ${days.map((day) => `
          <div class="chart__bar ${isWeekend(day.date) ? 'is-weekend' : ''}" role="listitem" tabindex="0"
               data-date="${day.date}"
               aria-label="${escapeHtml(dayLongFormat.format(toDate(day.date)))}: открытий ${day.visits}, сканирований QR ${day.scans}, посланий ${day.messages}">
            <span class="chart__fill" style="height: ${(day.visits / top) * 100}%"></span>
          </div>
        `).join('')}
      </div>
      <div class="chart__axis" aria-hidden="true">
        ${days.map((day, i) => `<span>${showAxisLabel(i, days.length, step) ? escapeHtml(dayFormat.format(toDate(day.date))) : ''}</span>`).join('')}
      </div>
      <div class="chart__tooltip" data-tooltip hidden></div>
    </div>
  `;

  const byDate = new Map(days.map((day) => [day.date, day]));
  const tooltip = chartEl.querySelector('[data-tooltip]');
  const plot = chartEl.querySelector('.chart__plot');

  const showTooltip = (bar) => {
    const day = byDate.get(bar.dataset.date);
    tooltip.innerHTML = `
      <b>${escapeHtml(dayLongFormat.format(toDate(day.date)))}</b>
      <span>Открытий: <b>${fmt(day.visits)}</b></span>
      <span>Сканирований QR: <b>${fmt(day.scans)}</b></span>
      <span>Посланий: <b>${fmt(day.messages)}</b></span>
    `;
    tooltip.hidden = false;
    const plotBox = plot.getBoundingClientRect();
    const barBox = bar.getBoundingClientRect();
    const center = barBox.left + barBox.width / 2 - plotBox.left;
    // Не даємо підказці вилізти за край графіка
    const half = tooltip.offsetWidth / 2;
    tooltip.style.left = `${Math.min(Math.max(center, half), plotBox.width - half)}px`;
    chartEl.querySelectorAll('.chart__bar.is-hover').forEach((el) => el.classList.remove('is-hover'));
    bar.classList.add('is-hover');
  };
  const hideTooltip = () => {
    tooltip.hidden = true;
    chartEl.querySelectorAll('.chart__bar.is-hover').forEach((el) => el.classList.remove('is-hover'));
  };

  const bars = chartEl.querySelector('.chart__bars');
  bars.addEventListener('pointerover', (event) => {
    const bar = event.target.closest('.chart__bar');
    if (bar) showTooltip(bar);
  });
  bars.addEventListener('pointerleave', hideTooltip);
  bars.addEventListener('focusin', (event) => showTooltip(event.target.closest('.chart__bar')));
  bars.addEventListener('focusout', hideTooltip);
}

// Підпис кожного step-го дня; біля правого краю — ні, бо підпис вилізе за графік
function showAxisLabel(index, count, step) {
  return index % step === 0 && (count <= 2 || index < count - 2);
}

// Верх шкали — «кругле» число (1, 2, 5 × 10ⁿ), щоб підписи сітки читались
function niceMax(value) {
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const nice = [1, 2, 5, 10].find((m) => m * magnitude >= value);
  return nice * magnitude;
}

function renderTable(days, total) {
  // Новіші дні зверху — так зручніше дивитися «що було вчора»
  const rows = [...days].reverse();
  tableEl.innerHTML = `
    <table class="data-table">
      <thead>
        <tr>
          <th>Дата</th>
          <th class="num">Сканирования QR</th>
          <th class="num">Открытия сайта</th>
          <th class="num">Послания</th>
          ${LANGS.map((lang) => `<th class="num">${lang.toUpperCase()}</th>`).join('')}
        </tr>
      </thead>
      <tbody>
        ${rows.map((day) => `
          <tr class="${isWeekend(day.date) ? 'is-weekend' : ''}">
            <td>${escapeHtml(dayLongFormat.format(toDate(day.date)))}</td>
            <td class="num">${fmt(day.scans)}</td>
            <td class="num">${fmt(day.visits)}</td>
            <td class="num">${fmt(day.messages)}</td>
            ${LANGS.map((lang) => `<td class="num">${fmt(day.byLang[lang])}</td>`).join('')}
          </tr>
        `).join('')}
      </tbody>
      ${days.length > 1 ? `
        <tfoot>
          <tr>
            <td>Итого</td>
            <td class="num">${fmt(total.scans)}</td>
            <td class="num">${fmt(total.visits)}</td>
            <td class="num">${fmt(total.messages)}</td>
            ${LANGS.map((lang) => `<td class="num">${fmt(total.byLang[lang])}</td>`).join('')}
          </tr>
        </tfoot>
      ` : ''}
    </table>
  `;
}
