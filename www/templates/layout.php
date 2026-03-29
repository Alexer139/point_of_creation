<?php
/**
 * templates/layout.php
 * Shared HTML shell. Call layout_start() at the top of each page
 * and layout_end() at the bottom.
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
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Fraunces:ital,wght@0,500;0,700;1,300;1,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/public/css/app.css">
  <!-- Persist theme before first paint to avoid flash -->
  <script>
    (function(){
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
?>
</body>
</html>
<?php
}
