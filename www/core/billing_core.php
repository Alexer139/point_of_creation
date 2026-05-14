<?php
/**
 * core/billing.php — система подписок, кошелька и лимитов
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_all_plans(): array
{
    return get_db()->query("SELECT * FROM `plans` ORDER BY `sort_order` ASC")->fetchAll();
}
function get_plan_by_slug(string $slug): ?array
{
    $s = get_db()->prepare("SELECT * FROM `plans` WHERE `slug`=? LIMIT 1");
    $s->execute([$slug]);
    return $s->fetch() ?: null;
}
function get_plan_by_id(int $id): ?array
{
    $s = get_db()->prepare("SELECT * FROM `plans` WHERE `id`=? LIMIT 1");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function get_active_subscription(int $user_id): array
{

    $fallback = ['slug' => 'free', 'plan_name' => 'Free', 'max_dashboards' => 3, 'max_pages' => 5, 'max_members' => 1, 'price' => 0, 'status' => 'active'];
    try {
        $db = get_db();
        // Проверить что таблицы существуют
        $tables = $db->query("SHOW TABLES LIKE 'subscriptions'")->fetchAll();
        if (empty($tables))
            return $fallback;
        $s = $db->prepare("SELECT s.*,p.`slug`,p.`name` AS plan_name,p.`max_dashboards`,p.`max_pages`,p.`max_members`,p.`price`
            FROM `subscriptions` s JOIN `plans` p ON p.`id`=s.`plan_id`
            WHERE s.`user_id`=? AND s.`status`='active' AND s.`expires_at`>NOW()
            ORDER BY p.`sort_order` DESC LIMIT 1");
        $s->execute([$user_id]);
        $sub = $s->fetch();
        if (!$sub) {
            ensure_free_subscription($user_id);
            $s->execute([$user_id]);
            $sub = $s->fetch();
        }
        $result = $sub ?: $fallback;
        return $result;
    } catch (Throwable $e) {
        return $fallback;
    }
}
function ensure_free_subscription(int $user_id): void
{
    try {
        $db = get_db();
        $free = get_plan_by_slug('free');
        if (!$free)
            return;
        $db->prepare("INSERT IGNORE INTO `wallets` (`user_id`,`balance`) VALUES (?,0.00)")->execute([$user_id]);
        $s = $db->prepare("SELECT COUNT(*) FROM `subscriptions` WHERE `user_id`=? AND `plan_id`=? AND `status`='active' AND `expires_at`>NOW()");
        $s->execute([$user_id, $free['id']]);
        if ((int) $s->fetchColumn() > 0)
            return;
        $db->prepare("INSERT INTO `subscriptions` (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL 100 YEAR))")
            ->execute([$user_id, $free['id']]);
    } catch (Throwable $e) { /* таблицы ещё не готовы */
    }
}

// ══════════════════════════════════════════════════════════════
//  АВТО-ПРОВЕРКА ИСТЁКШИХ ПОДПИСОК
// ══════════════════════════════════════════════════════════════

/**
 * Вызывается при каждом запросе к API.
 * Если подписка истекла — переводит на Free и замораживает лишние дашборды.
 */
