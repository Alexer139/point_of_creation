<?php
/**
 * index.php
 * Point of Creation — главная страница с мульти-дашбордами и вкладками страниц
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/access.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';
require_auth();

$user    = current_user();
$user_id = (int) $user['id'];
$db      = get_db();

// ── Определяем активный дашборд ──────────────────────────────
$all_dashboards = get_user_dashboards($user_id);

if (empty($all_dashboards)) {
    // Крайний случай: создать дефолтный дашборд
    $db->prepare("INSERT INTO `dashboards` (`owner_id`, `name`) VALUES (?, 'Мой дашборд')")
       ->execute([$user_id]);
    $did = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO `pages` (`dashboard_id`, `name`, `order_index`) VALUES (?, 'Главная', 0)")
       ->execute([$did]);
    header('Location: /');
    exit;
}

// Текущий дашборд из сессии или первый в списке
$active_dashboard_id = (int)($_SESSION['active_dashboard_id'] ?? 0);
$valid_ids = array_column($all_dashboards, 'id');
if (!in_array($active_dashboard_id, $valid_ids, false)) {
    $active_dashboard_id = (int)$all_dashboards[0]['id'];
    $_SESSION['active_dashboard_id'] = $active_dashboard_id;
}

// Получить активный дашборд
$active_dashboard = null;
foreach ($all_dashboards as $d) {
    if ((int)$d['id'] === $active_dashboard_id) {
        $active_dashboard = $d;
        break;
    }
}

// Проверить доступ
if (!can_view_dashboard($active_dashboard_id, $user_id)) {
    $active_dashboard_id = (int)$all_dashboards[0]['id'];
    $_SESSION['active_dashboard_id'] = $active_dashboard_id;
    $active_dashboard = $all_dashboards[0];
}

$is_editor = can_edit_dashboard($active_dashboard_id, $user_id);
$is_owner  = is_dashboard_owner($active_dashboard_id, $user_id);

// ── Страницы активного дашборда ───────────────────────────────
$stmt = $db->prepare("
    SELECT * FROM `pages`
    WHERE `dashboard_id` = ?
    ORDER BY `order_index` ASC, `id` ASC
");
$stmt->execute([$active_dashboard_id]);
$pages = $stmt->fetchAll();

// Активная страница
$active_page_id = (int)($_SESSION['active_page_id'] ?? 0);
$page_ids = array_column($pages, 'id');
if (!in_array($active_page_id, $page_ids, false)) {
    $active_page_id = $pages ? (int)$pages[0]['id'] : 0;
    $_SESSION['active_page_id'] = $active_page_id;
}

// ── Виджеты активной страницы ─────────────────────────────────
$initial_widgets = [];
if ($active_page_id > 0) {
    $stmt = $db->prepare("
        SELECT * FROM `widgets`
        WHERE `page_id` = ?
        ORDER BY JSON_EXTRACT(`position_data`, '$.sort_order') ASC, `id` ASC
    ");
    $stmt->execute([$active_page_id]);

    $initial_widgets = array_map(fn($r) => [
        'id'            => (int) $r['id'],
        'type'          => $r['type'],
        'title'         => $r['title'],
        'content'       => json_decode($r['settings_json'], true) ?: [],
        'settings_json' => json_decode($r['settings_json'], true) ?: [],
        'position_w'    => (int)(json_decode($r['position_data'], true)['w'] ?? 1),
        'position_h'    => (int)(json_decode($r['position_data'], true)['h'] ?? 1),
    ], $stmt->fetchAll());
}

// ── Палитра виджетов ──────────────────────────────────────────
$palette = [
    ['note',       'Заметка',             'file-text'],
    ['checklist',  'Список дел',          'list-checks'],
    ['calendar',   'Календарь',           'calendar'],
    ['metric',     'Числовой показатель', 'hash'],
    ['timer',      'Таймер',              'timer'],
    ['table',      'Таблица',             'table'],
    ['goal',       'Прогресс / Цель',     'target'],
    ['line_chart', 'Линейный график',     'line-chart'],
    ['bar_chart',  'Диаграмма',           'bar-chart'],
];

layout_start('Дашборд');
?>

<div class="app">

  <!-- ══ NAVBAR ═════════════════════════════════════════════ -->
  <nav class="navbar">
    <a href="/" class="logo">
      <div class="logo__mark"><?= icon('sparkles', '', 16) ?></div>
      <span class="logo__text">Point of <em>Creation</em></span>
    </a>

    <!-- Селектор дашбордов -->
    <div class="dashboard-selector" id="dashboard-selector">
      <button class="dashboard-selector__btn" id="ds-toggle" onclick="toggleDashboardMenu()">
        <?php if ($active_dashboard && $active_dashboard['is_shared']): ?>
          <span class="badge badge--shared"><?= icon('users', '', 12) ?></span>
        <?php else: ?>
          <span class="badge badge--personal"><?= icon('user', '', 12) ?></span>
        <?php endif; ?>
        <span class="dashboard-selector__name" id="ds-name">
          <?= htmlspecialchars($active_dashboard['name'] ?? 'Дашборд') ?>
        </span>
        <?= icon('chevron-down', 'ds-chevron', 14) ?>
      </button>

      <div class="dashboard-menu" id="dashboard-menu">
        <div class="dashboard-menu__list" id="dashboard-menu-list">
          <?php foreach ($all_dashboards as $d): ?>
            <?php $is_active = (int)$d['id'] === $active_dashboard_id; ?>
            <div class="dashboard-menu__item <?= $is_active ? 'dashboard-menu__item--active' : '' ?>"
                 onclick="switchDashboard(<?= (int)$d['id'] ?>)">
              <?php if ($d['my_role'] === 'owner'): ?>
                <?php if ($d['is_shared']): ?>
                  <?= icon('users', 'dm-icon dm-icon--shared', 14) ?>
                <?php else: ?>
                  <?= icon('layout-dashboard', 'dm-icon dm-icon--personal', 14) ?>
                <?php endif; ?>
              <?php else: ?>
                <?= icon('share-2', 'dm-icon dm-icon--invited', 14) ?>
              <?php endif; ?>
              <span class="dm-name"><?= htmlspecialchars($d['name']) ?></span>
              <?php if ($d['my_role'] !== 'owner'): ?>
                <span class="dm-role"><?= $d['my_role'] === 'editor' ? 'ред.' : 'просм.' ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="dashboard-menu__footer">
          <button class="btn btn--ghost btn--xs" onclick="openCreateDashboard()">
            <?= icon('plus', '', 13) ?> Новый дашборд
          </button>
        </div>
      </div>
    </div>

    <div class="nav-spacer"></div>

    <a href="/settings.php" class="nav-user" title="Настройки профиля">
      <?= icon('user', '', 14) ?> <?= htmlspecialchars($user['username']) ?>
    </a>
    <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему">
      <?= icon('moon', 'icon--theme-moon', 16) ?><?= icon('sun', 'icon--theme-sun', 16) ?>
    </button>
    <a href="/about.php" class="btn btn--ghost">О проекте</a>
    <?php if (is_admin()): ?>
      <a href="/admin.php" class="btn btn--admin"><?= icon('settings', '', 14) ?> Admin</a>
    <?php endif; ?>
    <a href="/logout.php" class="btn btn--danger"><?= icon('log-out', '', 14) ?> Выйти</a>
  </nav>

  <div class="main">

    <!-- ══ SIDEBAR ═══════════════════════════════════════════ -->
    <aside class="sidebar">
      <div class="sidebar__header">
        <div class="sidebar__clock" id="sidebar-time">00:00</div>
        <div class="sidebar__date"  id="sidebar-date"></div>
      </div>

      <?php if ($is_editor): ?>
      <div class="sidebar__section sidebar__section--grow">
        <div class="sidebar__label"><?= icon('layout-grid', '', 12) ?> Виджеты</div>
        <?php foreach ($palette as [$type, $label, $ico]): ?>
          <button class="wpal" onclick="openModal('<?= $type ?>', '<?= addslashes($label) ?>')">
            <span class="wpal__icon"><?= icon($ico, '', 15) ?></span>
            <?= $label ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="sidebar__section">
        <div class="sidebar__label viewer-notice">
          <?= icon('eye', '', 13) ?> Режим просмотра
        </div>
        <p class="sidebar__hint">Вы можете просматривать этот дашборд, но не редактировать.</p>
      </div>
      <?php endif; ?>

      <?php if ($is_owner): ?>
      <!-- Управление дашбордом (только для владельца) -->
      <div class="sidebar__section">
        <div class="sidebar__label"><?= icon('settings', '', 12) ?> Дашборд</div>
        <button class="sidebar__action" onclick="openShareModal()">
          <?= icon('user-plus', '', 14) ?> Поделиться
        </button>
        <button class="sidebar__action sidebar__action--danger" onclick="confirmDeleteDashboard()">
          <?= icon('trash', '', 14) ?> Удалить дашборд
        </button>
      </div>
      <?php endif; ?>
    </aside>

    <!-- ══ MAIN CONTENT ══════════════════════════════════════ -->
    <div style="flex:1;display:flex;flex-direction:column;min-width:0">

      <!-- Вкладки страниц -->
      <div class="pages-bar" id="pages-bar">
        <div class="pages-tabs" id="pages-tabs">
          <?php foreach ($pages as $page): ?>
            <button class="page-tab <?= (int)$page['id'] === $active_page_id ? 'page-tab--active' : '' ?>"
                    id="tab-<?= (int)$page['id'] ?>"
                    onclick="switchPage(<?= (int)$page['id'] ?>)"
                    ondblclick="startRenameTab(<?= (int)$page['id'] ?>, this)"
                    data-page-id="<?= (int)$page['id'] ?>">
              <span class="page-tab__name"><?= htmlspecialchars($page['name']) ?></span>
              <?php if ($is_editor && count($pages) > 1): ?>
                <span class="page-tab__del" onclick="event.stopPropagation(); deletePage(<?= (int)$page['id'] ?>)"
                      title="Удалить страницу"><?= icon('x', '', 11) ?></span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>

        <?php if ($is_editor): ?>
        <button class="pages-add-btn" onclick="addPage()" title="Добавить страницу">
          <?= icon('plus', '', 14) ?>
        </button>
        <?php endif; ?>

        <div class="topbar__spacer"></div>
        <div class="autosave-status" id="autosave-status"><?= icon('check', '', 13) ?> Сохранено</div>
        <?php if ($is_editor): ?>
        <button class="btn btn--danger btn--sm" onclick="clearAllWidgets()" title="Удалить все виджеты">
          <?= icon('trash', '', 13) ?> Очистить
        </button>
        <?php endif; ?>
      </div>

      <div class="canvas">
        <div id="muuri-grid" class="canvas__grid"></div>
      </div>
    </div>

  </div>
</div>

<!-- ══ MODAL: Добавить виджет ════════════════════════════════ -->
<div class="overlay" id="overlay">
  <div class="modal">
    <div class="modal__head">
      <span class="modal__title" id="modal-title">Добавить виджет</span>
      <button class="modal__close" onclick="closeModal()"><?= icon('x', '', 16) ?></button>
    </div>
    <div class="modal__body" id="modal-body"></div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeModal()">Отмена</button>
      <button class="btn btn--warm" id="modal-confirm" onclick="confirmAdd()">
        <?= icon('plus', '', 15) ?> Добавить
      </button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Создать дашборд ════════════════════════════════ -->
<div class="overlay" id="overlay-dashboard">
  <div class="modal modal--sm">
    <div class="modal__head">
      <span class="modal__title"><?= icon('layout-dashboard', '', 16) ?> Новый дашборд</span>
      <button class="modal__close" onclick="closeDashboardModal()"><?= icon('x', '', 16) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label class="field__label">Название дашборда</label>
        <input type="text" class="input" id="new-dashboard-name" placeholder="Например: Работа, Личное..." maxlength="120">
      </div>
      <div class="field">
        <label class="field__label field__label--checkbox">
          <input type="checkbox" id="new-dashboard-shared">
          Корпоративный (сразу расшаренный)
        </label>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeDashboardModal()">Отмена</button>
      <button class="btn btn--warm" onclick="confirmCreateDashboard()">
        <?= icon('plus', '', 15) ?> Создать
      </button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Поделиться дашбордом ═══════════════════════════ -->
<div class="overlay" id="overlay-share">
  <div class="modal">
    <div class="modal__head">
      <span class="modal__title"><?= icon('share-2', '', 16) ?> Общий доступ</span>
      <button class="modal__close" onclick="closeShareModal()"><?= icon('x', '', 16) ?></button>
    </div>
    <div class="modal__body">
      <p class="share-hint">Пригласите пользователя по его email-адресу.</p>
      <div class="field field--row">
        <input type="email" class="input input--grow" id="invite-email" placeholder="email@example.com">
        <select class="input input--select" id="invite-role">
          <option value="viewer">Просмотр</option>
          <option value="editor">Редактор</option>
        </select>
        <button class="btn btn--warm" onclick="inviteUser()">
          <?= icon('user-plus', '', 14) ?> Пригласить
        </button>
      </div>
      <div class="share-members" id="share-members">
        <div class="share-loading"><?= icon('loader', '', 16) ?> Загрузка...</div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeShareModal()">Закрыть</button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<script>
  const CSRF_TOKEN          = <?= json_encode(csrf_token()) ?>;
  const INITIAL_WIDGETS     = <?= json_encode($initial_widgets, JSON_UNESCAPED_UNICODE) ?>;
  const ACTIVE_DASHBOARD_ID = <?= json_encode($active_dashboard_id) ?>;
  const ACTIVE_PAGE_ID      = <?= json_encode($active_page_id) ?>;
  const IS_EDITOR           = <?= json_encode($is_editor) ?>;
  const IS_OWNER            = <?= json_encode($is_owner) ?>;
  const ALL_DASHBOARDS      = <?= json_encode(array_map(fn($d) => [
      'id'       => (int)$d['id'],
      'name'     => $d['name'],
      'is_shared'=> (bool)$d['is_shared'],
      'my_role'  => $d['my_role'],
  ], $all_dashboards), JSON_UNESCAPED_UNICODE) ?>;

  function toggleTheme(){
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('poc-theme', t);
    applyThemeIcons(t);
  }
  function applyThemeIcons(t){
    document.querySelectorAll('.icon--theme-moon').forEach(function(el){ el.style.display = t==='dark'?'none':'inline-block'; });
    document.querySelectorAll('.icon--theme-sun').forEach(function(el){ el.style.display = t==='dark'?'inline-block':'none'; });
  }
  (function(){
    var t = document.documentElement.getAttribute('data-theme') || 'light';
    applyThemeIcons(t);
  })();
</script>

<?php layout_end([
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
    '/public/js/app.js',
]); ?>
