<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/templates/layout.php';
require_admin();

$db = get_db();

$users = $db->query("
    SELECT u.id, u.username, u.role, u.created_at,
           COUNT(w.id) AS widget_count
    FROM users u
    LEFT JOIN widgets w ON w.user_id = u.id
    GROUP BY u.id ORDER BY u.created_at DESC
")->fetchAll();

$totals = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users)                    AS total_users,
        (SELECT COUNT(*) FROM widgets)                  AS total_widgets,
        (SELECT COUNT(*) FROM users WHERE role='admin') AS total_admins
")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $tid = (int)($_POST['delete_user'] ?? 0);
    if ($tid > 0 && $tid !== (int)current_user()['id']) {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$tid]);
        header('Location: /admin.php?msg=deleted'); exit;
    }
}

$flash_map = ['deleted' => '✓ Пользователь удалён.'];
$flash     = $flash_map[$_GET['msg'] ?? ''] ?? '';

layout_start('Администратор', ['body_class' => 'admin-page']);
?>

<nav class="navbar">
  <a href="/" class="logo">
    <div class="logo__mark"><?= icon('sparkles', '', 16) ?></div>
    <span class="logo__text">Point of <em>Creation</em></span>
  </a>
  <span class="btn btn--admin" style="cursor:default">⚙ Панель администратора</span>
  <div class="nav-spacer"></div>
  <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Сменить тему"><?= icon('moon', 'icon--theme-moon', 16) ?><?= icon('sun', 'icon--theme-sun', 16) ?></button>
  <a href="/" class="btn btn--ghost"><?= icon('arrow-left', '', 14) ?> Дашборд</a>
  <a href="/logout.php" class="btn btn--danger"><?= icon('log-out', '', 14) ?> Выйти</a>
</nav>

<div class="admin-content">

  <?php if ($flash): ?>
    <div class="alert alert--success" style="margin-bottom:1.25rem"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div style="margin-bottom:2rem">
    <h1 class="font-display" style="font-size:1.875rem;font-weight:700;color:var(--text);letter-spacing:-.02em">Панель администратора</h1>
    <p style="color:var(--text3);margin-top:.25rem">Point of Creation · Управление пользователями</p>
  </div>

  <div class="admin-stats">
    <?php foreach ([
      ['👥','Пользователей',$totals['total_users'],'rgba(245,158,11,.12)','var(--amber)'],
      ['📦','Виджетов',     $totals['total_widgets'],'rgba(251,146,60,.1)','var(--orange)'],
      ['⚙', 'Админов',     $totals['total_admins'],'rgba(139,92,246,.1)','#8b5cf6'],
    ] as [$icon,$label,$val,$bg,$color]): ?>
      <div class="stat-card">
        <div class="stat-card__icon" style="background:<?= $bg ?>"><?= $icon ?></div>
        <div class="stat-card__value" style="color:<?= $color ?>"><?= (int)$val ?></div>
        <div class="stat-card__label"><?= $label ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="admin-table-card">
    <div class="admin-table-card__head">
      <h2 class="font-display" style="font-size:1.125rem;font-weight:700;color:var(--text)">Пользователи</h2>
      <span class="topbar__badge"><?= count($users) ?> акк.</span>
    </div>
    <table class="admin-table">
      <thead><tr>
        <th>#</th><th>Пользователь</th><th>Роль</th><th>Виджетов</th><th>Зарегистрирован</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($users as $u):
          $isSelf = ((int)$u['id'] === (int)current_user()['id']); ?>
          <tr>
            <td style="color:var(--text3);font-size:.8125rem"><?= $u['id'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:.625rem">
                <div class="user-avatar"><?= strtoupper(mb_substr($u['username'],0,1)) ?></div>
                <div>
                  <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($u['username']) ?></div>
                  <?php if ($isSelf): ?><div style="font-size:.7rem;color:var(--amber);font-weight:600">это вы</div><?php endif; ?>
                </div>
              </div>
            </td>
            <td><span class="role-badge role-badge--<?= $u['role'] ?>"><?= $u['role']==='admin'?'⚙ admin':'user' ?></span></td>
            <td style="font-weight:700;color:var(--amber)"><?= (int)$u['widget_count'] ?></td>
            <td style="color:var(--text2);font-size:.8125rem"><?= date('d.m.Y H:i', strtotime($u['created_at'])) ?></td>
            <td>
              <?php if (!$isSelf): ?>
                <form method="POST" onsubmit="return confirm('Удалить «<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>»?')">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="delete_user" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn--danger">Удалить</button>
                </form>
              <?php else: ?><span style="font-size:.75rem;color:var(--text3)">нельзя</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$users): ?><div style="padding:3rem;text-align:center;color:var(--text3)">Нет пользователей</div><?php endif; ?>
  </div>

  <div class="admin-footer-note">
    💡 При удалении пользователя все его виджеты удаляются автоматически. Вы не можете удалить себя.
  </div>
</div>

<script>
function toggleTheme(){var t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('poc-theme',t);var b=document.getElementById('theme-toggle');if(b)b.textContent=t==='dark'?'☀️':'🌙';}
</script>

<?php layout_end(); ?>
