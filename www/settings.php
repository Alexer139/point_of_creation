<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/templates/layout.php';
require_auth();

$user = current_user();
$db   = get_db();

$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Неверный CSRF-токен. Обновите страницу.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'change_username') {
            $new = trim($_POST['username'] ?? '');
            if (strlen($new) < 3 || strlen($new) > 32) {
                $errors[] = 'Имя пользователя: от 3 до 32 символов.';
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new)) {
                $errors[] = 'Имя пользователя: только латиница, цифры и _.';
            } else {
                $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
                $stmt->execute([$new, $user['id']]);
                if ($stmt->fetch()) {
                    $errors[] = 'Это имя уже занято другим пользователем.';
                } else {
                    $db->prepare('UPDATE users SET username = ? WHERE id = ?')
                       ->execute([$new, $user['id']]);
                    $_SESSION['user']['username'] = $new;
                    $user = current_user();
                    $success[] = 'Имя пользователя успешно изменено.';
                }
            }
        }

        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            if (!password_verify($current, $row['password'])) {
                $errors[] = 'Текущий пароль введён неверно.';
            } elseif (strlen($new) < 6) {
                $errors[] = 'Новый пароль: минимум 6 символов.';
            } elseif ($new !== $confirm) {
                $errors[] = 'Новый пароль и подтверждение не совпадают.';
            } else {
                $db->prepare('UPDATE users SET password = ? WHERE id = ?')
                   ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                $success[] = 'Пароль успешно изменён.';
            }
        }

        if ($action === 'delete_account') {
            $pass = $_POST['confirm_password_delete'] ?? '';
            $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            if (!password_verify($pass, $row['password'])) {
                $errors[] = 'Неверный пароль. Аккаунт не удалён.';
            } else {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
                session_destroy();
                header('Location: /login.php');
                exit;
            }
        }
    }
}

$stmt = $db->prepare('SELECT COUNT(*) as cnt, MAX(updated_at) as last FROM widgets WHERE user_id = ?');
$stmt->execute([$user['id']]);
$wstats = $stmt->fetch();

$stmt2 = $db->prepare('SELECT created_at FROM users WHERE id = ?');
$stmt2->execute([$user['id']]);
$urow = $stmt2->fetch();

layout_start('Настройки', ['body_class' => 'settings-page']);
?>

<nav class="navbar">
  <a href="/" class="logo">
    <div class="logo__mark">✦</div>
    <span class="logo__text">Point of <em>Creation</em></span>
  </a>
  <div class="nav-spacer"></div>
  <a href="/settings.php" class="nav-user nav-user--active" title="Настройки профиля">
    👤 <?= htmlspecialchars($user['username']) ?>
  </a>
  <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему">🌙</button>
  <a href="/" class="btn btn--ghost">← Дашборд</a>
  <?php if (is_admin()): ?>
    <a href="/admin.php" class="btn btn--admin">⚙ Admin</a>
  <?php endif; ?>
  <a href="/logout.php" class="btn btn--danger">Выйти</a>
</nav>

