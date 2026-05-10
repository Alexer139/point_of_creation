<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/core/billing_core.php';
require_once __DIR__ . '/templates/layout.php';
require_admin();

$db = get_db();

// ── Обработка POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {

  $action = $_POST['admin_action'] ?? '';

  if ($action === 'soft_delete') {
    $tid = (int) ($_POST['user_id'] ?? 0);
    if ($tid > 0 && $tid !== (int) current_user()['id']) {
      $db->prepare("UPDATE `users` SET `deleted_at`=NOW() WHERE `id`=? AND `deleted_at` IS NULL")->execute([$tid]);
    }
    header('Location: /admin.php?msg=deleted');
    exit;
  }

  if ($action === 'restore') {
    $rid = (int) ($_POST['user_id'] ?? 0);
    if ($rid > 0) {
      $db->prepare("UPDATE `users` SET `deleted_at`=NULL WHERE `id`=?")->execute([$rid]);
    }
    header('Location: /admin.php?tab=deleted&msg=restored');
    exit;
  }

  if ($action === 'hard_delete') {
    $hid = (int) ($_POST['user_id'] ?? 0);
    if ($hid > 0 && $hid !== (int) current_user()['id']) {
      $db->prepare("DELETE FROM `users` WHERE `id`=? AND `deleted_at` IS NOT NULL")->execute([$hid]);
    }
    header('Location: /admin.php?tab=deleted&msg=hard_deleted');
    exit;
  }

  if ($action === 'topup') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    if ($uid > 0 && $amount > 0) {
      admin_topup_user($uid, $amount);
    }
    header('Location: /admin.php?msg=topped_up');
    exit;
  }

  if ($action === 'update_plan') {
    $plan_id = (int) ($_POST['plan_id'] ?? 0);
    if ($plan_id > 0) {
      admin_update_plan($plan_id, [
        'name' => trim($_POST['plan_name'] ?? ''),
        'price' => (float) ($_POST['price'] ?? 0),
        'duration_days' => (int) ($_POST['duration_days'] ?? 30),
        'max_dashboards' => (int) ($_POST['max_dashboards'] ?? 3),
        'max_pages' => (int) ($_POST['max_pages'] ?? 5),
        'max_members' => (int) ($_POST['max_members'] ?? 1),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
      ]);
    }
    header('Location: /admin.php?tab=plans&msg=plan_updated');
    exit;
  }
}

// ── Параметры ─────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'active';
$search = trim($_GET['q'] ?? '');
$role_f = $_GET['role'] ?? '';
$plan_f = $_GET['plan'] ?? '';
$per_page = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// ── Построить WHERE ───────────────────────────────────────────
function build_where(string $tab, string $search, string $role_f): array
{
  $where = $tab === 'deleted' ? ['u.deleted_at IS NOT NULL'] : ['u.deleted_at IS NULL'];
  $params = [];
  if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
  }
  if ($role_f !== '') {
    $where[] = 'u.role = ?';
    $params[] = $role_f;
  }
  return ['WHERE ' . implode(' AND ', $where), $params];
}

[$where_sql, $where_params] = build_where($tab, $search, $role_f);

// ── Подсчёт ───────────────────────────────────────────────────
$count_stmt = $db->prepare("SELECT COUNT(DISTINCT u.id) FROM `users` u $where_sql");
$count_stmt->execute($where_params);
$total = (int) $count_stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $per_page));

