<?php
/**
 * register.php
 * Point of Creation — регистрация нового пользователя
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/templates/layout.php';

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Неверный CSRF-токен. Обновите страницу.';
    } else {
        $result = register_user(
            $_POST['username'] ?? '',
            $_POST['email']    ?? '',
            $_POST['password'] ?? ''
        );
        if ($result['ok']) {
            set_flash('success', 'Аккаунт создан. Войдите, чтобы продолжить.');
            header('Location: /login.php');
            exit;
        }
        $error = $result['error'];
    }
}

layout_start('Регистрация', ['body_class' => 'auth-page']);
?>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <span class="logo__mark">✦</span>
      <span class="logo__text">Point of <em>Creation</em></span>
    </div>
    <h1 class="auth-title">Создать аккаунт</h1>

    <?php if ($error): ?>
      <div class="alert alert--error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/register.php" class="auth-form">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div class="field">
        <label class="field__label" for="username">Имя пользователя</label>
        <input type="text" id="username" name="username" class="input"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               placeholder="только латиница, цифры, _" autocomplete="username" required>
      </div>

      <div class="field">
        <label class="field__label" for="email">Email</label>
        <input type="email" id="email" name="email" class="input"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" autocomplete="email" required>
        <span class="field__hint">Используется для приглашений в дашборды</span>
      </div>

      <div class="field">
        <label class="field__label" for="password">Пароль</label>
        <input type="password" id="password" name="password" class="input"
               placeholder="минимум 6 символов" autocomplete="new-password" required>
      </div>

      <button type="submit" class="btn btn--warm btn--full">Зарегистрироваться</button>
    </form>

    <p class="auth-alt">Уже есть аккаунт? <a href="/login.php">Войти</a></p>
  </div>
</div>

<?php layout_end(); ?>
