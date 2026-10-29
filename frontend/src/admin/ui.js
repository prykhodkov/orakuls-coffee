// Дрібні спільні утиліти інтерфейсу адмінки

export function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

export function pluralRu(n, one, few, many) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return one;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few;
  return many;
}

// ---- Тости ----

const toasts = document.querySelector('[data-toasts]');

export function toast(message, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast toast--${type}`;
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  el.textContent = message;
  toasts.append(el);
  setTimeout(() => {
    el.classList.add('is-leaving');
    el.addEventListener('transitionend', () => el.remove(), { once: true });
  }, type === 'error' ? 6000 : 3000);
}

// ---- Підтвердження ----

const confirmDialog = document.querySelector('[data-confirm-dialog]');

export function confirmAction({ title, text, confirmLabel = 'Удалить' }) {
  confirmDialog.querySelector('[data-confirm-title]').textContent = title;
  confirmDialog.querySelector('[data-confirm-text]').textContent = text;
  confirmDialog.querySelector('[data-confirm-ok]').textContent = confirmLabel;
  confirmDialog.returnValue = '';
  confirmDialog.showModal();
  return new Promise((resolve) => {
    confirmDialog.addEventListener('close', () => resolve(confirmDialog.returnValue === 'ok'), { once: true });
  });
}

// Закриття модалок кліком по затемненню
export function closeOnBackdrop(dialog) {
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
}