// ── Пользователи ──────────────────────────────────────────────
$users_stmt = $db->prepare("
    SELECT u.id, u.username, u.email, u.role, u.created_at, u.deleted_at,
           COUNT(DISTINCT d.id)  AS dashboard_count,
           COUNT(DISTINCT w.id)  AS widget_count,
           wl.balance,
           p.name AS plan_name, p.slug AS plan_slug, s.expires_at
    FROM `users` u
    LEFT JOIN `dashboards` d    ON d.owner_id = u.id
    LEFT JOIN `pages` pg        ON pg.dashboard_id = d.id
    LEFT JOIN `widgets` w       ON w.page_id = pg.id
    LEFT JOIN `wallets` wl      ON wl.user_id = u.id
    LEFT JOIN `subscriptions` s ON s.user_id = u.id AND s.status = 'active' AND s.expires_at > NOW()
    LEFT JOIN `plans` p         ON p.id = s.plan_id
    $where_sql
    GROUP BY u.id, u.username, u.email, u.role, u.created_at, u.deleted_at, wl.balance, p.name, p.slug, s.expires_at
    ORDER BY u.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$users_stmt->execute($where_params);
$users = $users_stmt->fetchAll();

// ── Статистика ────────────────────────────────────────────────
$totals = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL)                  AS total_users,
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL)              AS total_deleted,
        (SELECT COUNT(*) FROM dashboards)                                       AS total_dashboards,
        (SELECT COUNT(*) FROM widgets)                                          AS total_widgets,
        (SELECT COUNT(*) FROM users WHERE role='admin' AND deleted_at IS NULL) AS total_admins,
        (SELECT COALESCE(SUM(balance),0) FROM wallets)                         AS total_balance
")->fetch();

// ── Планы ─────────────────────────────────────────────────────
$plans = get_all_plans();

$flash_map = [
  'deleted' => '✓ Пользователь деактивирован.',
  'restored' => '✓ Пользователь восстановлен.',
  'hard_deleted' => '✓ Удалён безвозвратно.',
  'topped_up' => '✓ Счёт пополнен.',
  'plan_updated' => '✓ Тариф обновлён.',
];
$flash = $flash_map[$_GET['msg'] ?? ''] ?? '';

layout_start('Администратор', ['body_class' => 'admin-page']);
?>

<?php $navbar_active = 'admin';
require __DIR__ . '/templates/navbar.php'; ?>

