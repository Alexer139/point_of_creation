<?php
/**
 * templates/layout.php
 * Point of Creation — общая HTML-обёртка
 */

function layout_start(string $title = 'Dashboard', array $opts = []): void
{
  $body_class = $opts['body_class'] ?? '';
  ?>
  <!DOCTYPE html>
  <html lang="ru">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($title) ?> — Point of Creation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
      href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Fraunces:ital,wght@0,500;0,700;1,300;1,500&display=swap"
      rel="stylesheet">
    <link rel="stylesheet" href="/public/css/app.css">
    <link rel="stylesheet" href="/public/css/dashboard-additions.css">

    <link rel="stylesheet" href="/public/css/notifications.css">
    <script>
      (function () {
        var t = localStorage.getItem('poc-theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
      })();
    </script>
  </head>

  <body class="<?= htmlspecialchars($body_class) ?>">
    <?php
}

function layout_end(array $scripts = []): void
{
  foreach ($scripts as $src):
    ?>
      <script src="<?= htmlspecialchars($src) ?>"></script>
      <?php
  endforeach;
  // Подключаем dashboard.js после app.js
  if (!empty($scripts)):
    ?>
      <script src="/public/js/dashboard.js"></script>
      <?php
  endif;
  ?>

    <?php if (is_logged_in()): ?>
      <!-- ══ Уведомления ════════════════════════════════════════════ -->
      <div class="notif-bell" id="notif-bell">
        <button class="notif-bell__btn" id="notif-btn" onclick="toggleNotifPanel()" title="Уведомления">
          <?= icon('bell', '', 18) ?>
          <span class="notif-bell__badge" id="notif-badge" style="display:none">0</span>
        </button>
        <div class="notif-panel" id="notif-panel">
          <div class="notif-panel__head">
            <span class="notif-panel__title"><?= icon('bell', '', 15) ?> Уведомления</span>
            <button class="notif-panel__read-all" onclick="markAllRead()">Прочитать все</button>
          </div>
          <div class="notif-list" id="notif-list">
            <div class="notif-empty">Загрузка...</div>
          </div>
        </div>
      </div>
      <script src="/public/js/notifications.js"></script>
    <?php endif; ?>
  </body>

  </html>
  <?php
}
