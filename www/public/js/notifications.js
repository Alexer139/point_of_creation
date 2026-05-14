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
  const roleLabel =
    n.role === "editor"
      ? '<span class="notif-role notif-role--editor">редактор</span>'
      : '<span class="notif-role notif-role--viewer">наблюдатель</span>';

  switch (n.type) {
    case "invited":
      return `${actor} пригласил вас в дашборд ${dash} как ${roleLabel}`;
    case "role_changed":
      return `${actor} изменил вашу роль в дашборде ${dash} на ${roleLabel}`;
    case "removed":
      return `${actor} убрал вас из дашборда ${dash}`;
    default:
      return `Новое уведомление от ${actor}`;
  }
}

// SVG иконки для уведомлений (inline, без зависимостей)
const NOTIF_SVG = {
  users:
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  key: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>',
  userMinus:
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="22" x2="16" y1="11" y2="11"/></svg>',
  arrowUp:
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>',
  arrowDown:
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>',
  refresh:
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
  clock:
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  bell: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
};

function notifIcon(type) {
  const icons = {
    invited: { svg: NOTIF_SVG.users, cls: "notif-icon--invited" },
    role_changed: { svg: NOTIF_SVG.key, cls: "notif-icon--role" },
    removed: { svg: NOTIF_SVG.userMinus, cls: "notif-icon--removed" },
    subscription_upgrade: {
      svg: NOTIF_SVG.arrowUp,
      cls: "notif-icon--upgrade",
    },
    subscription_downgrade: {
      svg: NOTIF_SVG.arrowDown,
      cls: "notif-icon--downgrade",
    },
    subscription_renewed: {
      svg: NOTIF_SVG.refresh,
      cls: "notif-icon--renewed",
    },
    subscription_expired: { svg: NOTIF_SVG.clock, cls: "notif-icon--expired" },
  };
  const icon = icons[type] || { svg: NOTIF_SVG.bell, cls: "" };
  return `<span class="notif-icon ${icon.cls}">${icon.svg}</span>`;
}

function notifTime(dateStr) {
  const d = new Date(dateStr.replace(" ", "T") + "Z"); // UTC → local
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60) return "только что";
  if (diff < 3600) return `${Math.floor(diff / 60)} мин назад`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} ч назад`;
  return d.toLocaleDateString("ru-RU", { day: "2-digit", month: "short" });
}

// ── Загрузка и рендер ─────────────────────────────────────────

async function loadNotifications() {
  try {
    const r = await apiNotif("get_notifications", {});
    if (!r.ok) return;

    // Обновить бейдж
    const badge = document.getElementById("notif-badge");
    if (badge) {
      badge.textContent = r.unread > 9 ? "9+" : r.unread;
      badge.style.display = r.unread > 0 ? "flex" : "none";
    }

    // Если панель открыта — перерисовать список
    if (notifPanelOpen) {
      renderNotifList(r.notifications);
    }
  } catch (e) {
    /* тихо */
  }
}

function renderNotifList(notifications) {
  const list = document.getElementById("notif-list");
  if (!list) return;

  if (!notifications.length) {
    list.innerHTML = '<div class="notif-empty">Уведомлений нет</div>';
    return;
  }

  list.innerHTML = notifications
    .map(
      (n) => `
    <div class="notif-item ${n.is_read ? "" : "notif-item--unread"}" id="ni-${n.id}">
      <div class="notif-item__icon">${notifIcon(n.type)}</div>
      <div class="notif-item__body">
        <div class="notif-item__text">${notifText(n)}</div>
        <div class="notif-item__time">${notifTime(n.created_at)}</div>
      </div>
      <button class="notif-item__dismiss" onclick="dismissNotif(${n.id})" title="Удалить">✕</button>
    </div>
  `,
    )
    .join("");
}

// ── Открытие/закрытие панели ──────────────────────────────────

async function toggleNotifPanel() {
  const panel = document.getElementById("notif-panel");
  if (!panel) return;

  notifPanelOpen = !notifPanelOpen;
  panel.classList.toggle("notif-panel--open", notifPanelOpen);

  if (notifPanelOpen) {
    // Загрузить и показать
    const r = await apiNotif("get_notifications", {});
    if (r.ok) {
      renderNotifList(r.notifications);
      // Сбросить бейдж после открытия
      const badge = document.getElementById("notif-badge");
      if (badge) badge.style.display = "none";
    }
    // Пометить все прочитанными автоматически через 1.5 сек
    setTimeout(() => markAllRead(false), 1500);

    // Закрыть по клику вне
    setTimeout(() => {
      document.addEventListener("click", closeNotifOnOutside, { once: true });
    }, 0);
  }
}

function closeNotifOnOutside(e) {
  const bell = document.getElementById("notif-bell");
  if (bell && !bell.contains(e.target)) {
    closeNotifPanel();
  }
}

function closeNotifPanel() {
  notifPanelOpen = false;
  const panel = document.getElementById("notif-panel");
  if (panel) panel.classList.remove("notif-panel--open");
}

// ── Действия ──────────────────────────────────────────────────

async function markAllRead(rerender = true) {
  await apiNotif("mark_notifications_read", { ids: "all" });
  const badge = document.getElementById("notif-badge");
  if (badge) badge.style.display = "none";

  if (rerender) {
    document.querySelectorAll(".notif-item--unread").forEach((el) => {
      el.classList.remove("notif-item--unread");
    });
  }
}

async function dismissNotif(id) {
  await apiNotif("delete_notification", { id });
  const el = document.getElementById(`ni-${id}`);
  if (el) {
    el.style.opacity = "0";
    el.style.transform = "translateX(20px)";
    setTimeout(() => {
      el.remove();
      const list = document.getElementById("notif-list");
      if (list && !list.querySelector(".notif-item")) {
        list.innerHTML = '<div class="notif-empty">Уведомлений нет</div>';
      }
    }, 250);
  }
}

// ── API helper (не зависит от app.js) ────────────────────────

async function apiNotif(action, body) {
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrf = csrfMeta
    ? csrfMeta.getAttribute("content")
    : typeof CSRF_TOKEN !== "undefined"
      ? CSRF_TOKEN
      : "";

  const resp = await fetch("/api.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrf,
    },
    body: JSON.stringify({ action, csrf, ...body }),
  });
  return resp.json();
}

// ── Escape helper (если нет app.js) ──────────────────────────
function esc(s) {
  if (typeof s !== "string") return "";
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

// ── Инициализация ─────────────────────────────────────────────

document.addEventListener("DOMContentLoaded", () => {
  loadNotifications();
  notifPollTimer = setInterval(loadNotifications, NOTIF_POLL_INTERVAL);
});
