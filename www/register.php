<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';

if (is_logged_in()) { header('Location: /'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $result = register_user(
            $_POST['username'] ?? '',
            $_POST['email']    ?? '',
            $_POST['password'] ?? ''
        );
        if ($result['ok']) {
            set_flash('reg_success', 'Аккаунт создан. Добро пожаловать!');
            header('Location: /login.php');
            exit;
        }
        $error = $result['error'];
    }
}

layout_start('Регистрация', ['body_class' => 'auth-page']);
?>

<div class="auth-card">
  <div class="auth-card__logo">
    <div class="auth-card__logo-icon">✦</div>
    <h1 class="auth-card__logo-title">Point of <em>Creation</em></h1>
    <p class="auth-card__logo-sub">Создайте своё пространство для продуктивности</p>
  </div>

  <div class="auth-card__body">
    <?php if ($error): ?>
      <div class="alert alert--error">✕ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div class="field">
        <label class="field__label" for="username">Логин</label>
        <input class="input" type="text" id="username" name="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               placeholder="только a–z, 0–9 и _"
               autocomplete="username" required autofocus
               minlength="3" maxlength="32">
      </div>

      <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="input" type="email" id="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="you@example.com"
               autocomplete="email" required>
      </div>

      <div class="field">
        <label class="field__label" for="password">Пароль</label>
        <input class="input" type="password" id="password" name="password"
               placeholder="минимум 6 символов"
               autocomplete="new-password" required minlength="6">
      </div>

      <div class="field" style="margin-top:1.5rem">
        <button type="submit" class="btn btn--warm btn--full">Создать аккаунт →</button>
      </div>
    </form>

    <div class="auth-card__footer">
      Уже есть аккаунт? <a href="/login.php">Войти</a>
    </div>
  </div>

</div>

<?php layout_end(); ?>
