<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/icons.php';
require_once __DIR__ . '/core/billing_core.php';
require_once __DIR__ . '/templates/layout.php';
require_auth();

$user_id = (int)current_user()['id'];
$sub     = get_active_subscription($user_id);
$wallet  = get_wallet($user_id);
$plans   = get_all_plans();
$limits  = get_user_limits($user_id);
$txns    = get_wallet_transactions($user_id, 15);

// Статистика использования
$db = get_db();
$usage_dashboards = (int)$db->query("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`={$user_id}")->fetchColumn();

// Заблокированные дашборды
$locked_stmt = $db->prepare("
    SELECT le.`entity_id`, d.`name`
    FROM `locked_entities` le
    JOIN `dashboards` d ON d.`id` = le.`entity_id`
    WHERE le.`user_id` = ? AND le.`entity_type` = 'dashboard'
");
$locked_stmt->execute([$user_id]);
$locked_dashboards = $locked_stmt->fetchAll();

// Все дашборды для выбора при downgrade
$all_dashboards_stmt = $db->prepare("SELECT `id`, `name` FROM `dashboards` WHERE `owner_id` = ? ORDER BY `created_at` ASC");
$all_dashboards_stmt->execute([$user_id]);
$all_dashboards = $all_dashboards_stmt->fetchAll();

// Считаем только НЕзаблокированные дашборды
$locked_ids      = array_column($locked_dashboards, 'entity_id');
$active_dash_count = $usage_dashboards - count($locked_dashboards);

// Плашка: нужно выбрать если активных больше лимита
$needs_downgrade = !is_unlimited($limits['max_dashboards']) &&
    $active_dash_count > $limits['max_dashboards'];

layout_start('Подписка', ['body_class' => 'billing-page']);
?>

<?php $navbar_active = 'billing'; require __DIR__ . '/templates/navbar.php'; ?>

<div class="billing-wrap">

  <?php if ($needs_downgrade): ?>
  <!-- ══ БАННЕР DOWNGRADE ══════════════════════════════════════ -->
  <div class="downgrade-banner">
    <div class="downgrade-banner__icon"><?= icon('alert-triangle','',20) ?></div>
    <div class="downgrade-banner__body">
      <div class="downgrade-banner__title">Требуется выбор активных дашбордов</div>
      <div class="downgrade-banner__sub">
        Ваш тариф позволяет <?= $limits['max_dashboards'] ?> дашбордов,
        у вас <?= $usage_dashboards ?>. Выберите какие оставить активными — остальные будут заморожены (данные сохранятся).
      </div>
    </div>
    <button class="btn btn--warm" onclick="openDowngradeModal()">
      <?= icon('settings','',14) ?> Выбрать дашборды
    </button>
  </div>
  <?php endif; ?>

  <div class="billing-grid">

    <!-- ══ ЛЕВАЯ КОЛОНКА ════════════════════════════════════════ -->
    <div class="billing-left">

      <!-- Текущий план -->
      <div class="bcard">
        <div class="bcard__head">
          <?= icon('zap','',18) ?>
          <span>Текущий тариф</span>
        </div>
        <div class="plan-current">
          <div class="plan-current__name"><?= htmlspecialchars($sub['plan_name']) ?></div>
          <div class="plan-current__badge plan-current__badge--<?= $sub['slug'] ?>">
            <?= $sub['slug'] === 'free' ? 'Бесплатно' : (number_format((float)$sub['price'], 0, '.', ' ').' ₽/мес') ?>
          </div>
          <?php if ($sub['slug'] !== 'free'): ?>
            <div class="plan-current__expires">
              Действует до: <span class="fmt-date" data-utc="<?= htmlspecialchars($sub['expires_at']) ?>"></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Использование -->
        <div class="usage-list">
          <div class="usage-item">
            <span class="usage-item__label"><?= icon('layout-dashboard','',14) ?> Дашборды</span>
            <span class="usage-item__val">
              <?= $usage_dashboards ?> /
              <?= is_unlimited($limits['max_dashboards']) ? '∞' : $limits['max_dashboards'] ?>
            </span>
            <?php if (!is_unlimited($limits['max_dashboards'])): ?>
              <div class="usage-bar">
                <div class="usage-bar__fill" style="width:<?= min(100, round($usage_dashboards / $limits['max_dashboards'] * 100)) ?>%"></div>
              </div>
            <?php endif; ?>
          </div>
          <div class="usage-item">
            <span class="usage-item__label"><?= icon('file','',14) ?> Страниц на дашборд</span>
            <span class="usage-item__val">
              <?= is_unlimited($limits['max_pages']) ? '∞' : $limits['max_pages'] ?>
            </span>
          </div>
          <div class="usage-item">
            <span class="usage-item__label"><?= icon('users','',14) ?> Участников на дашборд</span>
            <span class="usage-item__val">
              <?= is_unlimited($limits['max_members']) ? '∞' : $limits['max_members'] ?>
            </span>
          </div>
        </div>
      </div>

      <!-- Кошелёк -->
      <div class="bcard">
        <div class="bcard__head">
          <?= icon('hash','',18) ?>
          <span>Счёт</span>
        </div>
        <div class="wallet-balance">
          <div class="wallet-balance__amount"><?= number_format((float)$wallet['balance'], 2, '.', ' ') ?> <span>₽</span></div>
          <div class="wallet-balance__label">Доступно</div>
        </div>
        <div class="wallet-topup">
          <input type="number" class="input" id="topup-amount" placeholder="Сумма пополнения" min="1" max="100000" step="1">
          <button class="btn btn--warm" onclick="topupWallet()">
            <?= icon('plus','',14) ?> Пополнить
          </button>
        </div>
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
        <div class="bcard__head">
          <?= icon('list-checks','',18) ?>
          <span>История операций</span>
        </div>
        <div class="txn-list">
          <?php foreach ($txns as $t): ?>
            <div class="txn-item txn-item--<?= $t['type'] ?>">
              <span class="txn-item__icon">
                <?= $t['type'] === 'topup' ? '↑' : ($t['type'] === 'refund' ? '↩' : '↓') ?>
              </span>
              <div class="txn-item__info">
                <span class="txn-item__desc"><?= htmlspecialchars($t['description']) ?></span>
                <span class="txn-item__date fmt-date" data-utc="<?= htmlspecialchars($t['created_at']) ?>"></span>
              </div>
              <span class="txn-item__amount">
                <?= $t['type'] === 'topup' || $t['type'] === 'refund' ? '+' : '−' ?>
                <?= number_format((float)$t['amount'], 2, '.', ' ') ?> ₽
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- ══ ПРАВАЯ КОЛОНКА — Тарифы ══════════════════════════════ -->
    <div class="billing-right">
      <h2 class="billing-plans__title">Выберите тариф</h2>
      <div class="plans-grid">
        <?php foreach ($plans as $plan):
          $is_current = ($plan['slug'] === $sub['slug']);
          $price      = (float)$plan['price'];
          $can_afford = (float)$wallet['balance'] >= $price;
        ?>
        <div class="plan-card <?= $is_current ? 'plan-card--current' : '' ?> <?= $plan['slug'] === 'level1' ? 'plan-card--popular' : '' ?>">
          <?php if ($plan['slug'] === 'level1'): ?>
            <div class="plan-card__popular-badge">Популярный</div>
          <?php endif; ?>

          <div class="plan-card__name"><?= htmlspecialchars($plan['name']) ?></div>
          <div class="plan-card__price">
            <?php if ($price == 0): ?>
              <span class="plan-card__price-val">0</span>
              <span class="plan-card__price-cur">₽</span>
            <?php else: ?>
              <span class="plan-card__price-val"><?= number_format($price, 0, '.', ' ') ?></span>
              <span class="plan-card__price-cur">₽ / <?= $plan['duration_days'] ?> дн.</span>
            <?php endif; ?>
          </div>

          <ul class="plan-card__features">
            <li>
              <?= icon('layout-dashboard','',13) ?>
              <?= $plan['max_dashboards'] == -1 ? 'Безлимит дашбордов' : "{$plan['max_dashboards']} дашборда" ?>
            </li>
            <li>
              <?= icon('file','',13) ?>
              <?= $plan['max_pages'] == -1 ? 'Безлимит страниц' : "{$plan['max_pages']} страниц на дашборд" ?>
            </li>
            <li>
              <?= icon('users','',13) ?>
              <?= $plan['max_members'] == -1 ? 'Безлимит участников' : "{$plan['max_members']} участник" . ($plan['max_members'] > 1 ? 'ов' : '') ?>
            </li>
          </ul>

          <?php if ($is_current): ?>
            <button class="btn btn--ghost btn--full" disabled>Текущий тариф</button>
          <?php elseif ($price > 0 && !$can_afford): ?>
            <button class="btn btn--ghost btn--full"
                    onclick="document.getElementById('topup-amount').value=<?= $price - (float)$wallet['balance'] ?>;document.getElementById('topup-amount').focus()"
                    title="Недостаточно средств">
              <?= icon('plus','',13) ?> Пополнить на <?= number_format($price - (float)$wallet['balance'], 0, '.', ' ') ?> ₽
            </button>
          <?php else: ?>
            <button class="btn btn--warm btn--full"
                    onclick="activatePlan('<?= $plan['slug'] ?>', '<?= addslashes($plan['name']) ?>', <?= $price ?>)">
              <?= $price == 0 ? 'Выбрать' : 'Подключить' ?>
            </button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<!-- ══ MODAL: Downgrade — выбор дашбордов ═══════════════════ -->
<div class="overlay" id="overlay-downgrade">
  <div class="modal">
    <div class="modal__head">
      <span class="modal__title"><?= icon('alert-triangle','',16) ?> Выбор активных дашбордов</span>
      <button class="modal__close" onclick="closeDowngradeModal()"><?= icon('x','',16) ?></button>
    </div>
    <div class="modal__body">
      <p style="color:var(--text-2);font-size:.875rem;margin-bottom:1rem">
        Выберите <strong><?= $limits['max_dashboards'] ?></strong> дашборд(а) которые останутся активными.
        Остальные будут заморожены — данные сохранятся, доступ откроется при повышении тарифа.
      </p>
      <div class="downgrade-list" id="downgrade-list">
        <?php foreach ($all_dashboards as $d):
          $is_locked = in_array($d['id'], array_column($locked_dashboards, 'entity_id'));
        ?>
          <label class="downgrade-item <?= $is_locked ? 'downgrade-item--locked' : '' ?>">
            <input type="checkbox" class="downgrade-check" value="<?= $d['id'] ?>"
                   <?= !$is_locked ? 'checked' : '' ?>>
            <span class="downgrade-item__name"><?= htmlspecialchars($d['name']) ?></span>
            <?php if ($is_locked): ?>
              <span class="downgrade-item__badge"><?= icon('lock','',12) ?> заморожен</span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="downgrade-counter" id="downgrade-counter">
        Выбрано: <strong id="downgrade-count">0</strong> / <?= $limits['max_dashboards'] ?>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeDowngradeModal()">Отмена</button>
      <button class="btn btn--warm" onclick="applyDowngrade()">
        <?= icon('check','',14) ?> Применить
      </button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<style>
/* ── Layout ──────────────────────────────────────────────────── */
.billing-wrap  { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }
.billing-grid  { display: grid; grid-template-columns: 340px 1fr; gap: 1.5rem; align-items: start; }
@media (max-width: 820px) { .billing-grid { grid-template-columns: 1fr; } }
.billing-left  { display: flex; flex-direction: column; gap: 1rem; }
.billing-right { }

/* ── Bcard ───────────────────────────────────────────────────── */
.bcard { background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.25rem; }
.bcard__head { display: flex; align-items: center; gap: .5rem; font-size: .8rem; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 1rem; }

/* ── Текущий план ────────────────────────────────────────────── */
.plan-current { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.plan-current__name { font-size: 1.25rem; font-weight: 800; color: var(--text-1); }
.plan-current__badge { font-size: .75rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
.plan-current__badge--free   { background: var(--surface-3); color: var(--text-2); }
.plan-current__badge--level1 { background: rgba(99,102,241,.15); color: #6366f1; }
.plan-current__badge--level2 { background: rgba(245,158,11,.15); color: var(--amber); }
.plan-current__expires { font-size: .78rem; color: var(--text-3); width: 100%; }

/* ── Usage bars ──────────────────────────────────────────────── */
.usage-list { display: flex; flex-direction: column; gap: .6rem; }
.usage-item { display: grid; grid-template-columns: 1fr auto; gap: .25rem; align-items: center; }
.usage-item__label { font-size: .82rem; color: var(--text-2); display: flex; align-items: center; gap: .3rem; }
.usage-item__val   { font-size: .82rem; font-weight: 700; color: var(--text-1); text-align: right; }
.usage-bar { grid-column: 1/-1; height: 4px; background: var(--surface-3); border-radius: 2px; overflow: hidden; }
.usage-bar__fill { height: 100%; background: var(--accent); border-radius: 2px; transition: width .4s; }

/* ── Кошелёк ─────────────────────────────────────────────────── */
.wallet-balance { text-align: center; padding: .75rem 0 1rem; }
.wallet-balance__amount { font-size: 2rem; font-weight: 800; color: var(--text-1); }
.wallet-balance__amount span { font-size: 1rem; color: var(--text-3); }
.wallet-balance__label { font-size: .78rem; color: var(--text-3); margin-top: .15rem; }
.wallet-topup { display: flex; gap: .5rem; margin-bottom: .5rem; }
.topup-presets { display: flex; gap: .4rem; flex-wrap: wrap; }
.topup-preset { padding: 3px 10px; border: 1px solid var(--border); border-radius: 20px; background: var(--surface-3); color: var(--text-2); font-size: .78rem; cursor: pointer; transition: all .12s; }
.topup-preset:hover { border-color: var(--accent); color: var(--accent); }

/* ── Транзакции ──────────────────────────────────────────────── */
.txn-list { display: flex; flex-direction: column; gap: .4rem; }
.txn-item { display: flex; align-items: center; gap: .75rem; padding: .5rem .75rem; border-radius: var(--radius-sm); background: var(--surface-1); font-size: .82rem; }
.txn-item__icon { font-size: 1rem; width: 20px; text-align: center; font-weight: 700; }
.txn-item--topup  .txn-item__icon { color: #10b981; }
.txn-item--charge .txn-item__icon { color: #ef4444; }
.txn-item--refund .txn-item__icon { color: #6366f1; }
.txn-item__info { flex: 1; overflow: hidden; }
.txn-item__desc { display: block; color: var(--text-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.txn-item__date { display: block; font-size: .72rem; color: var(--text-3); }
.txn-item__amount { font-weight: 700; flex-shrink: 0; }
.txn-item--topup  .txn-item__amount { color: #10b981; }
.txn-item--charge .txn-item__amount { color: #ef4444; }

/* ── Тарифные карточки ───────────────────────────────────────── */
.billing-plans__title { font-size: 1.1rem; font-weight: 700; color: var(--text-1); margin-bottom: 1rem; }
.plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
.plan-card { position: relative; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem 1.25rem; display: flex; flex-direction: column; gap: .75rem; }
.plan-card--current { border-color: var(--accent); background: var(--accent-subtle); }
.plan-card--popular { border-color: #6366f1; }
.plan-card__popular-badge { position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #6366f1; color: #fff; font-size: .7rem; font-weight: 700; padding: 2px 10px; border-radius: 20px; white-space: nowrap; }
.plan-card__name { font-size: 1rem; font-weight: 700; color: var(--text-1); }
.plan-card__price { display: flex; align-items: baseline; gap: .25rem; }
.plan-card__price-val { font-size: 2rem; font-weight: 800; color: var(--text-1); line-height: 1; }
.plan-card__price-cur { font-size: .8rem; color: var(--text-3); }
.plan-card__features { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .4rem; flex: 1; }
.plan-card__features li { display: flex; align-items: center; gap: .4rem; font-size: .82rem; color: var(--text-2); }
.btn--full { width: 100%; justify-content: center; }

/* ── Downgrade banner ────────────────────────────────────────── */
.downgrade-banner { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); border-radius: var(--radius-lg); margin-bottom: 1.5rem; flex-wrap: wrap; }
.downgrade-banner__icon { color: var(--amber); flex-shrink: 0; }
.downgrade-banner__body { flex: 1; }
.downgrade-banner__title { font-weight: 700; color: var(--text-1); margin-bottom: .2rem; }
.downgrade-banner__sub   { font-size: .83rem; color: var(--text-2); }

/* ── Downgrade modal ─────────────────────────────────────────── */
.downgrade-list { display: flex; flex-direction: column; gap: .5rem; margin-bottom: 1rem; }
.downgrade-item { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-md); cursor: pointer; transition: background .12s; }
.downgrade-item:hover { background: var(--surface-2); }
.downgrade-item--locked { opacity: .6; }
.downgrade-item input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; cursor: pointer; }
.downgrade-item__name { flex: 1; font-size: .875rem; color: var(--text-1); }
.downgrade-item__badge { font-size: .72rem; color: var(--text-3); display: flex; align-items: center; gap: .25rem; }
.downgrade-counter { text-align: right; font-size: .82rem; color: var(--text-2); }
</style>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const MAX_DASHBOARDS = <?= $limits['max_dashboards'] ?>;

async function api(action, body = {}) {
  const r = await fetch('/api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify({ action, csrf: CSRF_TOKEN, ...body }),
  });
  return r.json();
}

function billingToast(msg, type = 'ok') {
  // Попробовать использовать глобальную toast из app.js
  if (typeof toast === 'function') { toast(msg, type); return; }
  // Фоллбэк — свой рендер
  let t = document.getElementById('toasts');
  if (!t) { t = document.createElement('div'); t.id = 'toasts'; t.className = 'toasts'; document.body.appendChild(t); }
  const el = document.createElement('div');
  el.className = `toast toast--${type}`;
  el.textContent = msg;
  t.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// Пополнение кошелька
async function topupWallet() {
  const amount = parseFloat(document.getElementById('topup-amount').value);
  if (!amount || amount <= 0) { billingToast('Введите сумму', 'err'); return; }

  const btn = document.querySelector('.wallet-topup .btn');
  if (btn) btn.disabled = true;

  try {
    const r = await api('topup_wallet', { amount });
    if (r.ok) {
      billingToast(`Счёт пополнен на ${amount} ₽`);
      location.reload();
    } else {
      billingToast(r.error || 'Ошибка', 'err');
      if (btn) btn.disabled = false;
    }
  } catch (e) {
    billingToast('Ошибка сети', 'err');
    if (btn) btn.disabled = false;
  }
}

// Активация тарифа
async function activatePlan(slug, name, price) {
  const msg = price > 0
    ? `Подключить тариф «${name}» за ${price} ₽?`
    : `Переключиться на тариф «${name}»?`;
  if (!confirm(msg)) return;

  // Заблокировать кнопки на время запроса
  document.querySelectorAll('.plan-card .btn').forEach(b => b.disabled = true);

  try {
    const r = await api('activate_plan', { slug });
    if (r.ok) {
      if (r.needs_downgrade) {
        billingToast(`Тариф «${r.plan}» активирован. Выберите активные дашборды.`);
        location.reload();
      } else {
        billingToast(`Тариф «${r.plan}» активирован!`);
        location.reload();
      }
    } else {
      billingToast(r.error || 'Ошибка активации', 'err');
      document.querySelectorAll('.plan-card .btn').forEach(b => b.disabled = false);
    }
  } catch (e) {
    billingToast('Ошибка сети', 'err');
    document.querySelectorAll('.plan-card .btn').forEach(b => b.disabled = false);
  }
}

// Downgrade modal
function openDowngradeModal() {
  document.getElementById('overlay-downgrade').classList.add('overlay--open');
  updateDowngradeCounter();
}
function closeDowngradeModal() {
  document.getElementById('overlay-downgrade').classList.remove('overlay--open');
}
function updateDowngradeCounter() {
  const checked = document.querySelectorAll('.downgrade-check:checked').length;
  document.getElementById('downgrade-count').textContent = checked;
}
document.querySelectorAll('.downgrade-check').forEach(cb => {
  cb.addEventListener('change', updateDowngradeCounter);
});

async function applyDowngrade() {
  const keep_ids = [...document.querySelectorAll('.downgrade-check:checked')].map(cb => parseInt(cb.value));
  if (MAX_DASHBOARDS !== -1 && keep_ids.length > MAX_DASHBOARDS) {
    billingToast(`Можно выбрать максимум ${MAX_DASHBOARDS} дашборда`, 'err');
    return;
  }
  const r = await api('apply_downgrade', { keep_ids });
  if (r.ok) {
    billingToast(`Готово: ${r.active} активных, ${r.locked} заморожено`);
    setTimeout(() => location.reload(), 800);
  } else {
    billingToast(r.error || 'Ошибка', 'err');
  }
}

// Форматирование дат
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.fmt-date').forEach(function(el) {
    var utc = el.getAttribute('data-utc');
    if (!utc) return;
    var d = new Date(utc.replace(' ', 'T') + 'Z');
    el.textContent = d.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
  });
});
</script>

<?php layout_end(); ?>