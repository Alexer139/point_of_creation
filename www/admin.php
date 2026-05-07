<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';
require_admin();

$db = get_db();

// ── Обработка POST-действий ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {

  // Мягкое удаление (soft delete)
  $tid = (int) ($_POST['delete_user'] ?? 0);
  if ($tid > 0 && $tid !== (int) current_user()['id']) {
    $db->prepare("UPDATE `users` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL")
      ->execute([$tid]);
    header('Location: /admin.php?msg=deleted');
    exit;
  }

  // Восстановление пользователя
  $rid = (int) ($_POST['restore_user'] ?? 0);
  if ($rid > 0) {
    $db->prepare("UPDATE `users` SET `deleted_at` = NULL WHERE `id` = ?")
      ->execute([$rid]);
    header('Location: /admin.php?tab=deleted&msg=restored');
    exit;
  }

  // Окончательное удаление (hard delete — только для уже soft-deleted)
  $hid = (int) ($_POST['hard_delete_user'] ?? 0);
  if ($hid > 0 && $hid !== (int) current_user()['id']) {
    $db->prepare("DELETE FROM `users` WHERE `id` = ? AND `deleted_at` IS NOT NULL")
      ->execute([$hid]);
    header('Location: /admin.php?tab=deleted&msg=hard_deleted');
    exit;
  }
}

$tab = $_GET['tab'] ?? 'active';

// ── Активные пользователи (не удалённые) ─────────────────────
$users = $db->query("
    SELECT u.id, u.username, u.email, u.role, u.created_at,
           COUNT(DISTINCT d.id) AS dashboard_count,
           COUNT(DISTINCT w.id) AS widget_count
    FROM users u
    LEFT JOIN dashboards d ON d.owner_id = u.id
    LEFT JOIN pages p      ON p.dashboard_id = d.id
    LEFT JOIN widgets w    ON w.page_id = p.id
    WHERE u.deleted_at IS NULL
    GROUP BY u.id, u.username, u.email, u.role, u.created_at
    ORDER BY u.created_at DESC
")->fetchAll();

// ── Удалённые пользователи ────────────────────────────────────
$deleted_users = $db->query("
    SELECT u.id, u.username, u.email, u.role, u.created_at, u.deleted_at,
           COUNT(DISTINCT d.id) AS dashboard_count,
           COUNT(DISTINCT w.id) AS widget_count
    FROM users u
    LEFT JOIN dashboards d ON d.owner_id = u.id
    LEFT JOIN pages p      ON p.dashboard_id = d.id
    LEFT JOIN widgets w    ON w.page_id = p.id
    WHERE u.deleted_at IS NOT NULL
    GROUP BY u.id, u.username, u.email, u.role, u.created_at, u.deleted_at
    ORDER BY u.deleted_at DESC
")->fetchAll();

// ── Статистика ────────────────────────────────────────────────
$totals = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL)                  AS total_users,
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL)              AS total_deleted,
        (SELECT COUNT(*) FROM dashboards)                                       AS total_dashboards,
        (SELECT COUNT(*) FROM widgets)                                          AS total_widgets,
        (SELECT COUNT(*) FROM users WHERE role='admin' AND deleted_at IS NULL) AS total_admins
")->fetch();

$flash_map = [
  'deleted' => '&#10003; Пользователь деактивирован. Данные сохранены.',
  'restored' => '&#10003; Пользователь восстановлен и может войти снова.',
  'hard_deleted' => '&#10003; Пользователь и все его данные удалены безвозвратно.',
];
$flash = $flash_map[$_GET['msg'] ?? ''] ?? '';

layout_start('Администратор', ['body_class' => 'admin-page']);
?>

<nav class="navbar">
  <a href="/" class="logo">
    <div class="logo__mark"><?= icon('sparkles', '', 16) ?></div>
    <span class="logo__text">Point of <em>Creation</em></span>
  </a>
  <span class="btn btn--admin" style="cursor:default"><?= icon('settings', '', 15) ?> Панель администратора</span>
  <div class="nav-spacer"></div>
  <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему">
    <?= icon('moon', 'icon--theme-moon', 16) ?><?= icon('sun', 'icon--theme-sun', 16) ?>
  </button>
  <a href="/" class="btn btn--ghost"><?= icon('arrow-left', '', 14) ?> Дашборд</a>
  <a href="/logout.php" class="btn btn--danger"><?= icon('log-out', '', 14) ?> Выйти</a>
</nav>

