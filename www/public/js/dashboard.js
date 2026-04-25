/**
 * public/js/dashboard.js
 * Point of Creation — логика мульти-дашбордов, вкладок страниц и корпоративного доступа
 *
 * Зависит от: app.js (должен подключаться ПОСЛЕ него, или можно объединить)
 * PHP инжектирует глобалы:
 *   ACTIVE_DASHBOARD_ID  — текущий дашборд
 *   ACTIVE_PAGE_ID       — текущая страница
 *   IS_EDITOR            — bool: может ли юзер редактировать
 *   IS_OWNER             — bool: владелец ли
 *   ALL_DASHBOARDS       — массив дашбордов
 */
"use strict";

// ════════════════════════════════════════════════════════════
//  Переключение дашборда
// ════════════════════════════════════════════════════════════

async function switchDashboard(dashboardId) {
  closeDashboardMenuUI();
  try {
    const r = await api('switch_dashboard', { dashboard_id: dashboardId });
    if (r.ok) {
      // Перезагрузить страницу — PHP сам подхватит из сессии
      window.location.reload();
    } else {
      toast(r.error || 'Ошибка переключения', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

// ════════════════════════════════════════════════════════════
//  Меню дашбордов (dropdown)
// ════════════════════════════════════════════════════════════

function toggleDashboardMenu() {
  const menu = document.getElementById('dashboard-menu');
  if (!menu) return;
  const isOpen = menu.classList.toggle('dashboard-menu--open');
  if (isOpen) {
    // Закрыть по клику вне
    setTimeout(() => {
      document.addEventListener('click', closeDashboardOnOutside, { once: true });
    }, 0);
  }
}

function closeDashboardOnOutside(e) {
  const selector = document.getElementById('dashboard-selector');
  if (selector && !selector.contains(e.target)) {
    closeDashboardMenuUI();
  }
}

function closeDashboardMenuUI() {
  const menu = document.getElementById('dashboard-menu');
  if (menu) menu.classList.remove('dashboard-menu--open');
}

// ════════════════════════════════════════════════════════════
//  Создать новый дашборд
// ════════════════════════════════════════════════════════════

function openCreateDashboard() {
  closeDashboardMenuUI();
  document.getElementById('overlay-dashboard').classList.add('overlay--open');
  setTimeout(() => document.getElementById('new-dashboard-name')?.focus(), 80);
}

function closeDashboardModal() {
  document.getElementById('overlay-dashboard').classList.remove('overlay--open');
  document.getElementById('new-dashboard-name').value = '';
  document.getElementById('new-dashboard-shared').checked = false;
}

async function confirmCreateDashboard() {
  const name      = document.getElementById('new-dashboard-name').value.trim();
  const is_shared = document.getElementById('new-dashboard-shared').checked ? 1 : 0;

  if (!name) {
    toast('Введите название дашборда', 'error');
    return;
  }

  try {
    const r = await api('create_dashboard', { name, is_shared });
    if (r.ok) {
      closeDashboardModal();
      toast('Дашборд создан');
      // Переключиться на новый
      await api('switch_dashboard', { dashboard_id: r.dashboard_id });
      window.location.reload();
    } else {
      toast(r.error || 'Ошибка', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

async function confirmDeleteDashboard() {
  if (!confirm('Удалить этот дашборд и все его страницы/виджеты? Это нельзя отменить.')) return;
  try {
    const r = await api('delete_dashboard', { id: ACTIVE_DASHBOARD_ID });
    if (r.ok) {
      window.location.reload();
    } else {
      toast(r.error || 'Ошибка', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

// ════════════════════════════════════════════════════════════
//  Переключение страницы
// ════════════════════════════════════════════════════════════

async function switchPage(pageId) {
  if (pageId === ACTIVE_PAGE_ID) return;

  // Сохранить текущие виджеты перед переключением
  try { await saveAllNow(); } catch (_) {}

  try {
    const r = await api('switch_page', { page_id: pageId });
    if (r.ok) {
      window.location.reload();
    } else {
      toast(r.error || 'Ошибка переключения', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

// ════════════════════════════════════════════════════════════
//  Добавить страницу
// ════════════════════════════════════════════════════════════

async function addPage() {
  const name = prompt('Название новой страницы:', 'Страница');
  if (name === null) return; // отмена
  const trimmed = name.trim() || 'Страница';

  try {
    const r = await api('create_page', {
      dashboard_id: ACTIVE_DASHBOARD_ID,
      name: trimmed,
    });
    if (r.ok) {
      // Переключиться на новую страницу
      await api('switch_page', { page_id: r.page_id });
      window.location.reload();
    } else {
      toast(r.error || 'Ошибка', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

// ════════════════════════════════════════════════════════════
//  Удалить страницу
// ════════════════════════════════════════════════════════════

async function deletePage(pageId) {
  if (!confirm('Удалить эту страницу и все её виджеты?')) return;
  try {
    const r = await api('delete_page', { id: pageId });
    if (r.ok) {
      window.location.reload();
    } else {
      toast(r.error || 'Ошибка', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

// ════════════════════════════════════════════════════════════
//  Переименование страницы (двойной клик по вкладке)
// ════════════════════════════════════════════════════════════

function startRenameTab(pageId, tabEl) {
  if (!IS_EDITOR) return;
  const nameSpan = tabEl.querySelector('.page-tab__name');
  if (!nameSpan) return;

  const original = nameSpan.textContent;
  const input = document.createElement('input');
  input.className = 'page-tab__rename-input';
  input.value = original;
  nameSpan.replaceWith(input);
  input.focus();
  input.select();

  const finish = async () => {
    const newName = input.value.trim() || original;
    const newSpan = document.createElement('span');
    newSpan.className = 'page-tab__name';
    newSpan.textContent = newName;
    input.replaceWith(newSpan);

    if (newName !== original) {
      try {
        const r = await api('rename_page', { id: pageId, name: newName });
        if (!r.ok) toast(r.error || 'Ошибка переименования', 'error');
      } catch (e) {
        toast('Ошибка сети', 'error');
      }
    }
  };

  input.addEventListener('blur', finish);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    if (e.key === 'Escape') { input.value = original; input.blur(); }
  });
}

// ════════════════════════════════════════════════════════════
//  Корпоративный доступ (Share modal)
// ════════════════════════════════════════════════════════════

async function openShareModal() {
  document.getElementById('overlay-share').classList.add('overlay--open');
  await loadMembers();
}

function closeShareModal() {
  document.getElementById('overlay-share').classList.remove('overlay--open');
}

async function loadMembers() {
  const container = document.getElementById('share-members');
  container.innerHTML = '<div class="share-loading">Загрузка...</div>';

  try {
    const r = await api('list_access', { dashboard_id: ACTIVE_DASHBOARD_ID });
    if (!r.ok) { container.innerHTML = `<p class="share-error">${r.error}</p>`; return; }

    if (!r.members.length) {
      container.innerHTML = '<p class="share-empty">Нет приглашённых пользователей</p>';
      return;
    }

    container.innerHTML = r.members.map(m => `
      <div class="share-member" id="sm-${m.id}">
        <span class="sm-avatar">${m.username[0].toUpperCase()}</span>
        <div class="sm-info">
          <span class="sm-name">${esc(m.username)}</span>
          <span class="sm-email">${esc(m.email)}</span>
        </div>
        <select class="input input--select sm-role"
                onchange="changeRole(${m.id}, this.value)">
          <option value="viewer" ${m.role === 'viewer' ? 'selected' : ''}>Просмотр</option>
          <option value="editor" ${m.role === 'editor' ? 'selected' : ''}>Редактор</option>
        </select>
        <button class="btn btn--danger btn--xs" onclick="removeAccess(${m.id})">
          Убрать
        </button>
      </div>
    `).join('');
  } catch (e) {
    container.innerHTML = '<p class="share-error">Ошибка загрузки</p>';
  }
}

async function inviteUser() {
  const email = document.getElementById('invite-email').value.trim();
  const role  = document.getElementById('invite-role').value;

  if (!email) { toast('Введите email', 'error'); return; }

  try {
    const r = await api('invite_user', {
      dashboard_id: ACTIVE_DASHBOARD_ID,
      email,
      role,
    });
    if (r.ok) {
      toast(`${r.username} добавлен как ${role === 'editor' ? 'редактор' : 'наблюдатель'}`);
      document.getElementById('invite-email').value = '';
      await loadMembers();
    } else {
      toast(r.error || 'Ошибка', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

async function removeAccess(userId) {
  if (!confirm('Убрать доступ этого пользователя?')) return;
  try {
    const r = await api('remove_access', {
      dashboard_id: ACTIVE_DASHBOARD_ID,
      user_id: userId,
    });
    if (r.ok) {
      await loadMembers();
      toast('Доступ отозван');
    } else {
      toast(r.error || 'Ошибка', 'error');
    }
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

async function changeRole(userId, newRole) {
  try {
    const r = await api('change_access_role', {
      dashboard_id: ACTIVE_DASHBOARD_ID,
      user_id: userId,
      role: newRole,
    });
    if (!r.ok) toast(r.error || 'Ошибка', 'error');
    else toast('Роль обновлена');
  } catch (e) {
    toast('Ошибка сети', 'error');
  }
}

// ════════════════════════════════════════════════════════════
//  Сохранить все виджеты немедленно (вызывается перед сменой страницы)
// ════════════════════════════════════════════════════════════

async function saveAllNow() {
  if (!IS_EDITOR) return;
  if (typeof widgets === 'undefined' || !widgets.length) return;

  const payload = widgets.map((w, idx) => ({
    id:            w.id,
    type:          w.type,
    title:         w.title || '',
    settings_json: w.content || {},
    position_w:    w.position_w || 1,
    position_h:    w.position_h || 1,
    sort_order:    idx,
  }));

  await api('save_all', { page_id: ACTIVE_PAGE_ID, widgets: payload });
}

// ════════════════════════════════════════════════════════════
//  Патч: override api() calls в app.js для передачи page_id
//  Добавляем page_id к save_widget и save_all автоматически
// ════════════════════════════════════════════════════════════

(function patchApiForPages() {
  // Ждём загрузки DOM и app.js
  document.addEventListener('DOMContentLoaded', () => {
    // Если оригинальный api() уже определён в app.js, оборачиваем его
    if (typeof window._originalApi === 'undefined' && typeof api === 'function') {
      window._originalApi = api;
      window.api = async function(action, body = {}) {
        // Автоматически добавляем page_id для виджетных операций
        if (['save_widget', 'save_all', 'delete_widget', 'update_content'].includes(action)) {
          if (!body.page_id) {
            body.page_id = ACTIVE_PAGE_ID;
          }
        }
        return window._originalApi(action, body);
      };
    }
  });
})();

// ════════════════════════════════════════════════════════════
//  Закрытие модалок по Escape / клик на overlay
// ════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  ['overlay-dashboard', 'overlay-share'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('click', (e) => {
        if (e.target === el) el.classList.remove('overlay--open');
      });
    }
  });
});
