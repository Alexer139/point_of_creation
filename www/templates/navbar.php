<?php
/**
 * templates/navbar.php
 * Point of Creation — единая шапка для всех страниц
 *
 * Переменные (опционально):
 *   $navbar_active — строка с активной страницей: 'settings', 'admin', 'about'
 */

if (!isset($navbar_active)) $navbar_active = '';
$_nav_user = current_user();
?>
<nav class="navbar">
  <a href="/" class="logo">
    <div class="logo__mark"><?= icon('sparkles', '', 16) ?></div>
    <span class="logo__text">Point of <em>Creation</em></span>
  </a>

  <div class="nav-spacer"></div>

  <?php if (is_logged_in()): ?>

    <!-- Уведомления -->
    <div class="notif-bell" id="notif-bell">
      <button class="notif-bell__btn" id="notif-btn" onclick="toggleNotifPanel()" title="Уведомления">
        <?= icon('bell', '', 18) ?>
        <span class="notif-bell__badge" id="notif-badge" style="display:none">0</span>
      </button>
      <div class="notif-panel" id="notif-panel">
        <div class="notif-panel__head">
          <span class="notif-panel__title"><?= icon('bell','',15) ?> Уведомления</span>
          <button class="notif-panel__read-all" onclick="markAllRead()">Прочитать все</button>
        </div>
        <div class="notif-list" id="notif-list">
          <div class="notif-empty">Загрузка...</div>
        </div>
      </div>
    </div>


    <!-- Подписка -->
    <?php if (is_logged_in()):
      require_once __DIR__ . '/../core/billing_core.php';
      $_nav_sub = get_active_subscription((int)current_user()['id']);
    ?>
    <a href="/billing.php"
       class="nav-billing <?= $navbar_active === 'billing' ? 'nav-billing--active' : '' ?>"
       title="Подписка и баланс счёта">
      <?= icon('zap', '', 14) ?>
      <span class="nav-billing__plan nav-billing__plan--<?= $_nav_sub['slug'] ?>"><?= htmlspecialchars($_nav_sub['plan_name']) ?></span>
    </a>
    <?php endif; ?>

    <!-- Профиль -->
    <a href="/settings.php"
       class="nav-user <?= $navbar_active === 'settings' ? 'nav-user--active' : '' ?>"
       title="Настройки профиля">
      <?= icon('user', '', 14) ?> <?= htmlspecialchars($_nav_user['username']) ?>
    </a>

  <?php endif; ?>

  <!-- Тема -->
  <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему">
    <?= icon('moon', 'icon--theme-moon', 16) ?><?= icon('sun', 'icon--theme-sun', 16) ?>
  </button>

  <!-- О проекте -->
  <a href="/about.php"
     class="btn btn--ghost <?= $navbar_active === 'about' ? 'btn--ghost-active' : '' ?>">
    О проекте
  </a>

  <?php if (is_logged_in()): ?>

    <?php if (is_admin()): ?>
      <a href="/admin.php"
         class="btn btn--admin <?= $navbar_active === 'admin' ? 'btn--admin-active' : '' ?>">
        <?= icon('settings', '', 14) ?> Admin
      </a>
    <?php endif; ?>

    <a href="/logout.php" class="btn btn--danger">
      <?= icon('log-out', '', 14) ?> Выйти
    </a>

  <?php else: ?>
    <a href="/login.php" class="btn btn--warm">Войти</a>
  <?php endif; ?>

</nav>