<div class="admin-content">

  <?php if ($flash): ?>
    <div class="alert alert--success" style="margin-bottom:1.25rem"><?= $flash ?></div>
  <?php endif; ?>

  <div style="margin-bottom:2rem">
    <h1 class="font-display" style="font-size:1.875rem;font-weight:700;color:var(--text);letter-spacing:-.02em">Панель
      администратора</h1>
    <p style="color:var(--text3);margin-top:.25rem">Point of Creation &middot; Управление пользователями</p>
  </div>

  <!-- Статистика -->
  <div class="admin-stats">
    <?php foreach ([
      [icon('user', '', 20), 'Активных', $totals['total_users'], 'rgba(245,158,11,.12)', 'var(--amber)'],
      [icon('layout-dashboard', '', 20), 'Дашбордов', $totals['total_dashboards'], 'rgba(251,146,60,.1)', 'var(--orange)'],
      [icon('layout-grid', '', 20), 'Виджетов', $totals['total_widgets'], 'rgba(99,102,241,.1)', '#6366f1'],
      [icon('star', '', 20), 'Админов', $totals['total_admins'], 'rgba(139,92,246,.1)', '#8b5cf6'],
      [icon('trash', '', 20), 'Деактивирован', $totals['total_deleted'], 'rgba(239,68,68,.1)', '#ef4444'],
    ] as [$ico, $label, $val, $bg, $color]): ?>
      <div class="stat-card">
        <div class="stat-card__icon" style="background:<?= $bg ?>"><?= $ico ?></div>
        <div class="stat-card__value" style="color:<?= $color ?>"><?= (int) $val ?></div>
        <div class="stat-card__label"><?= $label ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Вкладки -->
  <div class="admin-tabs">
    <a href="/admin.php?tab=active" class="admin-tab <?= $tab === 'active' ? 'admin-tab--active' : '' ?>">
      <?= icon('users', '', 14) ?> Активные
      <span class="admin-tab__count"><?= count($users) ?></span>
    </a>
    <a href="/admin.php?tab=deleted" class="admin-tab <?= $tab === 'deleted' ? 'admin-tab--active' : '' ?>">
      <?= icon('trash', '', 14) ?> Деактивированные
      <?php if (count($deleted_users)): ?>
        <span class="admin-tab__count admin-tab__count--danger"><?= count($deleted_users) ?></span>
      <?php endif; ?>
    </a>
  </div>

  <?php if ($tab !== 'deleted'): ?>
    <!-- ══ Активные пользователи ══════════════════════════════ -->
    <div class="admin-table-card">
      <div class="admin-table-card__head">
        <h2 class="font-display" style="font-size:1.125rem;font-weight:700;color:var(--text)">Активные пользователи</h2>
        <span class="topbar__badge"><?= count($users) ?> акк.</span>
      </div>
      <table class="admin-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Пользователь</th>
            <th>Email</th>
            <th>Роль</th>
            <th>Дашбордов</th>
            <th>Виджетов</th>
            <th>Зарегистрирован</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $isSelf = ((int) $u['id'] === (int) current_user()['id']); ?>
            <tr>
              <td style="color:var(--text3);font-size:.8125rem"><?= $u['id'] ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:.625rem">
                  <div class="user-avatar"><?= strtoupper(mb_substr($u['username'], 0, 1)) ?></div>
                  <div>
                    <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($u['username']) ?></div>
                    <?php if ($isSelf): ?>
                      <div style="font-size:.7rem;color:var(--amber);font-weight:600">это вы</div><?php endif; ?>
                  </div>
                </div>
              </td>
              <td style="color:var(--text2);font-size:.8125rem"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
              <td>
                <span class="role-badge role-badge--<?= $u['role'] ?>">
                  <?= $u['role'] === 'admin' ? icon('star', '', 12) . ' admin' : 'user' ?>
                </span>
              </td>
              <td style="font-weight:600;color:var(--text2)"><?= (int) $u['dashboard_count'] ?></td>
              <td style="font-weight:700;color:var(--amber)"><?= (int) $u['widget_count'] ?></td>
              <td style="color:var(--text2);font-size:.8125rem"><span class="fmt-date"
                  data-utc="<?= htmlspecialchars($u['created_at']) ?>"></span></td>
              <td>
                <?php if (!$isSelf): ?>
                  <form method="POST"
                    onsubmit="return confirm('Деактивировать пользователя?\nДанные сохранятся, войти будет нельзя.')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="delete_user" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn--danger btn--sm">
                      <?= icon('user-minus', '', 13) ?> Деактивировать
                    </button>
                  </form>
                <?php else: ?>
                  <span style="font-size:.75rem;color:var(--text3)">нельзя</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?>
            <tr>
              <td colspan="8" style="text-align:center;padding:2rem;color:var(--text3)">Нет активных пользователей</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="admin-footer-note">
      <?= icon('info', '', 14) ?> Деактивация блокирует вход, но сохраняет все данные. Восстановить можно во вкладке
      «Деактивированные».
    </div>

  <?php else: ?>
    <!-- ══ Деактивированные пользователи ══════════════════════ -->
    <div class="admin-table-card">
      <div class="admin-table-card__head">
        <h2 class="font-display" style="font-size:1.125rem;font-weight:700;color:var(--text)">Деактивированные
          пользователи</h2>
        <span class="topbar__badge"><?= count($deleted_users) ?> акк.</span>
      </div>
      <table class="admin-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Пользователь</th>
            <th>Email</th>
            <th>Дашбордов</th>
            <th>Виджетов</th>
            <th>Деактивирован</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deleted_users as $u): ?>
            <tr class="admin-table__row--deleted">
              <td style="color:var(--text3);font-size:.8125rem"><?= $u['id'] ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:.625rem;opacity:.65">
                  <div class="user-avatar user-avatar--muted"><?= strtoupper(mb_substr($u['username'], 0, 1)) ?></div>
                  <div>
                    <div style="font-weight:600;color:var(--text);text-decoration:line-through">
                      <?= htmlspecialchars($u['username']) ?></div>
                    <div style="font-size:.7rem;color:#ef4444;font-weight:600">деактивирован</div>
                  </div>
                </div>
              </td>
              <td style="color:var(--text3);font-size:.8125rem"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
              <td style="color:var(--text3)"><?= (int) $u['dashboard_count'] ?></td>
              <td style="color:var(--text3)"><?= (int) $u['widget_count'] ?></td>
              <td style="color:#ef4444;font-size:.8125rem"><span class="fmt-date"
                  data-utc="<?= htmlspecialchars($u['deleted_at']) ?>"></span></td>
              <td>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                  <form method="POST" onsubmit="return confirm('Восстановить пользователя?\nОн снова сможет войти.')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="restore_user" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn--success btn--sm">
                      <?= icon('refresh-cw', '', 13) ?> Восстановить
                    </button>
                  </form>
                  <form method="POST"
                    onsubmit="return confirm('УДАЛИТЬ НАВСЕГДА?\nВсе дашборды, страницы и виджеты будут стёрты безвозвратно!')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="hard_delete_user" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn--danger btn--sm">
                      <?= icon('trash', '', 13) ?> Удалить навсегда
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$deleted_users): ?>
            <tr>
              <td colspan="7" style="text-align:center;padding:2rem;color:var(--text3)"><?= icon('check-circle', '', 16) ?>
                Деактивированных нет</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="admin-footer-note" style="color:#ef4444;border-color:rgba(239,68,68,.2);background:rgba(239,68,68,.04)">
      <?= icon('alert-triangle', '', 14) ?> «Удалить навсегда» — необратимо. Все данные пользователя уничтожаются через
      CASCADE.
    </div>
  <?php endif; ?>

