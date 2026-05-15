<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/core/billing_core.php';
require_once __DIR__ . '/templates/layout.php';
require_auth();

$user_id = (int) current_user()['id'];
$info = get_billing_info_full($user_id);
$sub = $info['subscription'];
$pending = $info['pending'];
$wallet = $info['wallet'];
$plans = $info['plans'];
$limits = $info['limits'];
$txns = $info['transactions'];

$db = get_db();
$usage_dashboards = (int) $db->query("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`={$user_id}")->fetchColumn();

$locked_stmt = $db->prepare("
    SELECT le.`entity_id`, d.`name`
    FROM `locked_entities` le
    JOIN `dashboards` d ON d.`id` = le.`entity_id`
    WHERE le.`user_id` = ? AND le.`entity_type` = 'dashboard'
");
$locked_stmt->execute([$user_id]);
$locked_dashboards = $locked_stmt->fetchAll();

$locked_pages_stmt = $db->prepare("
    SELECT le.`entity_id`, p.`name`, p.`dashboard_id`
    FROM `locked_entities` le
    JOIN `pages` p ON p.`id` = le.`entity_id`
    WHERE le.`user_id` = ? AND le.`entity_type` = 'page'
");
$locked_pages_stmt->execute([$user_id]);
$locked_pages = $locked_pages_stmt->fetchAll();

$locked_ids = array_column($locked_dashboards, 'entity_id');
$active_dash_count = $usage_dashboards - count($locked_dashboards);
$needs_downgrade = !is_unlimited($limits['max_dashboards']) &&
  $active_dash_count > $limits['max_dashboards'];

$all_dashboards = $db->prepare("SELECT `id`, `name` FROM `dashboards` WHERE `owner_id` = ? ORDER BY `created_at` ASC");
$all_dashboards->execute([$user_id]);
$all_dashboards = $all_dashboards->fetchAll();

// Flash messages
$flash_map = [
  'payment_success' => '✓ Оплата прошла успешно! Счёт пополнен.',
  'payment_failed' => '✕ Оплата не прошла. Попробуйте ещё раз.',
  'payment_error' => '✕ Ошибка при обработке платежа.',
  'payment_pending' => '⏳ Платёж обрабатывается. Баланс обновится автоматически.',
];
$flash = $flash_map[$_GET['msg'] ?? ''] ?? '';

layout_start('Подписка', ['body_class' => 'billing-page']);
?>

<?php $navbar_active = 'billing';
require __DIR__ . '/templates/navbar.php'; ?>

<div class="billing-wrap">

  <?php if ($flash): ?>
    <div class="alert alert--<?= str_contains($flash, '✓') ? 'success' : 'error' ?>" style="margin-bottom:1.25rem">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>

  <?php if ($needs_downgrade): ?>
    <div class="downgrade-banner">
      <div class="downgrade-banner__icon"><?= icon('alert-triangle', '', 20) ?></div>
      <div class="downgrade-banner__body">
        <div class="downgrade-banner__title">Требуется выбор активных дашбордов</div>
        <div class="downgrade-banner__sub">
          Ваш тариф позволяет <?= $limits['max_dashboards'] ?> дашбордов, у вас активно <?= $active_dash_count ?>.
          Выберите какие оставить — остальные будут заморожены (данные сохранятся).
        </div>
      </div>
      <button class="btn btn--warm" onclick="openDowngradeModal()">
        <?= icon('settings', '', 14) ?> Выбрать дашборды
      </button>
    </div>
  <?php endif; ?>

  <?php if ($pending): ?>
    <div class="pending-banner">
      <div class="pending-banner__icon"><?= icon('clock', '', 18) ?></div>
      <div class="pending-banner__body">
        <div class="pending-banner__title">Запланирован переход на «<?= htmlspecialchars($pending['plan_name']) ?>»</div>
        <div class="pending-banner__sub">
          Активируется <?= (new DateTime($pending['started_at']))->format('d.m.Y') ?> — по окончании текущего периода.
        </div>
      </div>
      <button class="btn btn--ghost btn--sm" onclick="cancelPendingDowngrade()">
        <?= icon('x', '', 13) ?> Отменить
      </button>
    </div>
  <?php endif; ?>

  <div class="billing-grid">

    <!-- ══ ЛЕВАЯ КОЛОНКА ════════════════════════════════════════ -->
    <div class="billing-left">

      <!-- Текущий план -->
      <div class="bcard">
        <div class="bcard__head"><?= icon('zap', '', 18) ?> <span>Текущий тариф</span></div>
        <div class="plan-current">
          <div class="plan-current__name"><?= htmlspecialchars($sub['plan_name']) ?></div>
          <div class="plan-current__badge plan-current__badge--<?= $sub['slug'] ?>">
            <?= $sub['slug'] === 'free' ? 'Бесплатно' : number_format((float) $sub['price'], 0, '.', ' ') . ' ₽/мес' ?>
          </div>
          <?php if ($sub['slug'] !== 'free'): ?>
            <div class="plan-current__expires">
              До: <span class="fmt-date" data-utc="<?= htmlspecialchars($sub['expires_at']) ?>"></span>
            </div>
          <?php endif; ?>
        </div>
        <div class="usage-list">
          <div class="usage-item">
            <span class="usage-item__label"><?= icon('layout-dashboard', '', 14) ?> Дашборды</span>
            <span class="usage-item__val"><?= $usage_dashboards ?> /
              <?= is_unlimited($limits['max_dashboards']) ? '∞' : $limits['max_dashboards'] ?></span>
            <?php if (!is_unlimited($limits['max_dashboards'])): ?>
              <div class="usage-bar">
                <div class="usage-bar__fill"
                  style="width:<?= min(100, round($usage_dashboards / $limits['max_dashboards'] * 100)) ?>%"></div>
              </div>
            <?php endif; ?>
          </div>
          <div class="usage-item">
            <span class="usage-item__label"><?= icon('file', '', 14) ?> Страниц на дашборд</span>
            <span class="usage-item__val"><?= is_unlimited($limits['max_pages']) ? '∞' : $limits['max_pages'] ?></span>
          </div>
          <div class="usage-item">
            <span class="usage-item__label"><?= icon('users', '', 14) ?> Участников</span>
            <span
              class="usage-item__val"><?= is_unlimited($limits['max_members']) ? '∞' : $limits['max_members'] ?></span>
          </div>
        </div>
      </div>

      <!-- Кошелёк -->
      <div class="bcard">
        <div class="bcard__head"><?= icon('hash', '', 18) ?> <span>Счёт</span></div>
        <div class="wallet-balance">
          <div class="wallet-balance__amount"><?= number_format((float) $wallet['balance'], 2, '.', ' ') ?>
            <span>₽</span></div>
          <div class="wallet-balance__label">Доступно</div>
        </div>

        <?php $yukassa_enabled = !empty(getenv('YUKASSA_SHOP_ID')) && !empty(getenv('YUKASSA_SECRET')); ?>

        <div class="wallet-topup">
          <input type="number" class="input" id="topup-amount" placeholder="Сумма пополнения" min="1" max="100000"
            step="1">
          <button class="btn btn--warm" onclick="openTopupWidget()">
            <?= icon($yukassa_enabled ? 'credit-card' : 'plus', '', 14) ?>
            <?= $yukassa_enabled ? 'Оплатить' : 'Пополнить' ?>
          </button>
        </div>
        <?php if (!$yukassa_enabled): ?>
          <div class="demo-notice">
            <?= icon('info', '', 12) ?> Демо-режим — задайте YUKASSA_SHOP_ID и YUKASSA_SECRET для реальных платежей.
          </div>
        <?php endif; ?>



        <div class="topup-presets">
          <?php foreach ([299, 699, 1000, 2000] as $preset): ?>
            <button class="topup-preset" onclick="document.getElementById('topup-amount').value=<?= $preset ?>">
              <?= $preset ?> ₽
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- История транзакций -->
      <?php if ($txns): ?>
        <div class="bcard">
          <div class="bcard__head"><?= icon('list-checks', '', 18) ?> <span>История</span></div>
          <div class="txn-list">
            <?php foreach ($txns as $t): ?>
              <div class="txn-item txn-item--<?= $t['type'] ?>">
                <span
                  class="txn-item__icon"><?= $t['type'] === 'topup' ? '↑' : ($t['type'] === 'refund' ? '↩' : '↓') ?></span>
                <div class="txn-item__info">
                  <span class="txn-item__desc"><?= htmlspecialchars($t['description']) ?></span>
                  <span class="txn-item__date fmt-date" data-utc="<?= htmlspecialchars($t['created_at']) ?>"></span>
                </div>
                <span class="txn-item__amount">
                  <?= $t['type'] === 'topup' || $t['type'] === 'refund' ? '+' : '−' ?>
                  <?= number_format((float) $t['amount'], 2, '.', ' ') ?> ₽
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- ══ ПРАВАЯ КОЛОНКА — Тарифы ══════════════════════════════ -->
    <div class="billing-right">
      <h2 class="billing-plans__title">Тарифы</h2>
      <div class="plans-grid">
        <?php
        $order_map = ['free' => 0, 'level1' => 1, 'level2' => 2];
        $current_ord = $order_map[$sub['slug']] ?? 0;
        foreach ($plans as $plan):
          $is_current = ($plan['slug'] === $sub['slug']);
          $is_pending = ($pending && $pending['slug'] === $plan['slug']);
          $price = (float) $plan['price'];
          $plan_ord = $order_map[$plan['slug']] ?? 0;
          $is_upgrade = $plan_ord > $current_ord;
          $is_downgrade = $plan_ord < $current_ord && $plan['slug'] !== 'free';

          // Скидка при апгрейде
          $pricing = calculate_upgrade_price($sub, $plan);
          $final_price = $is_upgrade ? $pricing['final'] : $price;
          $can_afford = (float) $wallet['balance'] >= $final_price;
          ?>
          <div
            class="plan-card <?= $is_current ? 'plan-card--current' : '' ?> <?= $plan['slug'] === 'level1' ? 'plan-card--popular' : '' ?>">
            <?php if ($plan['slug'] === 'level1'): ?>
              <div class="plan-card__popular-badge">Популярный</div>
            <?php endif; ?>
            <?php if ($is_pending): ?>
              <div class="plan-card__pending-badge"><?= icon('clock', '', 11) ?> Запланирован</div>
            <?php endif; ?>

            <div class="plan-card__name"><?= htmlspecialchars($plan['name']) ?></div>
            <div class="plan-card__price">
              <?php if ($price == 0): ?>
                <span class="plan-card__price-val">0</span>
                <span class="plan-card__price-cur">₽</span>
              <?php elseif ($is_upgrade && $pricing['discount_pct'] > 0): ?>
                <span class="plan-card__price-old"><?= number_format($price, 0, '.', ' ') ?></span>
                <span class="plan-card__price-val"><?= number_format($final_price, 0, '.', ' ') ?></span>
                <span class="plan-card__price-cur">₽
                  <span class="plan-card__discount">−<?= $pricing['discount_pct'] ?>%</span>
                </span>
              <?php else: ?>
                <span class="plan-card__price-val"><?= number_format($price, 0, '.', ' ') ?></span>
                <span class="plan-card__price-cur">₽ / <?= $plan['duration_days'] ?> дн.</span>
              <?php endif; ?>
            </div>

            <ul class="plan-card__features">
              <li><?= icon('layout-dashboard', '', 13) ?>
                <?= $plan['max_dashboards'] == -1 ? 'Безлимит дашбордов' : "{$plan['max_dashboards']} дашборда" ?></li>
              <li><?= icon('file', '', 13) ?>
                <?= $plan['max_pages'] == -1 ? 'Безлимит страниц' : "{$plan['max_pages']} страниц" ?></li>
              <li><?= icon('users', '', 13) ?>
                <?= $plan['max_members'] == -1 ? 'Безлимит участников' : "{$plan['max_members']} участник" . ($plan['max_members'] > 1 ? 'ов' : '') ?>
              </li>
            </ul>

            <?php if ($is_current): ?>
              <button class="btn btn--ghost btn--full" disabled>Текущий тариф</button>
            <?php elseif ($is_pending): ?>
              <button class="btn btn--ghost btn--full" disabled><?= icon('clock', '', 13) ?> Запланирован</button>
            <?php elseif ($is_downgrade): ?>
              <button class="btn btn--ghost btn--full"
                onclick="activatePlan('<?= $plan['slug'] ?>', '<?= addslashes($plan['name']) ?>', 0, true)">
                <?= icon('arrow-down', '', 13) ?> Перейти со след. периода
              </button>
            <?php elseif ($price > 0 && !$can_afford): ?>
              <button class="btn btn--ghost btn--full"
                onclick="document.getElementById('topup-amount').value=<?= ceil($final_price - (float) $wallet['balance']) ?>;document.getElementById('topup-amount').focus()"
                title="Недостаточно средств">
                <?= icon('plus', '', 13) ?> Пополнить на
                <?= number_format($final_price - (float) $wallet['balance'], 0, '.', ' ') ?> ₽
              </button>
            <?php else: ?>
              <button class="btn btn--warm btn--full"
                onclick="activatePlan('<?= $plan['slug'] ?>', '<?= addslashes($plan['name']) ?>', <?= $final_price ?>, false)">
                <?= $price == 0 ? icon('check', '', 13) . ' Выбрать' : icon('zap', '', 13) . ' Подключить' ?>
                <?php if ($is_upgrade && $pricing['discount_pct'] > 0): ?>
                  <span style="font-size:.72rem;opacity:.8">−<?= $pricing['discount_pct'] ?>%</span>
                <?php endif; ?>
              </button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Заблокированные страницы -->
      <?php if ($locked_pages): ?>
        <div class="bcard" style="margin-top:1rem">
          <div class="bcard__head"><?= icon('lock', '', 16) ?> <span>Замороженные страницы
              (<?= count($locked_pages) ?>)</span></div>
          <div class="locked-list">
            <?php foreach ($locked_pages as $lp): ?>
              <div class="locked-item">
                <?= icon('file', '', 13) ?>
                <span><?= htmlspecialchars($lp['name']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <p style="font-size:.78rem;color:var(--text3);margin-top:.5rem">Повысьте тариф чтобы разморозить страницы.</p>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Modal: выбор дашбордов при даунгрейде -->
<div class="overlay" id="overlay-downgrade">
  <div class="modal">
    <div class="modal__head">
      <span class="modal__title"><?= icon('alert-triangle', '', 16) ?> Выберите активные дашборды</span>
      <button class="modal__close" onclick="closeDowngradeModal()"><?= icon('x', '', 16) ?></button>
    </div>
    <div class="modal__body">
      <p style="font-size:.875rem;color:var(--text2);margin-bottom:1rem">
        Оставьте <strong><?= $limits['max_dashboards'] ?></strong> дашборда активными.
      </p>
      <div class="downgrade-list">
        <?php foreach ($all_dashboards as $d):
          $is_locked = in_array($d['id'], $locked_ids); ?>
          <label class="downgrade-item <?= $is_locked ? 'downgrade-item--locked' : '' ?>">
            <input type="checkbox" class="downgrade-check" value="<?= $d['id'] ?>" <?= !$is_locked ? 'checked' : '' ?>>
            <span class="downgrade-item__name"><?= htmlspecialchars($d['name']) ?></span>
            <?php if ($is_locked): ?><span class="downgrade-item__badge"><?= icon('lock', '', 12) ?>
                заморожен</span><?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="downgrade-counter">Выбрано: <strong id="downgrade-count">0</strong> / <?= $limits['max_dashboards'] ?>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeDowngradeModal()">Отмена</button>
      <button class="btn btn--warm" onclick="applyDowngrade()"><?= icon('check', '', 14) ?> Применить</button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<style>
  .billing-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 2rem 1.5rem;
  }

  .billing-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 1.5rem;
    align-items: start;
  }

  @media (max-width: 820px) {
    .billing-grid {
      grid-template-columns: 1fr;
    }
  }

  .billing-left {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .bcard {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
  }

  .bcard__head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text3);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 1rem;
  }

  .plan-current {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
  }

  .plan-current__name {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--text);
  }

  .plan-current__badge {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
  }

  .plan-current__badge--free {
    background: var(--card);
    color: var(--text2);
  }

  .plan-current__badge--level1 {
    background: rgba(99, 102, 241, 0.15);
    color: #818cf8;
  }

  .plan-current__badge--level2 {
    background: rgba(212, 160, 87, 0.15);
    color: var(--amber);
  }

  .plan-current__expires {
    font-size: 0.78rem;
    color: var(--text3);
    width: 100%;
  }

  .usage-list {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
  }

  .usage-item {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.25rem;
    align-items: center;
  }

  .usage-item__label {
    font-size: 0.82rem;
    color: var(--text2);
    display: flex;
    align-items: center;
    gap: 0.3rem;
  }

  .usage-item__val {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text);
  }

  .usage-bar {
    grid-column: 1/-1;
    height: 4px;
    background: var(--card);
    border-radius: 2px;
    overflow: hidden;
  }

  .usage-bar__fill {
    height: 100%;
    background: var(--amber);
    border-radius: 2px;
    transition: width 0.4s;
  }

  .wallet-balance {
    text-align: center;
    padding: 0.75rem 0 1rem;
  }

  .wallet-balance__amount {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text);
  }

  .wallet-balance__amount span {
    font-size: 1rem;
    color: var(--text3);
  }

  .wallet-balance__label {
    font-size: 0.78rem;
    color: var(--text3);
  }

  .wallet-topup {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
  }

  .topup-presets {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
    margin-top: 0.4rem;
  }

  .topup-preset {
    padding: 3px 10px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--card);
    color: var(--text2);
    font-size: 0.78rem;
    cursor: pointer;
    transition: all 0.12s;
  }

  .topup-preset:hover {
    border-color: var(--amber);
    color: var(--amber);
  }

  .demo-notice {
    font-size: 0.72rem;
    color: var(--text3);
    margin-top: 0.4rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
  }

  .txn-list {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
  }

  .txn-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius);
    background: var(--bg);
    font-size: 0.82rem;
  }

  .txn-item__icon {
    width: 20px;
    text-align: center;
    font-weight: 700;
  }

  .txn-item--topup .txn-item__icon {
    color: #34d399;
  }

  .txn-item--charge .txn-item__icon {
    color: var(--rose);
  }

  .txn-item__info {
    flex: 1;
    overflow: hidden;
  }

  .txn-item__desc {
    display: block;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .txn-item__date {
    display: block;
    font-size: 0.72rem;
    color: var(--text3);
  }

  .txn-item__amount {
    font-weight: 700;
    flex-shrink: 0;
  }

  .txn-item--topup .txn-item__amount {
    color: #34d399;
  }

  .txn-item--charge .txn-item__amount {
    color: var(--rose);
  }

  .billing-plans__title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 1rem;
  }

  .plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
  }

  .plan-card {
    position: relative;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.5rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  .plan-card--current {
    border-color: var(--amber);
  }

  .plan-card--popular {
    border-color: #818cf8;
  }

  .plan-card__popular-badge {
    position: absolute;
    top: -10px;
    left: 50%;
    transform: translateX(-50%);
    background: #818cf8;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
    white-space: nowrap;
  }

  .plan-card__pending-badge {
    position: absolute;
    top: -10px;
    right: 12px;
    background: var(--amber2);
    color: var(--bg);
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    gap: 3px;
  }

  .plan-card__name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text);
  }

  .plan-card__price {
    display: flex;
    align-items: baseline;
    gap: 0.25rem;
    flex-wrap: wrap;
  }

  .plan-card__price-val {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
  }

  .plan-card__price-cur {
    font-size: 0.8rem;
    color: var(--text3);
  }

  .plan-card__price-old {
    font-size: 1rem;
    color: var(--text3);
    text-decoration: line-through;
  }

  .plan-card__discount {
    background: rgba(52, 211, 153, 0.15);
    color: #34d399;
    border-radius: 20px;
    padding: 1px 6px;
    font-size: 0.72rem;
    font-weight: 700;
    margin-left: 3px;
  }

  .plan-card__features {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    flex: 1;
  }

  .plan-card__features li {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.82rem;
    color: var(--text2);
  }

  .btn--full {
    width: 100%;
    justify-content: center;
  }

  .pending-banner {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    background: rgba(99, 102, 241, 0.08);
    border: 1px solid rgba(99, 102, 241, 0.25);
    border-radius: var(--radius-lg);
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
  }

  .pending-banner__icon {
    color: #818cf8;
    flex-shrink: 0;
  }

  .pending-banner__body {
    flex: 1;
  }

  .pending-banner__title {
    font-weight: 700;
    color: var(--text);
    font-size: 0.9rem;
  }

  .pending-banner__sub {
    font-size: 0.82rem;
    color: var(--text2);
    margin-top: 0.2rem;
  }

  .locked-list {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
  }

  .locked-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.82rem;
    color: var(--text2);
    padding: 0.3rem 0;
  }

  .downgrade-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: 1rem;
  }

  .downgrade-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
  }

  .downgrade-item:hover {
    background: var(--bg2);
  }

  .downgrade-item--locked {
    opacity: 0.6;
  }

  .downgrade-item input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    cursor: pointer;
  }

  .downgrade-item__name {
    flex: 1;
    font-size: 0.875rem;
  }

  .downgrade-item__badge {
    font-size: 0.72rem;
    color: var(--text3);
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  .downgrade-counter {
    text-align: right;
    font-size: 0.82rem;
    color: var(--text2);
  }
</style>

<script>
  const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  const MAX_DASHBOARDS = <?= json_encode($limits['max_dashboards']) ?>;

  async function api(action, body = {}) {
    const r = await fetch('/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ action, csrf: CSRF_TOKEN, ...body }),
    });
    return r.json();
  }

  function billingToast(msg, type = 'ok') {
    if (typeof toast === 'function') { toast(msg, type); return; }
    let t = document.getElementById('toasts');
    if (!t) { t = document.createElement('div'); t.id = 'toasts'; t.className = 'toasts'; document.body.appendChild(t); }
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.textContent = msg;
    t.appendChild(el);
    setTimeout(() => el.remove(), 3500);
  }

  // Пополнение — редирект на страницу оплаты ЮKassa
  async function openTopupWidget() {
    const amount = parseFloat(document.getElementById('topup-amount').value);
    if (!amount || amount <= 0) { billingToast('Введите сумму', 'err'); return; }

    const btn = document.querySelector('.wallet-topup .btn');
    if (btn) { btn.disabled = true; btn.textContent = '...'; }

    try {
      const r = await fetch('/payment.php?action=token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ amount, csrf: CSRF_TOKEN }),
      }).then(res => res.json());

      if (!r.ok) {
        // ЮKassa не настроена — демо-режим
        if (r.error && r.error.includes('не настроена')) {
          const dr = await api('topup_wallet', { amount });
          if (dr.ok) { billingToast(`Счёт пополнен на ${amount} ₽ (демо)`); location.reload(); }
          else billingToast(dr.error || 'Ошибка', 'err');
        } else {
          billingToast(r.error || 'Ошибка создания платежа', 'err');
        }
        if (btn) { btn.disabled = false; btn.textContent = 'Оплатить'; }
        return;
      }

      // Редирект на страницу оплаты ЮKassa
      if (r.confirmation_url) {
        window.location.href = r.confirmation_url;
      } else {
        billingToast('Не удалось получить ссылку на оплату', 'err');
        if (btn) { btn.disabled = false; btn.textContent = 'Оплатить'; }
      }
    } catch (e) {
      billingToast('Ошибка сети', 'err');
      if (btn) { btn.disabled = false; btn.textContent = 'Оплатить'; }
    }
  }

  // Активация тарифа
  async function activatePlan(slug, name, price, isDowngrade) {
    let msg;
    if (isDowngrade) {
      msg = `Перейти на тариф «${name}» со следующего периода?`;
    } else if (price > 0) {
      msg = `Подключить «${name}» за ${price} ₽?`;
    } else {
      msg = `Переключиться на «${name}»?`;
    }
    if (!confirm(msg)) return;

    document.querySelectorAll('.plan-card .btn').forEach(b => b.disabled = true);
    try {
      const r = await api('activate_plan', { slug });
      if (r.ok) {
        if (r.scheduled) {
          billingToast(`Переход на «${r.plan}» запланирован на следующий период`);
        } else if (r.discount_pct > 0) {
          billingToast(`«${r.plan}» активирован со скидкой ${r.discount_pct}%!`);
        } else {
          billingToast(`Тариф «${r.plan}» активирован!`);
        }
        location.reload();
      } else {
        billingToast(r.error || 'Ошибка', 'err');
        document.querySelectorAll('.plan-card .btn').forEach(b => b.disabled = false);
      }
    } catch (e) {
      billingToast('Ошибка сети', 'err');
      document.querySelectorAll('.plan-card .btn').forEach(b => b.disabled = false);
    }
  }

  // Отмена запланированного даунгрейда
  async function cancelPendingDowngrade() {
    if (!confirm('Отменить запланированный переход?')) return;
    const r = await api('cancel_pending_downgrade', {});
    if (r.ok) { billingToast('Переход отменён'); location.reload(); }
    else billingToast(r.error || 'Ошибка', 'err');
  }

  // Downgrade modal
  function openDowngradeModal() { document.getElementById('overlay-downgrade').classList.add('overlay--open'); updateCounter(); }
  function closeDowngradeModal() { document.getElementById('overlay-downgrade').classList.remove('overlay--open'); }
  function updateCounter() {
    const n = document.querySelectorAll('.downgrade-check:checked').length;
    document.getElementById('downgrade-count').textContent = n;
  }
  document.querySelectorAll('.downgrade-check').forEach(cb => cb.addEventListener('change', updateCounter));

  async function applyDowngrade() {
    const keep_ids = [...document.querySelectorAll('.downgrade-check:checked')].map(cb => parseInt(cb.value));
    if (MAX_DASHBOARDS !== -1 && keep_ids.length > MAX_DASHBOARDS) {
      billingToast(`Максимум ${MAX_DASHBOARDS} дашборда`, 'err'); return;
    }
    const r = await api('apply_downgrade', { keep_ids });
    if (r.ok) { billingToast(`Готово: ${r.active} активных, ${r.locked} заморожено`); location.reload(); }
    else billingToast(r.error || 'Ошибка', 'err');
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.fmt-date').forEach(function (el) {
      const utc = el.getAttribute('data-utc');
      if (!utc) return;
      const d = new Date(utc.replace(' ', 'T') + 'Z');
      el.textContent = d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    });
    document.getElementById('overlay-downgrade')?.addEventListener('click', function (e) {
      if (e.target === this) closeDowngradeModal();
    });
  });
</script>

<?php layout_end(); ?>