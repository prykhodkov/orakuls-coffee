// Головний екран адмінки: зведення, фільтри, список фраз, пагінація
import { api } from '../shared/api/index.js';
import { CATEGORIES, LANGS } from '../shared/constants.js';
import { initImportDialog } from './import.js';
import { CATEGORY_LABELS } from './labels.js';
import { initPhraseDialog } from './phrase-dialog.js';
import { handleError } from './session.js';
import { confirmAction, escapeHtml, pluralRu, toast } from './ui.js';

const PER_PAGE = 50;

const statsEl = document.querySelector('[data-stats]');
const listEl = document.querySelector('[data-list]');
const metaEl = document.querySelector('[data-list-meta]');
const paginationEl = document.querySelector('[data-pagination]');
const categoryFilter = document.querySelector('[data-filter="category"]');
const missingFilter = document.querySelector('[data-filter="missing"]');
const searchFilter = document.querySelector('[data-filter="search"]');

const state = { category: '', missing: '', search: '', page: 1 };
let items = new Map();
// Номер останнього запиту списку — відповіді на застарілі запити ігноруємо
let listRequestId = 0;

export function initPhrases() {
  for (const category of CATEGORIES) {
    categoryFilter.add(new Option(CATEGORY_LABELS[category], category));
  }

  const phraseDialog = initPhraseDialog({ onSaved: refresh });
  const importDialog = initImportDialog({ onImported: refresh });

  categoryFilter.addEventListener('change', () => setFilter({ category: categoryFilter.value }));
  missingFilter.addEventListener('change', () => setFilter({ missing: missingFilter.value }));

  let searchTimer;
  searchFilter.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => setFilter({ search: searchFilter.value.trim() }), 300);
  });

  statsEl.addEventListener('click', (event) => {
    // «Всего фраз» — скидає всі фільтри, включно з пошуком
    if (event.target.closest('[data-reset-filters]')) {
      clearTimeout(searchTimer);
      searchFilter.value = '';
      setFilter({ category: '', missing: '', search: '' });
      return;
    }

    const button = event.target.closest('[data-stat-filter]');
    if (!button) return;
    const { statFilter, value } = button.dataset;
    // Повторний клік по активному фільтру знімає його
    const next = state[statFilter] === value ? '' : value;
    setFilter(statFilter === 'category' ? { category: next, missing: '' } : { missing: next, category: '' });
  });

  document.querySelector('[data-create]').addEventListener('click', () => {
    phraseDialog.open(null, { category: state.category });
  });
  document.querySelector('[data-open-import]').addEventListener('click', () => importDialog.open());

  listEl.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-edit]');
    if (editButton) {
      phraseDialog.open(items.get(Number(editButton.dataset.edit)));
      return;
    }
    const deleteButton = event.target.closest('[data-delete]');
    if (deleteButton) deletePhrase(Number(deleteButton.dataset.delete));
  });

  paginationEl.addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    state.page = Number(button.dataset.page);
    loadList();
    listEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  refresh();
  return { refresh };
}

function setFilter(patch) {
  Object.assign(state, patch, { page: 1 });
  categoryFilter.value = state.category;
  missingFilter.value = state.missing;
  renderStatsActive();
  loadList();
}

function refresh() {
  loadStats();
  loadList();
}

// ---- Зведення ----

let lastStats = null;

async function loadStats() {
  try {
    lastStats = await api.getStats();
    renderStats();
  } catch (error) {
    handleError(error);
  }
}

function renderStats() {
  if (!lastStats) return;
  const { total, active, byCategory, missing } = lastStats;
  const hidden = total - active;

  statsEl.innerHTML = `
    <button class="stat stat--total" type="button" data-reset-filters title="Показать все фразы">
      <span class="stat__label">Всего фраз</span>
      <span class="stat__value">${total}</span>
      ${hidden ? `<span class="stat__note">скрыто: ${hidden}</span>` : ''}
    </button>
    ${CATEGORIES.map((category) => `
      <button class="stat" type="button" data-stat-filter="category" data-value="${category}">
        <span class="stat__label">${CATEGORY_LABELS[category]}</span>
        <span class="stat__value">${byCategory[category] ?? 0}</span>
      </button>
    `).join('')}
    <div class="stat stat--missing">
      <span class="stat__label">Нет перевода</span>
      <span class="stat__langs">
        ${LANGS.map((lang) => `
          <button class="stat__lang ${missing[lang] ? 'has-missing' : ''}" type="button"
                  data-stat-filter="missing" data-value="${lang}">
            ${lang.toUpperCase()} <b>${missing[lang]}</b>
          </button>
        `).join('')}
      </span>
    </div>
  `;
  renderStatsActive();
}

