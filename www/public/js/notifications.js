"use strict";
/**
 * public/js/notifications.js
 * Point of Creation — система уведомлений
 * Подключается в layout.php только для залогиненных пользователей
 */

const NOTIF_POLL_INTERVAL = 30000; // опрашивать каждые 30 сек
let notifPollTimer = null;
let notifPanelOpen = false;

// ── Тексты уведомлений ────────────────────────────────────────

function notifText(n) {
  const dash = `<strong>${esc(n.dashboard_name)}</strong>`;
  const actor = `<span class="notif-actor">${esc(n.actor_name)}</span>`;
  const roleLabel = n.role === 'editor'
    ? '<span class="notif-role notif-role--editor">редактор</span>'
    : '<span class="notif-role notif-role--viewer">наблюдатель</span>';

  switch (n.type) {
    case 'invited':
      return `${actor} пригласил вас в дашборд ${dash} как ${roleLabel}`;
    case 'role_changed':
      return `${actor} изменил вашу роль в дашборде ${dash} на ${roleLabel}`;
    case 'removed':
      return `${actor} убрал вас из дашборда ${dash}`;
    default:
      return `Новое уведомление от ${actor}`;
  }
}

function notifIcon(type) {
  switch (type) {
    case 'invited':      return '<span class="notif-icon notif-icon--invited">👋</span>';
    case 'role_changed': return '<span class="notif-icon notif-icon--role">🔑</span>';
    case 'removed':               return '<span class="notif-icon notif-icon--removed">🚪</span>';
    case 'subscription_upgrade':  return '<span class="notif-icon">⬆️</span>';
    case 'subscription_downgrade':return '<span class="notif-icon">⬇️</span>';
    case 'subscription_renewed':  return '<span class="notif-icon">✅</span>';
    case 'subscription_expired':  return '<span class="notif-icon">⏰</span>';
    default:                      return '<span class="notif-icon">🔔</span>';
  }
}

function notifTime(dateStr) {
  const d = new Date(dateStr.replace(' ', 'T') + 'Z'); // UTC → local
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60)   return 'только что';
  if (diff < 3600) return `${Math.floor(diff/60)} мин назад`;
  if (diff < 86400)return `${Math.floor(diff/3600)} ч назад`;
  return d.toLocaleDateString('ru-RU', { day:'2-digit', month:'short' });
}

// ── Загрузка и рендер ─────────────────────────────────────────

async function loadNotifications() {
  try {
    const r = await apiNotif('get_notifications', {});
    if (!r.ok) return;

    // Обновить бейдж
    const badge = document.getElementById('notif-badge');
    if (badge) {
      badge.textContent = r.unread > 9 ? '9+' : r.unread;
      badge.style.display = r.unread > 0 ? 'flex' : 'none';
    }

    // Если панель открыта — перерисовать список
    if (notifPanelOpen) {
      renderNotifList(r.notifications);
    }
  } catch (e) { /* тихо */ }
}

function renderNotifList(notifications) {
  const list = document.getElementById('notif-list');
  if (!list) return;

  if (!notifications.length) {
    list.innerHTML = '<div class="notif-empty">Уведомлений нет</div>';
    return;
  }

  list.innerHTML = notifications.map(n => `
    <div class="notif-item ${n.is_read ? '' : 'notif-item--unread'}" id="ni-${n.id}">
      <div class="notif-item__icon">${notifIcon(n.type)}</div>
      <div class="notif-item__body">
        <div class="notif-item__text">${notifText(n)}</div>
        <div class="notif-item__time">${notifTime(n.created_at)}</div>
      </div>
      <button class="notif-item__dismiss" onclick="dismissNotif(${n.id})" title="Удалить">✕</button>
    </div>
  `).join('');
}

// ── Открытие/закрытие панели ──────────────────────────────────

async function toggleNotifPanel() {
  const panel = document.getElementById('notif-panel');
  if (!panel) return;

  notifPanelOpen = !notifPanelOpen;
  panel.classList.toggle('notif-panel--open', notifPanelOpen);

  if (notifPanelOpen) {
    // Загрузить и показать
    const r = await apiNotif('get_notifications', {});
    if (r.ok) {
      renderNotifList(r.notifications);
      // Сбросить бейдж после открытия
      const badge = document.getElementById('notif-badge');
      if (badge) badge.style.display = 'none';
    }
    // Пометить все прочитанными автоматически через 1.5 сек
    setTimeout(() => markAllRead(false), 1500);

    // Закрыть по клику вне
    setTimeout(() => {
      document.addEventListener('click', closeNotifOnOutside, { once: true });
    }, 0);
  }
}

function closeNotifOnOutside(e) {
  const bell = document.getElementById('notif-bell');
  if (bell && !bell.contains(e.target)) {
    closeNotifPanel();
  }
}

function closeNotifPanel() {
  notifPanelOpen = false;
  const panel = document.getElementById('notif-panel');
  if (panel) panel.classList.remove('notif-panel--open');
}

// ── Действия ──────────────────────────────────────────────────

async function markAllRead(rerender = true) {
  await apiNotif('mark_notifications_read', { ids: 'all' });
  const badge = document.getElementById('notif-badge');
  if (badge) badge.style.display = 'none';

  if (rerender) {
    document.querySelectorAll('.notif-item--unread').forEach(el => {
      el.classList.remove('notif-item--unread');
    });
  }
}

async function dismissNotif(id) {
  await apiNotif('delete_notification', { id });
  const el = document.getElementById(`ni-${id}`);
  if (el) {
    el.style.opacity = '0';
    el.style.transform = 'translateX(20px)';
    setTimeout(() => {
      el.remove();
      const list = document.getElementById('notif-list');
      if (list && !list.querySelector('.notif-item')) {
        list.innerHTML = '<div class="notif-empty">Уведомлений нет</div>';
      }
    }, 250);
  }
}

// ── API helper (не зависит от app.js) ────────────────────────

async function apiNotif(action, body) {
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrf = csrfMeta ? csrfMeta.getAttribute('content') : (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');

  const resp = await fetch('/api.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf,
    },
    body: JSON.stringify({ action, csrf, ...body }),
  });
  return resp.json();
}

// ── Escape helper (если нет app.js) ──────────────────────────
function esc(s) {
  if (typeof s !== 'string') return '';
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Инициализация ─────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
  loadNotifications();
  notifPollTimer = setInterval(loadNotifications, NOTIF_POLL_INTERVAL);
});