function check_and_apply_expiry(int $user_id): void
{
    // Rate-limit: не чаще раза в 60 сек на пользователя (через сессию)
    $sess_key = "expiry_checked_{$user_id}";
    if (!empty($_SESSION[$sess_key]) && (time() - $_SESSION[$sess_key]) < 60)
        return;
    $_SESSION[$sess_key] = time();

    try {
        $db = get_db();
        $tables = $db->query("SHOW TABLES LIKE 'subscriptions'")->fetchAll();
        if (empty($tables))
            return;

        // Активировать pending_downgrade если текущая подписка истекла
        $pending = $db->prepare("
            SELECT s.`id`, s.`plan_id`, s.`expires_at`, p.`slug`, p.`name`, p.`max_dashboards`, p.`max_pages`
            FROM `subscriptions` s
            JOIN `plans` p ON p.`id` = s.`plan_id`
            WHERE s.`user_id` = ?
              AND s.`status` = 'pending_downgrade'
              AND (SELECT `expires_at` FROM `subscriptions`
                   WHERE `user_id` = ? AND `status` = 'active' LIMIT 1) <= NOW()
        ");
        $pending->execute([$user_id, $user_id]);
        $pending_rows = $pending->fetchAll();
        foreach ($pending_rows as $pr) {
            // Отменить текущую активную
            $db->prepare("UPDATE `subscriptions` SET `status`='expired' WHERE `user_id`=? AND `status`='active'")->execute([$user_id]);
            // Активировать запланированный даунгрейд
            $db->prepare("UPDATE `subscriptions` SET `status`='active',`started_at`=NOW() WHERE `id`=?")->execute([$pr['id']]);
            // Применить заморозку страниц и дашбордов
            if ((int) $pr['max_dashboards'] !== -1)
                auto_lock_excess_dashboards($user_id, (int) $pr['max_dashboards']);
            if ((int) $pr['max_pages'] !== -1)
                auto_lock_excess_pages($user_id, (int) $pr['max_pages']);
            // Уведомить
            try {
                $meta = json_encode(['old_plan' => 'Предыдущий тариф', 'new_plan' => $pr['name'], 'expires_at' => $pr['expires_at']]);
                $db->prepare("INSERT INTO `notifications` (`user_id`,`type`,`dashboard_name`,`actor_name`,`role`,`meta_json`) VALUES (?,'subscription_downgrade','','Система','',?)")->execute([$user_id, $meta]);
            } catch (Throwable $e) {
            }
        }

        // Найти истёкшие активные подписки (не Free, не запланированные)
        $stmt = $db->prepare("
            SELECT s.`id`, p.`slug`, p.`name`
            FROM `subscriptions` s
            JOIN `plans` p ON p.`id` = s.`plan_id`
            WHERE s.`user_id` = ?
              AND s.`status` = 'active'
              AND s.`expires_at` <= NOW()
              AND p.`slug` != 'free'
        ");
        $stmt->execute([$user_id]);
        $expired = $stmt->fetchAll();

        if (empty($expired))
            return;

        $db->beginTransaction();
        try {
            // Пометить как истёкшие
            foreach ($expired as $sub) {
                $db->prepare("UPDATE `subscriptions` SET `status`='expired' WHERE `id`=?")
                    ->execute([$sub['id']]);
            }

            // Выдать Free-подписку
            $free = get_plan_by_slug('free');
            if ($free) {
                // Проверить нет ли уже активной Free
                $chk = $db->prepare("SELECT COUNT(*) FROM `subscriptions` WHERE `user_id`=? AND `plan_id`=? AND `status`='active' AND `expires_at`>NOW()");
                $chk->execute([$user_id, $free['id']]);
                if ((int) $chk->fetchColumn() === 0) {
                    $db->prepare("INSERT INTO `subscriptions` (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL 100 YEAR))")
                        ->execute([$user_id, $free['id']]);
                }

                // Авто-заморозить дашборды сверх лимита Free
                $free_max = (int) $free['max_dashboards'];
                if ($free_max !== -1) {
                    auto_lock_excess_dashboards($user_id, $free_max);
                }
            }

            $db->commit();

            // Уведомить об истечении подписки
            try {
                $old_name = $expired[0]['name'] ?? 'Платный тариф';
                $meta = json_encode(['old_plan' => $old_name, 'new_plan' => 'Free', 'expires_at' => date('Y-m-d H:i:s')]);
                $db->prepare("INSERT INTO `notifications` (`user_id`,`type`,`dashboard_name`,`actor_name`,`role`,`meta_json`) VALUES (?,'subscription_expired','','Система','',?)")
                    ->execute([$user_id, $meta]);
            } catch (Throwable $e) {
            }

        } catch (Throwable $e) {
            if ($db->inTransaction())
                $db->rollBack();
        }
    } catch (Throwable $e) { /* тихо */
    }
}

/**
 * Заморозить дашборды сверх лимита.
 * Оставляет $max самых старых (по created_at) активными, остальные замораживает.
 */
function auto_lock_excess_dashboards(int $user_id, int $max): void
{
    $db = get_db();

    // Получить все дашборды пользователя, отсортированные по дате создания
    $stmt = $db->prepare("
        SELECT d.`id`
        FROM `dashboards` d
        LEFT JOIN `locked_entities` le
            ON le.`entity_id` = d.`id`
            AND le.`user_id` = ?
            AND le.`entity_type` = 'dashboard'
        WHERE d.`owner_id` = ?
        ORDER BY
            le.`entity_id` IS NULL DESC,  -- сначала незаблокированные
            d.`created_at` ASC            -- старые приоритетнее
    ");
    $stmt->execute([$user_id, $user_id]);
    $all = array_column($stmt->fetchAll(), 'id');

    // Первые $max оставляем, остальные замораживаем
    $keep = array_slice($all, 0, $max);
    $lock = array_slice($all, $max);

    foreach ($lock as $did) {
        $db->prepare("INSERT IGNORE INTO `locked_entities` (`user_id`,`entity_type`,`entity_id`) VALUES (?,'dashboard',?)")
            ->execute([$user_id, (int) $did]);
    }
    // Сбросить флаг — показать экран выбора
    reset_downgrade_choice($user_id);
}

function get_user_limits(int $user_id): array
{
    $sub = get_active_subscription($user_id);
    return ['max_dashboards' => (int) $sub['max_dashboards'], 'max_pages' => (int) $sub['max_pages'], 'max_members' => (int) $sub['max_members'], 'plan_slug' => $sub['slug'], 'plan_name' => $sub['plan_name']];
}
function is_unlimited(int $val): bool
{
    return $val === -1;
}
function can_create_dashboard(int $user_id): array
{
    $l = get_user_limits($user_id);
    if (is_unlimited($l['max_dashboards']))
        return ['ok' => true];
    $s = get_db()->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`=?");
    $s->execute([$user_id]);
    if ((int) $s->fetchColumn() >= $l['max_dashboards'])
        return ['ok' => false, 'error' => "Лимит тарифа «{$l['plan_name']}»: {$l['max_dashboards']} дашбордов", "upgrade" => true];
    return ['ok' => true];
}
function can_create_page(int $dashboard_id, int $user_id): array
{
    $l = get_user_limits($user_id);
    if (is_unlimited($l['max_pages']))
        return ['ok' => true];
    $s = get_db()->prepare("SELECT COUNT(*) FROM `pages` WHERE `dashboard_id`=?");
    $s->execute([$dashboard_id]);
    if ((int) $s->fetchColumn() >= $l['max_pages'])
        return ['ok' => false, 'error' => "Лимит тарифа «{$l['plan_name']}»: {$l['max_pages']} страниц", "upgrade" => true];
    return ['ok' => true];
}
function can_invite_member(int $dashboard_id, int $user_id): array
{
    $l = get_user_limits($user_id);
    if (is_unlimited($l['max_members']))
        return ['ok' => true];
    $s = get_db()->prepare("SELECT COUNT(*) FROM `dashboard_access` WHERE `dashboard_id`=?");
    $s->execute([$dashboard_id]);
    if ((int) $s->fetchColumn() >= $l['max_members'])
        return ['ok' => false, 'error' => "Лимит тарифа «{$l['plan_name']}»: {$l['max_members']} участников", "upgrade" => true];
    return ['ok' => true];
}
function is_dashboard_locked(int $dashboard_id, int $user_id): bool
{
    try {
        // Безлимитный тариф — заморозки не может быть, чистим остатки
        $limits = get_user_limits($user_id);
        if (is_unlimited($limits['max_dashboards'])) {
            get_db()->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard'")->execute([$user_id]);
            return false;
        }
        $s = get_db()->prepare("SELECT COUNT(*) FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' AND `entity_id`=?");
        $s->execute([$user_id, $dashboard_id]);
        return (int) $s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function get_wallet(int $user_id): array
{
    $db = get_db();
    $db->prepare("INSERT IGNORE INTO `wallets` (`user_id`,`balance`) VALUES (?,0.00)")->execute([$user_id]);
    $s = $db->prepare("SELECT * FROM `wallets` WHERE `user_id`=?");
    $s->execute([$user_id]);
    return $s->fetch();
}
function topup_wallet(int $user_id, float $amount, string $desc = 'Пополнение счёта'): array
{
    if ($amount <= 0)
        return ['ok' => false, 'error' => 'Сумма должна быть больше нуля'];
    if ($amount > 100000)
        return ['ok' => false, 'error' => 'Максимум 100 000 ₽'];
    $db = get_db();
    $db->beginTransaction();
    try {
        $w = get_wallet($user_id);
        $bal = round((float) $w['balance'] + $amount, 2);
        $db->prepare("UPDATE `wallets` SET `balance`=? WHERE `user_id`=?")->execute([$bal, $user_id]);
        $db->prepare("INSERT INTO `wallet_transactions` (`user_id`,`type`,`amount`,`balance_after`,`description`) VALUES (?,'topup',?,?,?)")->execute([$user_id, $amount, $bal, $desc]);
        $db->commit();
        return ['ok' => true, 'balance' => $bal];
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
function get_wallet_transactions(int $user_id, int $limit = 20): array
{
    $s = get_db()->prepare("SELECT * FROM `wallet_transactions` WHERE `user_id`=? ORDER BY `created_at` DESC LIMIT ?");
    $s->execute([$user_id, $limit]);
    return $s->fetchAll();
}
function calculate_upgrade_price(array $current, array $new_plan): array
{
    // Скидка при апгрейде если текущая подписка ещё активна
    $price = (float) $new_plan['price'];
    $discount = 0;
    $discount_pct = 0;

    if ($current['slug'] !== 'free' && !empty($current['expires_at'])) {
        $days_left = max(0, (int) ceil((strtotime($current['expires_at']) - time()) / 86400));
        if ($days_left > 15) {
            $discount_pct = 50;
        } elseif ($days_left > 0) {
            $discount_pct = 25;
        }
        $discount = round($price * $discount_pct / 100, 2);
    }

    return [
        'original' => $price,
        'discount' => $discount,
        'final' => round($price - $discount, 2),
        'discount_pct' => $discount_pct,
    ];
}

function activate_subscription(int $user_id, string $plan_slug): array
{
    $db = get_db();
    $plan = get_plan_by_slug($plan_slug);
    if (!$plan || !$plan['is_active'])
        return ['ok' => false, 'error' => 'Тариф не найден'];

    $current = get_active_subscription($user_id);

    // Определяем направление изменения
    $order = ['free' => 0, 'level1' => 1, 'level2' => 2];
    $current_ord = $order[$current['slug'] ?? 'free'] ?? 0;
    $new_ord = $order[$plan_slug] ?? 0;
    $is_upgrade = $new_ord > $current_ord;
    $is_downgrade = $new_ord < $current_ord;

    // Рассчитать итоговую цену
    $pricing = calculate_upgrade_price($current, $plan);
    $price = $is_upgrade ? $pricing['final'] : $pricing['original'];

    // Даунгрейд: не списываем деньги сейчас, активируем со следующего цикла
    // Апгрейд: активируем сразу со скидкой
    $starts_at = 'NOW()';
    $expires = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));

    if ($is_downgrade && $current['slug'] !== 'free') {
        // Даунгрейд активируется в конце текущего периода
        $starts_at = $current['expires_at'];
        $expires = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days", strtotime($current['expires_at'])));
        $price = 0; // Не списываем сейчас
    }

    if ($price > 0) {
        $w = get_wallet($user_id);
        if ((float) $w['balance'] < $price) {
            return [
                'ok' => false,
                'error' => 'Недостаточно средств',
                'need' => $price,
                'balance' => (float) $w['balance'],
            ];
        }
    }

    $db->beginTransaction();
    try {
        if ($price > 0) {
            $w = get_wallet($user_id);
            $bal = round((float) $w['balance'] - $price, 2);
            $db->prepare("UPDATE `wallets` SET `balance`=? WHERE `user_id`=?")->execute([$bal, $user_id]);
            $desc = $pricing['discount_pct'] > 0
                ? "Подписка «{$plan['name']}» со скидкой {$pricing['discount_pct']}%"
                : "Подписка «{$plan['name']}»";
            $db->prepare("INSERT INTO `wallet_transactions` (`user_id`,`type`,`amount`,`balance_after`,`description`) VALUES (?,'charge',?,?,?)")
                ->execute([$user_id, $price, $bal, $desc]);
        }

        if ($is_downgrade && $current['slug'] !== 'free') {
            // Даунгрейд: помечаем текущую как pending_downgrade, новую создаём с будущей датой
            $db->prepare("UPDATE `subscriptions` SET `status`='active' WHERE `user_id`=? AND `status`='active'")->execute([$user_id]);
            // Удаляем старые pending_downgrade если были
            $db->prepare("DELETE FROM `subscriptions` WHERE `user_id`=? AND `status`='pending_downgrade'")->execute([$user_id]);
            $db->prepare("INSERT INTO `subscriptions` (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`) VALUES (?,?,'pending_downgrade',?,?)")
                ->execute([$user_id, $plan['id'], $current['expires_at'], $expires]);
        } else {
            // Апгрейд или переход на free: активируем сразу
            $db->prepare("UPDATE `subscriptions` SET `status`='cancelled',`cancelled_at`=NOW() WHERE `user_id`=? AND `status` IN ('active','pending_downgrade')")->execute([$user_id]);
            $db->prepare("INSERT INTO `subscriptions` (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`) VALUES (?,?,'active',NOW(),?)")
                ->execute([$user_id, $plan['id'], $expires]);
        }

        // Применить лимиты нового тарифа
        $new_max = (int) $plan['max_dashboards'];
        if ($new_max === -1) {
            // Безлимит — разморозить всё
            $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard'")->execute([$user_id]);
        } else {
            // Получить текущие заблокированные
            $lr = $db->prepare("SELECT `entity_id` FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard'");
            $lr->execute([$user_id]);
            $locked_ids = array_column($lr->fetchAll(), 'entity_id');

            // Общее кол-во дашбордов
            $_s = $db->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`=?");
            $_s->execute([$user_id]);
            $total_dash = (int) $_s->fetchColumn();
            $active_count = $total_dash - count($locked_ids);

            if ($active_count > $new_max) {
                // Даунгрейд — заморозить лишние автоматически
                auto_lock_excess_dashboards($user_id, $new_max);
                // Флаг уже сброшен внутри auto_lock_excess_dashboards
            } elseif ($active_count < $new_max && count($locked_ids) > 0) {
                // Апгрейд — разморозить сколько влезает
                $can_unlock = $new_max - $active_count;
                $to_unlock = array_slice($locked_ids, 0, $can_unlock);
                if ($to_unlock) {
                    $ph = implode(',', array_fill(0, count($to_unlock), '?'));
                    $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' AND `entity_id` IN ($ph)")
                        ->execute(array_merge([$user_id], array_map('intval', $to_unlock)));
                }
            }
        }

        $db->commit();
        // Сбросить сессионный rate-limit чтобы следующий запрос сразу проверил состояние
        unset($_SESSION["expiry_checked_{$user_id}"]);

        // Уведомление вне транзакции — чтобы её ошибка не откатила подписку
        try {
            _notify_sub_change($user_id, $current, $plan);
        } catch (Throwable $e) { /* не критично */
        }
        return [
            'ok' => true,
            'plan' => $plan['name'],
            'expires_at' => $expires,
            'is_downgrade' => $is_downgrade,
            'is_upgrade' => $is_upgrade,
            'discount_pct' => $pricing['discount_pct'] ?? 0,
            'price_paid' => $price,
            'needs_downgrade' => !$is_downgrade && check_needs_downgrade($user_id, $plan),
            'scheduled' => $is_downgrade && $current['slug'] !== 'free',
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
function check_needs_downgrade(int $user_id, array $plan): bool
{
    if ((int) $plan['max_dashboards'] === -1)
        return false;
    $s = get_db()->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`=?");
    $s->execute([$user_id]);
    return (int) $s->fetchColumn() > (int) $plan['max_dashboards'];
}
function apply_downgrade(int $user_id, array $keep_ids): array
{
    $db = get_db();
    $l = get_user_limits($user_id);
    if (is_unlimited($l['max_dashboards']))
        return ['ok' => true];
    if (count($keep_ids) > $l['max_dashboards'])
        return ['ok' => false, 'error' => "Максимум {$l['max_dashboards']} дашбордов"];
    $s = $db->prepare("SELECT `id` FROM `dashboards` WHERE `owner_id`=?");
    $s->execute([$user_id]);
    $all = array_column($s->fetchAll(), 'id');
    $lock = array_diff($all, array_map('intval', $keep_ids));
    $db->beginTransaction();
    try {
        if ($keep_ids) {
            $ph = implode(',', array_fill(0, count($keep_ids), '?'));
            $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' AND `entity_id` IN ($ph)")->execute(array_merge([$user_id], array_map('intval', $keep_ids)));
        }
        foreach ($lock as $did)
            $db->prepare("INSERT IGNORE INTO `locked_entities` (`user_id`,`entity_type`,`entity_id`) VALUES (?,'dashboard',?)")->execute([$user_id, $did]);
        $db->commit();
        return ['ok' => true, 'locked' => count($lock), 'active' => count($keep_ids)];
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
function _notify_sub_change(int $user_id, array $old, array $new_plan): void
{
    $order = ['free' => 0, 'level1' => 1, 'level2' => 2];
    $oo = $order[$old['slug'] ?? 'free'] ?? 0;
    $no = $order[$new_plan['slug']] ?? 0;
    $type = $no > $oo ? 'subscription_upgrade' : ($no < $oo ? 'subscription_downgrade' : 'subscription_renewed');
    $meta = json_encode(['old_plan' => $old['plan_name'] ?? 'Free', 'new_plan' => $new_plan['name'], 'expires_at' => date('Y-m-d H:i:s', strtotime("+{$new_plan['duration_days']} days"))]);
    get_db()->prepare("INSERT INTO `notifications` (`user_id`,`type`,`dashboard_name`,`actor_name`,`role`,`meta_json`) VALUES (?,'$type','','Система','',?)")->execute([$user_id, $meta]);
}

// ══════════════════════════════════════════════════════════════
//  ФЛАГ ВЫБОРА ДАУНГРЕЙДА
// ══════════════════════════════════════════════════════════════

/**
 * Проверить нужно ли пользователю показать экран выбора дашбордов.
 * Возвращает true если есть заблокированные И пользователь ещё не сделал выбор.
 */
function needs_downgrade_choice(int $user_id): bool
{
    try {
        $db = get_db();
        // Есть ли заблокированные дашборды?
        $s = $db->prepare("SELECT COUNT(*) FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard'");
        $s->execute([$user_id]);
        if ((int) $s->fetchColumn() === 0)
            return false;

        // Пользователь уже сделал выбор?
        $s2 = $db->prepare("SELECT `downgrade_resolved` FROM `subscriptions`
            WHERE `user_id`=? AND `status`='active' ORDER BY `id` DESC LIMIT 1");
        $s2->execute([$user_id]);
        $row = $s2->fetch();
        return $row && !(bool) $row['downgrade_resolved'];
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Пометить что пользователь уже сделал выбор дашбордов.
 */
function resolve_downgrade_choice(int $user_id): void
{
    try {
        get_db()->prepare("UPDATE `subscriptions` SET `downgrade_resolved`=1
            WHERE `user_id`=? AND `status`='active'")
            ->execute([$user_id]);
    } catch (Throwable $e) {
    }
}

/**
 * Сбросить флаг при новом даунгрейде (чтобы снова показать выбор).
 */
function reset_downgrade_choice(int $user_id): void
{
    try {
        get_db()->prepare("UPDATE `subscriptions` SET `downgrade_resolved`=0
            WHERE `user_id`=? AND `status`='active'")
            ->execute([$user_id]);
    } catch (Throwable $e) {
    }
}


// ══════════════════════════════════════════════════════════════
//  ЗАМОРОЗКА СТРАНИЦ (аналог дашбордов)
// ══════════════════════════════════════════════════════════════

function is_page_locked(int $page_id, int $user_id): bool
{
    try {
        $limits = get_user_limits($user_id);
        if (is_unlimited($limits['max_pages'])) {
            get_db()->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='page'")->execute([$user_id]);
            return false;
        }
        $s = get_db()->prepare("SELECT COUNT(*) FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='page' AND `entity_id`=?");
        $s->execute([$user_id, $page_id]);
        return (int) $s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function auto_lock_excess_pages(int $user_id, int $max_per_dashboard): void
{
    if ($max_per_dashboard === -1)
        return;
    $db = get_db();

    // Получить все дашборды пользователя
    $ds = $db->prepare("SELECT `id` FROM `dashboards` WHERE `owner_id`=?");
    $ds->execute([$user_id]);
    $dashboards = array_column($ds->fetchAll(), 'id');

    foreach ($dashboards as $did) {
        // Страницы этого дашборда, сначала незаблокированные и старые
        $stmt = $db->prepare("
            SELECT p.`id`
            FROM `pages` p
            LEFT JOIN `locked_entities` le
                ON le.`entity_id` = p.`id`
                AND le.`user_id` = ?
                AND le.`entity_type` = 'page'
            WHERE p.`dashboard_id` = ?
            ORDER BY le.`entity_id` IS NULL DESC, p.`created_at` ASC
        ");
        $stmt->execute([$user_id, $did]);
        $pages = array_column($stmt->fetchAll(), 'id');

        $keep = array_slice($pages, 0, $max_per_dashboard);
        $lock = array_slice($pages, $max_per_dashboard);

        foreach ($lock as $pid) {
            $db->prepare("INSERT IGNORE INTO `locked_entities` (`user_id`,`entity_type`,`entity_id`) VALUES (?,'page',?)")
                ->execute([$user_id, (int) $pid]);
        }
    }
    reset_downgrade_choice($user_id);
}

function apply_page_downgrade(int $user_id, array $keep_page_ids): array
{
    $db = get_db();
    $limits = get_user_limits($user_id);
    if (is_unlimited($limits['max_pages']))
        return ['ok' => true];

    // Разблокировать выбранные
    if ($keep_page_ids) {
        $ph = implode(',', array_fill(0, count($keep_page_ids), '?'));
        $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='page' AND `entity_id` IN ($ph)")
            ->execute(array_merge([$user_id], array_map('intval', $keep_page_ids)));
    }
    // Заблокировать остальные
    $all = $db->prepare("
        SELECT p.`id` FROM `pages` p
        JOIN `dashboards` d ON d.`id` = p.`dashboard_id`
        WHERE d.`owner_id` = ?
    ");
    $all->execute([$user_id]);
    $all_ids = array_column($all->fetchAll(), 'id');
    foreach (array_diff($all_ids, array_map('intval', $keep_page_ids)) as $pid) {
        $db->prepare("INSERT IGNORE INTO `locked_entities` (`user_id`,`entity_type`,`entity_id`) VALUES (?,'page',?)")
            ->execute([$user_id, $pid]);
    }
    resolve_downgrade_choice($user_id);
    return ['ok' => true];
}

function get_billing_info_full(int $user_id): array
{
    $db = get_db();
    $sub = get_active_subscription($user_id);

    // Запланированный даунгрейд
    $pending_stmt = $db->prepare("
        SELECT s.*, p.`name` AS plan_name, p.`slug`
        FROM `subscriptions` s JOIN `plans` p ON p.`id`=s.`plan_id`
        WHERE s.`user_id`=? AND s.`status`='pending_downgrade' LIMIT 1
    ");
    $pending_stmt->execute([$user_id]);
    $pending = $pending_stmt->fetch() ?: null;

    return [
        'subscription' => $sub,
        'pending' => $pending,
        'wallet' => get_wallet($user_id),
        'limits' => get_user_limits($user_id),
        'plans' => get_all_plans(),
        'transactions' => get_wallet_transactions($user_id, 10),
    ];
}

function admin_update_plan(int $plan_id, array $data): array
{
    $allowed = ['name', 'price', 'duration_days', 'max_dashboards', 'max_pages', 'max_members', 'is_active'];
    $sets = [];
    $vals = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "`$f`=?";
            $vals[] = $data[$f];
        }
    }
    if (!$sets)
        return ['ok' => false, 'error' => 'Нет данных'];
    $vals[] = $plan_id;
    get_db()->prepare("UPDATE `plans` SET " . implode(',', $sets) . " WHERE `id`=?")->execute($vals);
    return ['ok' => true];
}
function admin_topup_user(int $user_id, float $amount): array
{
    return topup_wallet($user_id, $amount, 'Пополнение администратором');
}