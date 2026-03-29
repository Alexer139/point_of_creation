<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/templates/layout.php';

if (is_logged_in()) { header('Location: /'); exit; }

$error = '';
$success = get_flash('reg_success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $result = login_user($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['ok']) { header('Location: /'); exit; }
        $error = $result['error'];
    }
}

layout_start('Вход', ['body_class' => 'auth-page']);
?>

<div class="auth-card">
  <div class="auth-card__logo">
    <div class="auth-card__logo-icon">✦</div>
    <h1 class="auth-card__logo-title">Point of <em>Creation</em></h1>
    <p class="auth-card__logo-sub">Ваше личное пространство для продуктивности</p>
  </div>

  <div class="auth-card__body">
    <?php if ($success): ?>
      <div class="alert alert--success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert--error">✕ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div class="field">
        <label class="field__label" for="username">Логин</label>
        <input class="input" type="text" id="username" name="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               placeholder="Ваш логин" autocomplete="username" required autofocus>
      </div>

      <div class="field">
        <label class="field__label" for="password">Пароль</label>
        <input class="input" type="password" id="password" name="password"
               placeholder="••••••••" autocomplete="current-password" required>
      </div>

      <div class="field" style="margin-top:1.5rem">
        <button type="submit" class="btn btn--warm btn--full">Войти →</button>
      </div>
    </form>

    <div class="auth-card__footer">
      Нет аккаунта? <a href="/register.php">Зарегистрироваться</a>
    </div>
  </div>

  <p class="auth-card__hint">Тестовый доступ: <span>admin</span> / admin123</p>
</div>

<?php layout_end(); ?>