<div class="settings-center">

  <?php foreach ($errors  as $e): ?>
    <div class="settings-alert settings-alert--err">✕ <?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
  <?php foreach ($success as $s): ?>
    <div class="settings-alert settings-alert--ok">✓ <?= htmlspecialchars($s) ?></div>
  <?php endforeach; ?>

  <!-- Profile -->
  <section class="scard" id="profile">
    <div class="scard__head">
      <div class="scard__icon">👤</div>
      <div>
        <div class="scard__title">Профиль</div>
        <div class="scard__sub">Имя пользователя для входа в систему</div>
      </div>
    </div>
    <div class="s-avatar">
      <div class="s-avatar__circle"><?= mb_strtoupper(mb_substr($user['username'], 0, 1)) ?></div>
      <div>
        <div class="s-avatar__name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="s-avatar__role"><?= $user['role'] === 'admin' ? '⭐ Администратор' : '👤 Пользователь' ?></div>
        <?php if ($urow): ?>
          <div class="s-avatar__since">С нами с <?= date('d.m.Y', strtotime($urow['created_at'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="change_username">
      <div class="field">
        <label class="field__label">Имя пользователя</label>
        <input class="input" type="text" name="username"
               value="<?= htmlspecialchars($user['username']) ?>"
               minlength="3" maxlength="32" required autocomplete="username">
        <div class="field__hint">Только a–z, 0–9 и _. От 3 до 32 символов.</div>
      </div>
      <button class="btn btn--warm" type="submit">✦ Сохранить имя</button>
    </form>
  </section>

  <!-- Password -->
  <section class="scard" id="password">
    <div class="scard__head">
      <div class="scard__icon">🔒</div>
      <div>
        <div class="scard__title">Смена пароля</div>
        <div class="scard__sub">Используйте надёжный пароль от 6 символов</div>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="field">
        <label class="field__label">Текущий пароль</label>
        <input class="input" type="password" name="current_password" required
               autocomplete="current-password" placeholder="••••••">
      </div>
      <div class="field">
        <label class="field__label">Новый пароль</label>
        <input class="input" type="password" name="new_password" required
               minlength="6" autocomplete="new-password" placeholder="Минимум 6 символов">
      </div>
      <div class="field">
        <label class="field__label">Подтверждение нового пароля</label>
        <input class="input" type="password" name="confirm_password" required
               minlength="6" autocomplete="new-password" placeholder="Повторите пароль">
      </div>
      <button class="btn btn--warm" type="submit">🔒 Изменить пароль</button>
    </form>
  </section>

  <!-- Stats -->
  <section class="scard" id="stats">
    <div class="scard__head">
      <div class="scard__icon">📊</div>
      <div>
        <div class="scard__title">Статистика аккаунта</div>
        <div class="scard__sub">Общая информация о вашем пространстве</div>
      </div>
    </div>
    <div class="s-stats">
      <div class="s-stat">
        <div class="s-stat__val"><?= (int)$wstats['cnt'] ?></div>
        <div class="s-stat__lbl">Виджетов</div>
      </div>
      <div class="s-stat">
        <div class="s-stat__val"><?= $wstats['last'] ? date('d.m', strtotime($wstats['last'])) : '—' ?></div>
        <div class="s-stat__lbl">Последнее изменение</div>
      </div>
      <div class="s-stat">
        <div class="s-stat__val"><?= $urow ? date('Y', strtotime($urow['created_at'])) : '—' ?></div>
        <div class="s-stat__lbl">Год регистрации</div>
      </div>
      <div class="s-stat">
        <div class="s-stat__val"><?= $user['role'] === 'admin' ? '⭐' : '✦' ?></div>
        <div class="s-stat__lbl"><?= $user['role'] === 'admin' ? 'Admin' : 'User' ?></div>
      </div>
    </div>
  </section>

  <!-- Danger zone -->
  <section class="scard scard--danger" id="danger">
    <div class="scard__head">
      <div class="scard__icon">⚠</div>
      <div>
        <div class="scard__title">Опасная зона</div>
        <div class="scard__sub">Необратимые действия с данными аккаунта</div>
      </div>
    </div>
    <div class="s-danger-row">
      <div>
        <div class="s-danger-row__title">Удалить аккаунт</div>
        <div class="s-danger-row__sub">Аккаунт и все виджеты будут удалены без возможности восстановления</div>
      </div>
      <button class="btn btn--danger" type="button"
              onclick="document.getElementById('delete-account-form').classList.toggle('s-delete-form--open')">
        🗑 Удалить аккаунт
      </button>
    </div>

    <form method="post" id="delete-account-form" class="s-delete-form"
          onsubmit="return confirm('Удалить аккаунт? Это нельзя отменить.')">
      <input type="hidden" name="csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="delete_account">
      <div class="field">
        <label class="field__label">Введите пароль для подтверждения</label>
        <input class="input" type="password" name="confirm_password_delete"
               required placeholder="Ваш текущий пароль" autocomplete="current-password">
      </div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn--danger" type="submit">Подтвердить удаление</button>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('delete-account-form').classList.remove('s-delete-form--open')">
          Отмена
        </button>
      </div>
    </form>
  </section>

</div>

<!-- Footer -->
<footer class="about-footer">
  <div class="about-footer__inner">
    <div>
      <div class="about-footer__brand">
        <div class="about-footer__logo">✦</div>
        <span class="about-footer__name">Point of <em>Creation</em></span>
      </div>
      <div class="about-footer__copy">© 2026 Point of Creation — личное пространство для глубокой работы.</div>
    </div>
    <nav class="about-footer__links">
      <a href="/" class="about-footer__link">Дашборд</a>
      <a href="/about.php" class="about-footer__link">О проекте</a>
    </nav>
  </div>
</footer>

<script>
(function(){
  var t = localStorage.getItem('poc-theme') || 'light';
  var b = document.getElementById('theme-toggle');
  if (b) b.textContent = t === 'dark' ? '☀️' : '🌙';
})();
function toggleTheme(){
  var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('poc-theme', t);
  var b = document.getElementById('theme-toggle');
  if (b) b.textContent = t === 'dark' ? '☀️' : '🌙';
}
</script>

<?php layout_end(); ?>