<div class="admin-content">

  <?php if ($flash): ?>
    <div class="alert alert--success" style="margin-bottom:1.25rem"><?= $flash ?></div>
  <?php endif; ?>

  <div style="margin-bottom:1.5rem">
    <h1 class="font-display" style="font-size:1.75rem;font-weight:700;color:var(--text);letter-spacing:-.02em">Панель
      администратора</h1>
  </div>

  <!-- Статистика -->
  <div class="admin-stats">
    <?php foreach ([
      [icon('user', '', 20), 'Активных', $totals['total_users'], 'rgba(245,158,11,.12)', 'var(--amber)'],
      [icon('layout-dashboard', '', 20), 'Дашбордов', $totals['total_dashboards'], 'rgba(251,146,60,.1)', 'var(--orange)'],
      [icon('layout-grid', '', 20), 'Виджетов', $totals['total_widgets'], 'rgba(99,102,241,.1)', '#6366f1'],
      [icon('star', '', 20), 'Админов', $totals['total_admins'], 'rgba(139,92,246,.1)', '#8b5cf6'],
      [icon('trash', '', 20), 'Деактив.', $totals['total_deleted'], 'rgba(239,68,68,.1)', '#ef4444'],
      [icon('hash', '', 20), 'Баланс (₽)', number_format($totals['total_balance'], 0, '.', ','), 'rgba(16,185,129,.1)', '#10b981'],
    ] as [$ico, $lbl, $val, $bg, $clr]): ?>
      <div class="stat-card">
        <div class="stat-card__icon" style="background:<?= $bg ?>"><?= $ico ?></div>
        <div class="stat-card__value" style="color:<?= $clr ?>"><?= $val ?></div>
        <div class="stat-card__label"><?= $lbl ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Вкладки -->
  <div class="admin-tabs">
    <a href="/admin.php?tab=active"
      class="admin-tab <?= $tab === 'active' ? 'admin-tab--active' : '' ?>"><?= icon('users', '', 14) ?> Активные <span
        class="admin-tab__count"><?= $tab === 'active' ? $total : '' ?></span></a>
    <a href="/admin.php?tab=deleted"
      class="admin-tab <?= $tab === 'deleted' ? 'admin-tab--active' : '' ?>"><?= icon('trash', '', 14) ?>
      Деактивированные
      <?php if ($tab === 'deleted' && $total > 0): ?><span
          class="admin-tab__count admin-tab__count--danger"><?= $total ?></span><?php endif; ?></a>
    <a href="/admin.php?tab=plans"
      class="admin-tab <?= $tab === 'plans' ? 'admin-tab--active' : '' ?>"><?= icon('zap', '', 14) ?> Тарифы</a>
  </div>

  <?php if ($tab === 'plans'): ?>
    <!-- ══ РЕДАКТОР ТАРИФОВ ══════════════════════════════════════ -->
    <div class="admin-table-card">
      <div class="admin-table-card__head">
        <h2 style="font-size:1.1rem;font-weight:700;color:var(--text)">Управление тарифами</h2>
        <span style="font-size:.8rem;color:var(--text-3)">-1 = безлимит</span>
      </div>
      <div class="plans-editor">
        <?php foreach ($plans as $plan): ?>
          <form method="POST" class="plan-edit-card">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="admin_action" value="update_plan">
            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">

            <div class="plan-edit-card__head">
              <span class="plan-edit-card__slug"><?= htmlspecialchars($plan['slug']) ?></span>
              <label class="plan-edit-card__active">
                <input type="checkbox" name="is_active" <?= $plan['is_active'] ? 'checked' : '' ?>> Активен
              </label>
            </div>

            <div class="plan-edit-grid">
              <div class="field">
                <label class="field__label">Название</label>
                <input class="input" type="text" name="plan_name" value="<?= htmlspecialchars($plan['name']) ?>" required>
              </div>
              <div class="field">
                <label class="field__label">Цена (₽)</label>
                <input class="input" type="number" name="price" value="<?= $plan['price'] ?>" min="0" step="1">
              </div>
              <div class="field">
                <label class="field__label">Дней</label>
                <input class="input" type="number" name="duration_days" value="<?= $plan['duration_days'] ?>" min="1">
              </div>
              <div class="field">
                <label class="field__label">Дашбордов</label>
                <input class="input" type="number" name="max_dashboards" value="<?= $plan['max_dashboards'] ?>">
              </div>
              <div class="field">
                <label class="field__label">Страниц</label>
                <input class="input" type="number" name="max_pages" value="<?= $plan['max_pages'] ?>">
              </div>
              <div class="field">
                <label class="field__label">Участников</label>
                <input class="input" type="number" name="max_members" value="<?= $plan['max_members'] ?>">
              </div>
            </div>

            <button class="btn btn--warm btn--sm" type="submit"><?= icon('save', '', 13) ?> Сохранить</button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

  <?php else: ?>
    <!-- ══ ПОЛЬЗОВАТЕЛИ ══════════════════════════════════════════ -->

    <!-- Поиск и фильтры -->
    <form method="GET" class="admin-filters">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      <div class="admin-search">
        <?= icon('search', '', 15) ?>
        <input class="input admin-search__input" type="text" name="q" value="<?= htmlspecialchars($search) ?>"
          placeholder="Поиск по логину или email...">
      </div>
      <?php if ($tab === 'active'): ?>
        <select class="input input--select" name="role">
          <option value="">Все роли</option>
          <option value="user" <?= $role_f === 'user' ? 'selected' : '' ?>>user</option>
          <option value="admin" <?= $role_f === 'admin' ? 'selected' : '' ?>>admin</option>
        </select>
      <?php endif; ?>
      <button class="btn btn--warm btn--sm" type="submit"><?= icon('filter', '', 13) ?> Применить</button>
      <?php if ($search || $role_f): ?>
        <a href="/admin.php?tab=<?= $tab ?>" class="btn btn--ghost btn--sm">Сбросить</a>
      <?php endif; ?>
    </form>

    <div class="admin-table-card">
      <div class="admin-table-card__head">
        <h2 style="font-size:1.1rem;font-weight:700;color:var(--text)">
          <?= $tab === 'deleted' ? 'Деактивированные' : 'Активные' ?> пользователи
        </h2>
        <span class="topbar__badge"><?= $total ?> / <?= $per_page ?> на стр.</span>
      </div>

      <table class="admin-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Пользователь</th>
            <th>Email</th>
            <th>Тариф</th>
            <th>Баланс</th>
            <th>Дашбордов</th>
            <th>Виджетов</th>
            <th><?= $tab === 'deleted' ? 'Деактивирован' : 'Зарегистрирован' ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $isSelf = ((int) $u['id'] === (int) current_user()['id']); ?>
            <tr class="<?= $tab === 'deleted' ? 'admin-table__row--deleted' : '' ?>">
              <td style="color:var(--text3);font-size:.8125rem"><?= $u['id'] ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                  <div class="user-avatar <?= $tab === 'deleted' ? 'user-avatar--muted' : '' ?>">
                    <?= strtoupper(mb_substr($u['username'], 0, 1)) ?>
                  </div>
                  <div>
                    <div
                      style="font-weight:600;color:var(--text);<?= $tab === 'deleted' ? 'text-decoration:line-through' : '' ?>">
                      <?= htmlspecialchars($u['username']) ?>
                    </div>
                    <?php if ($isSelf): ?>
                      <div style="font-size:.7rem;color:var(--amber);font-weight:600">это вы</div><?php endif; ?>
                    <span
                      class="role-badge role-badge--<?= $u['role'] ?>"><?= $u['role'] === 'admin' ? icon('star', '', 11) . ' admin' : 'user' ?></span>
                  </div>
                </div>
              </td>
              <td style="font-size:.8125rem;color:var(--text2)"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
              <td>
                <?php $ps = $u['plan_slug'] ?? 'free'; ?>
                <span class="plan-badge plan-badge--<?= $ps ?>"><?= htmlspecialchars($u['plan_name'] ?? 'Free') ?></span>
              </td>
              <td style="font-size:.8125rem;color:var(--text2)">
                <?= number_format((float) ($u['balance'] ?? 0), 2, '.', ',') ?> ₽
              </td>
              <td style="font-weight:600;color:var(--text2)"><?= (int) $u['dashboard_count'] ?></td>
              <td style="font-weight:700;color:var(--amber)"><?= (int) $u['widget_count'] ?></td>
              <td><span class="fmt-date"
                  data-utc="<?= htmlspecialchars($tab === 'deleted' ? ($u['deleted_at'] ?? '') : $u['created_at']) ?>"></span>
              </td>
              <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                  <?php if ($tab === 'deleted'): ?>
                    <form method="POST" onsubmit="return confirm('Восстановить?')">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="admin_action" value="restore">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button class="btn btn--success btn--xs"><?= icon('refresh-cw', '', 12) ?></button>
                    </form>
                    <form method="POST" onsubmit="return confirm('УДАЛИТЬ НАВСЕГДА?')">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="admin_action" value="hard_delete">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button class="btn btn--danger btn--xs"><?= icon('trash', '', 12) ?></button>
                    </form>
                  <?php elseif (!$isSelf): ?>
                    <!-- Пополнить счёт -->
                    <button class="btn btn--ghost btn--xs"
                      onclick="openTopup(<?= $u['id'] ?>, '<?= addslashes($u['username']) ?>')"
                      title="Пополнить счёт"><?= icon('plus', '', 12) ?> ₽</button>
                    <!-- Деактивировать -->
                    <form method="POST" onsubmit="return confirm('Деактивировать?')">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="admin_action" value="soft_delete">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button class="btn btn--danger btn--xs"
                        title="Деактивировать"><?= icon('user-minus', '', 12) ?></button>
                    </form>
                  <?php else: ?>
                    <span style="font-size:.7rem;color:var(--text3)">вы</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?>
            <tr>
              <td colspan="9" style="text-align:center;padding:2rem;color:var(--text3)">Ничего не найдено</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Пагинация -->
      <?php if ($pages > 1): ?>
        <div class="admin-pagination">
          <?php for ($i = 1; $i <= $pages; $i++):
            $url = "/admin.php?tab={$tab}&page={$i}" . ($search ? "&q=" . urlencode($search) : "") . ($role_f ? "&role={$role_f}" : "");
            ?>
            <a href="<?= $url ?>" class="admin-page-btn <?= $i === $page ? 'admin-page-btn--active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="admin-footer-note">
      <?= $tab === 'deleted'
        ? icon('alert-triangle', '', 14) . ' «Удалить навсегда» необратимо — CASCADE удалит все данные.'
        : icon('info', '', 14) . ' Деактивация блокирует вход, данные сохраняются.' ?>
    </div>
  <?php endif; ?>