function renderStatsActive() {
  statsEl.querySelectorAll('[data-stat-filter]').forEach((button) => {
    const active = state[button.dataset.statFilter] === button.dataset.value;
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', active);
  });

  const totalButton = statsEl.querySelector('[data-reset-filters]');
  if (totalButton) {
    const unfiltered = !state.category && !state.missing && !state.search;
    totalButton.classList.toggle('is-active', unfiltered);
    totalButton.setAttribute('aria-pressed', unfiltered);
  }
}

// ---- Список ----

async function loadList() {
  const requestId = ++listRequestId;
  listEl.classList.add('is-loading');
  try {
    const result = await api.listPhrases({
      category: state.category || undefined,
      missing: state.missing || undefined,
      search: state.search || undefined,
      page: state.page,
      perPage: PER_PAGE,
    });
    if (requestId !== listRequestId) return;

    // Видалили останню фразу на сторінці — переходимо на попередню
    if (!result.items.length && state.page > 1) {
      state.page -= 1;
      loadList();
      return;
    }

    items = new Map(result.items.map((p) => [p.id, p]));
    renderList(result);
  } catch (error) {
    if (requestId === listRequestId) handleError(error);
  } finally {
    if (requestId === listRequestId) listEl.classList.remove('is-loading');
  }
}

function renderList({ items: phrases, total, page, perPage }) {
  const filtered = state.category || state.missing || state.search;
  metaEl.textContent = filtered
    ? `Найдено: ${total} ${pluralRu(total, 'фраза', 'фразы', 'фраз')}`
    : `Всего: ${total} ${pluralRu(total, 'фраза', 'фразы', 'фраз')}`;

  if (!phrases.length) {
    listEl.innerHTML = `<div class="empty">${
      filtered ? 'Ничего не найдено. Попробуйте изменить фильтры.' : 'Фраз пока нет. Добавьте первую или импортируйте файл.'
    }</div>`;
  } else {
    listEl.innerHTML = `
      <table class="phrases">
        <thead>
          <tr>
            <th class="phrases__id">№</th>
            <th class="phrases__category">Категория</th>
            ${LANGS.map((lang) => `<th>${lang.toUpperCase()}</th>`).join('')}
            <th class="phrases__status">Статус</th>
            <th class="phrases__actions"><span class="visually-hidden">Действия</span></th>
          </tr>
        </thead>
        <tbody>${phrases.map(renderRow).join('')}</tbody>
      </table>
    `;
  }

  renderPagination(page, Math.ceil(total / perPage));
}

function renderRow(phrase) {
  return `
    <tr class="${phrase.isActive ? '' : 'is-inactive'}">
      <td class="phrases__id" data-label="№">${phrase.id}</td>
      <td class="phrases__category" data-label="Категория">${CATEGORY_LABELS[phrase.category] ?? escapeHtml(phrase.category)}</td>
      ${LANGS.map((lang) => `
        <td class="phrases__text" data-label="${lang.toUpperCase()}">
          ${phrase.text[lang] ? escapeHtml(phrase.text[lang]) : '<span class="missing">нет перевода</span>'}
        </td>
      `).join('')}
      <td class="phrases__status" data-label="Статус">
        <span class="badge ${phrase.isActive ? 'badge--active' : 'badge--hidden'}">${phrase.isActive ? 'Показывается' : 'Скрыта'}</span>
      </td>
      <td class="phrases__actions">
        <button class="button button--small button--secondary" type="button" data-edit="${phrase.id}">Изменить</button>
        <button class="button button--small button--ghost-danger" type="button" data-delete="${phrase.id}">Удалить</button>
      </td>
    </tr>
  `;
}

function renderPagination(page, pages) {
  if (pages <= 1) {
    paginationEl.innerHTML = '';
    return;
  }
  paginationEl.innerHTML = `
    <button class="button button--secondary button--small" type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>← Назад</button>
    <span>Страница ${page} из ${pages}</span>
    <button class="button button--secondary button--small" type="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Вперёд →</button>
  `;
}

// ---- Видалення ----

async function deletePhrase(id) {
  const phrase = items.get(id);
  const preview = LANGS.map((lang) => phrase?.text[lang]).find(Boolean) ?? '';
  const confirmed = await confirmAction({
    title: `Удалить фразу №${id}?`,
    text: `«${preview}» — будет удалена на всех трёх языках. Это действие нельзя отменить. Если фразу нужно лишь временно убрать с сайта, снимите галочку «Показывать пользователям».`,
  });
  if (!confirmed) return;

  try {
    await api.deletePhrase(id);
    toast(`Фраза №${id} удалена`);
    refresh();
  } catch (error) {
    handleError(error);
  }
}
