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

    <script>
      function toggleTheme() {
        var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('poc-theme', t);
        document.querySelectorAll('.icon--theme-moon').forEach(function (el) { el.style.display = t === 'dark' ? 'none' : 'inline-block'; });
        document.querySelectorAll('.icon--theme-sun').forEach(function (el) { el.style.display = t === 'dark' ? 'inline-block' : 'none'; });
      }
      function applyThemeIcons(t) {
        document.querySelectorAll('.icon--theme-moon').forEach(function (el) { el.style.display = t === 'dark' ? 'none' : 'inline-block'; });
        document.querySelectorAll('.icon--theme-sun').forEach(function (el) { el.style.display = t === 'dark' ? 'inline-block' : 'none'; });
      }
      (function () { applyThemeIcons(document.documentElement.getAttribute('data-theme') || 'light'); })();
    </script>
    <?php if (is_logged_in()): ?>
      <script src="/public/js/notifications.js"></script>
    <?php endif; ?>
  </body>

  </html>
  <?php
}