</div>

<!-- Modal: пополнение счёта -->
<div class="overlay" id="overlay-topup">
  <div class="modal modal--sm">
    <div class="modal__head">
      <span class="modal__title"><?= icon('hash', '', 16) ?> Пополнить счёт</span>
      <button class="modal__close" onclick="closeTopup()"><?= icon('x', '', 16) ?></button>
    </div>
    <div class="modal__body">
      <p style="font-size:.875rem;color:var(--text-2);margin-bottom:.75rem">
        Пользователь: <strong id="topup-username"></strong>
      </p>
      <div class="field">
        <label class="field__label">Сумма (₽)</label>
        <input class="input" type="number" id="topup-admin-amount" min="1" max="100000" placeholder="Например: 299">
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeTopup()">Отмена</button>
      <button class="btn btn--warm" onclick="submitTopup()"><?= icon('plus', '', 14) ?> Пополнить</button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<style>
  .admin-filters {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
  }

  .admin-search {
    position: relative;
    flex: 1;
    min-width: 200px;
  }

  .admin-search svg {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-3);
    pointer-events: none;
  }

  .admin-search__input {
    padding-left: 34px !important;
  }

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

  .admin-pagination {
    display: flex;
    gap: .35rem;
    padding: 1rem;
    justify-content: center;
    flex-wrap: wrap;
  }

  .admin-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    color: var(--text-2);
    font-size: .82rem;
    text-decoration: none;
    transition: all .12s;
  }

  .admin-page-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
  }

  .admin-page-btn--active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
    font-weight: 700;
  }

  .plan-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 700;
  }

  .plan-badge--free {
    background: var(--surface-3);
    color: var(--text-2);
  }

  .plan-badge--level1 {
    background: rgba(99, 102, 241, .15);
    color: #6366f1;
  }

  .plan-badge--level2 {
    background: rgba(245, 158, 11, .15);
    color: var(--amber);
  }

  .btn--success {
    background: rgba(16, 185, 129, .15);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, .3);
  }

  .btn--success:hover {
    background: rgba(16, 185, 129, .25);
  }

  .admin-table__row--deleted td {
    background: rgba(239, 68, 68, .03);
  }

  .user-avatar--muted {
    background: var(--surface3) !important;
    color: var(--text3) !important;
  }

  /* Plans editor */
  .plans-editor {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    padding: 1rem;
  }

  .plan-edit-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
  }

  .plan-edit-card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .plan-edit-card__slug {
    font-family: monospace;
    font-size: .85rem;
    font-weight: 700;
    color: var(--accent);
  }

  .plan-edit-card__active {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-size: .82rem;
    color: var(--text-2);
    cursor: pointer;
  }

  .plan-edit-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .5rem;
  }
