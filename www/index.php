<?php
require_once __DIR__ . '/core/auth.php';
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

layout_start('Мой дашборд');
?>

<div class="app">

  <!-- Navbar -->
  <nav class="navbar">
    <a href="/" class="logo">
      <div class="logo__mark">✦</div>
      <span class="logo__text">Point of <em>Creation</em></span>
    </a>
    <div class="nav-spacer"></div>
    <a href="/settings.php" class="nav-user" title="Настройки профиля">👤 <?= htmlspecialchars($user['username']) ?></a>

    <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему">🌙</button>
    <a href="/about.php" class="btn btn--ghost">О проекте</a>
    <?php if (is_admin()): ?>
      <a href="/admin.php" class="btn btn--admin">⚙ Admin</a>
    <?php endif; ?>
    <a href="/logout.php" class="btn btn--danger">Выйти</a>
  </nav>

  <div class="main">

    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar__header">
        <div class="sidebar__clock" id="sidebar-time">00:00</div>
        <div class="sidebar__date"  id="sidebar-date"></div>
      </div>

      <div class="sidebar__section sidebar__section--grow">
        <div class="sidebar__label">✦ Виджеты</div>
        <?php
        $palette = [
          ['note',       'Заметка',             '📝'],
          ['checklist',  'Список дел',          '✅'],
          ['calendar',   'Календарь',           '📅'],
          ['metric',     'Числовой показатель', '🔢'],
          ['timer',      'Таймер',              '⏱'],
          ['table',      'Таблица',             '🗂'],
          ['goal',       'Прогресс / Цель',     '🎯'],
          ['line_chart', 'Линейный график',     '📈'],
          ['bar_chart',  'Диаграмма',           '📊'],
        ];
        foreach ($palette as [$type, $label, $icon]):
        ?>
          <button class="wpal" onclick="openModal('<?= $type ?>', '<?= addslashes($label) ?>')">
            <span class="wpal__icon"><?= $icon ?></span>
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
        <div class="autosave-status" id="autosave-status">✦ Сохранено</div>
        <button class="btn btn--danger btn--sm" onclick="clearAllWidgets()" title="Удалить все виджеты">🗑 Очистить</button>
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
      <button class="modal__close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal__body" id="modal-body"></div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeModal()">Отмена</button>
      <button class="btn btn--warm" id="modal-confirm" onclick="confirmAdd()">✦ Добавить</button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<script>
  const CSRF_TOKEN      = <?= json_encode(csrf_token()) ?>;
  const INITIAL_WIDGETS = <?= json_encode($initial_widgets, JSON_UNESCAPED_UNICODE) ?>;
  // Inline fallback so theme toggle works even before app.js loads
  function toggleTheme(){
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('poc-theme', t);
    var b = document.getElementById('theme-toggle');
    if (b) b.textContent = t === 'dark' ? '☀️' : '🌙';
  }
</script>

<?php layout_end([
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
  '/public/js/app.js',
]); ?>