</div>

<style>
  .admin-tabs {
    display: flex;
    gap: .5rem;
    margin-bottom: 1.25rem;
    border-bottom: 1px solid var(--border);
  }

  .admin-tab {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .5rem 1rem;
    font-size: .875rem;
    font-weight: 500;
    color: var(--text2);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color .15s, border-color .15s;
  }

  .admin-tab:hover {
    color: var(--text);
  }

  .admin-tab--active {
    color: var(--accent);
    border-bottom-color: var(--accent);
    font-weight: 600;
  }

  .admin-tab__count {
    font-size: .72rem;
    padding: 1px 6px;
    border-radius: 20px;
    background: var(--surface3);
    color: var(--text3);
    font-weight: 700;
  }

  .admin-tab__count--danger {
    background: rgba(239, 68, 68, .15);
    color: #ef4444;
  }

  .admin-table__row--deleted td {
    background: rgba(239, 68, 68, .03);
  }

  .user-avatar--muted {
    background: var(--surface3) !important;
    color: var(--text3) !important;
  }

  .btn--success {
    background: rgba(16, 185, 129, .15);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, .3);
  }

  .btn--success:hover {
    background: rgba(16, 185, 129, .25);
  }
</style>

<script>
  function toggleTheme() {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('poc-theme', t);
    document.querySelectorAll('.icon--theme-moon').forEach(function (el) { el.style.display = t === 'dark' ? 'none' : 'inline-block'; });
    document.querySelectorAll('.icon--theme-sun').forEach(function (el) { el.style.display = t === 'dark' ? 'inline-block' : 'none'; });
  }
  (function () {
    var t = document.documentElement.getAttribute('data-theme') || 'light';
    document.querySelectorAll('.icon--theme-moon').forEach(function (el) { el.style.display = t === 'dark' ? 'none' : 'inline-block'; });
    document.querySelectorAll('.icon--theme-sun').forEach(function (el) { el.style.display = t === 'dark' ? 'inline-block' : 'none'; });
  })();

  // Форматировать все даты из UTC в локальный timezone браузера
  document.querySelectorAll('.fmt-date').forEach(function (el) {
    var utc = el.getAttribute('data-utc');
    if (!utc) return;
    var d = new Date(utc.replace(' ', 'T') + 'Z');
    el.textContent = d.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  });
</script>

<?php layout_end(); ?>