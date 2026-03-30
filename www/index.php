<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';
require_auth();

$user = current_user();
$db   = get_db();

$stmt = $db->prepare(
    'SELECT * FROM widgets WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
);
$stmt->execute([$user['id']]);

$initial_widgets = array_map(fn($r) => [
    'id'         => (int)$r['id'],
    'type'       => $r['type'],
    'title'      => $r['title'],
    'content'    => json_decode($r['content'], true) ?: [],
    'position_w' => (int)$r['position_w'],
    'position_h' => (int)$r['position_h'],
], $stmt->fetchAll());

// Widget palette: [type, label, icon_name]
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

layout_start('Мой дашборд');
?>

<div class="app">

  <!-- Navbar -->
  <nav class="navbar">
    <a href="/" class="logo">
      <div class="logo__mark"><?= icon('sparkles', '', 16) ?></div>
      <span class="logo__text">Point of <em>Creation</em></span>
    </a>
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

    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar__header">
        <div class="sidebar__clock" id="sidebar-time">00:00</div>
        <div class="sidebar__date"  id="sidebar-date"></div>
      </div>

      <div class="sidebar__section sidebar__section--grow">
        <div class="sidebar__label"><?= icon('layout-grid', '', 12) ?> Виджеты</div>
        <?php foreach ($palette as [$type, $label, $ico]): ?>
          <button class="wpal" onclick="openModal('<?= $type ?>', '<?= addslashes($label) ?>')">
            <span class="wpal__icon"><?= icon($ico, '', 15) ?></span>
            <?= $label ?>
          </button>
        <?php endforeach; ?>
      </div>
    </aside>

    <!-- Editor -->
    <div style="flex:1;display:flex;flex-direction:column;min-width:0">
      <div class="topbar">
        <span class="topbar__title">Мой дашборд</span>
        <span class="topbar__badge" id="widget-count">0 виджетов</span>
        <div class="topbar__spacer"></div>
        <div class="autosave-status" id="autosave-status"><?= icon('check', '', 13) ?> Сохранено</div>
        <button class="btn btn--danger btn--sm" onclick="clearAllWidgets()" title="Удалить все виджеты">
          <?= icon('trash', '', 13) ?> Очистить
        </button>
      </div>

      <div class="canvas">
        <div id="muuri-grid" class="canvas__grid"></div>
      </div>
    </div>

  </div>
</div>

<!-- Modal -->
<div class="overlay" id="overlay">
  <div class="modal">
    <div class="modal__head">
      <span class="modal__title" id="modal-title">Добавить виджет</span>
      <button class="modal__close" onclick="closeModal()"><?= icon('x', '', 16) ?></button>
    </div>
    <div class="modal__body" id="modal-body"></div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeModal()">Отмена</button>
      <button class="btn btn--warm" id="modal-confirm" onclick="confirmAdd()"><?= icon('plus', '', 15) ?> Добавить</button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<script>
  const CSRF_TOKEN      = <?= json_encode(csrf_token()) ?>;
  const INITIAL_WIDGETS = <?= json_encode($initial_widgets, JSON_UNESCAPED_UNICODE) ?>;
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