</style>

<script>
  const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  let topupUserId = null;

  function openTopup(uid, username) {
    topupUserId = uid;
    document.getElementById('topup-username').textContent = username;
    document.getElementById('topup-admin-amount').value = '';
    document.getElementById('overlay-topup').classList.add('overlay--open');
    setTimeout(() => document.getElementById('topup-admin-amount').focus(), 80);
  }
  function closeTopup() {
    document.getElementById('overlay-topup').classList.remove('overlay--open');
  }

  function submitTopup() {
    const amount = parseFloat(document.getElementById('topup-admin-amount').value);
    if (!amount || amount <= 0) {
      document.getElementById('topup-admin-amount').focus();
      return;
    }
    closeTopup();
    // Только форма — без fetch, чтобы не пополнять счёт администратора
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '/admin.php';
    [['csrf', CSRF_TOKEN], ['admin_action', 'topup'], ['user_id', topupUserId], ['amount', amount]].forEach(([n, v]) => {
      const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.fmt-date').forEach(function (el) {
      var utc = el.getAttribute('data-utc');
      if (!utc) return;
      var d = new Date(utc.replace(' ', 'T') + 'Z');
      el.textContent = d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    });
    document.querySelectorAll('.overlay').forEach(function (ol) {
      ol.addEventListener('click', function (e) { if (e.target === ol) ol.classList.remove('overlay--open'); });
    });
  });
</script>

<?php layout_end(); ?>