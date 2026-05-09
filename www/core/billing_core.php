<?php
/**
 * core/billing.php — система подписок, кошелька и лимитов
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_all_plans(): array {
    return get_db()->query("SELECT * FROM `plans` ORDER BY `sort_order` ASC")->fetchAll();
}
function get_plan_by_slug(string $slug): ?array {
    $s = get_db()->prepare("SELECT * FROM `plans` WHERE `slug`=? LIMIT 1");
    $s->execute([$slug]); return $s->fetch() ?: null;
}
function get_plan_by_id(int $id): ?array {
    $s = get_db()->prepare("SELECT * FROM `plans` WHERE `id`=? LIMIT 1");
    $s->execute([$id]); return $s->fetch() ?: null;
}
function get_active_subscription(int $user_id): array {

    $fallback = ['slug'=>'free','plan_name'=>'Free','max_dashboards'=>3,'max_pages'=>5,'max_members'=>1,'price'=>0,'status'=>'active'];
    try {
        $db = get_db();
        // Проверить что таблицы существуют
        $tables = $db->query("SHOW TABLES LIKE 'subscriptions'")->fetchAll();
        if (empty($tables)) return $fallback;
        $s = $db->prepare("SELECT s.*,p.`slug`,p.`name` AS plan_name,p.`max_dashboards`,p.`max_pages`,p.`max_members`,p.`price`
            FROM `subscriptions` s JOIN `plans` p ON p.`id`=s.`plan_id`
            WHERE s.`user_id`=? AND s.`status`='active' AND s.`expires_at`>NOW()
            ORDER BY p.`sort_order` DESC LIMIT 1");
        $s->execute([$user_id]);
        $sub = $s->fetch();
        if (!$sub) { ensure_free_subscription($user_id); $s->execute([$user_id]); $sub = $s->fetch(); }
        $result = $sub ?: $fallback;
        return $result;
    } catch (Throwable $e) {
        return $fallback;
    }
}
function ensure_free_subscription(int $user_id): void {
    try {
    $db = get_db(); $free = get_plan_by_slug('free'); if (!$free) return;
    $db->prepare("INSERT IGNORE INTO `wallets` (`user_id`,`balance`) VALUES (?,0.00)")->execute([$user_id]);
    $s = $db->prepare("SELECT COUNT(*) FROM `subscriptions` WHERE `user_id`=? AND `plan_id`=? AND `status`='active' AND `expires_at`>NOW()");
    $s->execute([$user_id,$free['id']]); if ((int)$s->fetchColumn()>0) return;
    $db->prepare("INSERT INTO `subscriptions` (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL 100 YEAR))")
       ->execute([$user_id,$free['id']]);
    } catch (Throwable $e) { /* таблицы ещё не готовы */ }
}
function get_user_limits(int $user_id): array {
    $sub = get_active_subscription($user_id);
    return ['max_dashboards'=>(int)$sub['max_dashboards'],'max_pages'=>(int)$sub['max_pages'],'max_members'=>(int)$sub['max_members'],'plan_slug'=>$sub['slug'],'plan_name'=>$sub['plan_name']];
}
function is_unlimited(int $val): bool { return $val===-1; }
function can_create_dashboard(int $user_id): array {
    $l=get_user_limits($user_id); if(is_unlimited($l['max_dashboards'])) return ['ok'=>true];
    $s=get_db()->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`=?"); $s->execute([$user_id]);
    if((int)$s->fetchColumn()>=$l['max_dashboards']) return ['ok'=>false,'error'=>"Лимит тарифа «{$l['plan_name']}»: {$l['max_dashboards']} дашбордов","upgrade"=>true];
    return ['ok'=>true];
}
function can_create_page(int $dashboard_id, int $user_id): array {
    $l=get_user_limits($user_id); if(is_unlimited($l['max_pages'])) return ['ok'=>true];
    $s=get_db()->prepare("SELECT COUNT(*) FROM `pages` WHERE `dashboard_id`=?"); $s->execute([$dashboard_id]);
    if((int)$s->fetchColumn()>=$l['max_pages']) return ['ok'=>false,'error'=>"Лимит тарифа «{$l['plan_name']}»: {$l['max_pages']} страниц","upgrade"=>true];
    return ['ok'=>true];
}
function can_invite_member(int $dashboard_id, int $user_id): array {
    $l=get_user_limits($user_id); if(is_unlimited($l['max_members'])) return ['ok'=>true];
    $s=get_db()->prepare("SELECT COUNT(*) FROM `dashboard_access` WHERE `dashboard_id`=?"); $s->execute([$dashboard_id]);
    if((int)$s->fetchColumn()>=$l['max_members']) return ['ok'=>false,'error'=>"Лимит тарифа «{$l['plan_name']}»: {$l['max_members']} участников","upgrade"=>true];
    return ['ok'=>true];
}
function is_dashboard_locked(int $dashboard_id, int $user_id): bool {
    $s=get_db()->prepare("SELECT COUNT(*) FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' AND `entity_id`=?");
    $s->execute([$user_id,$dashboard_id]); return (int)$s->fetchColumn()>0;
}
function get_wallet(int $user_id): array {
    $db=get_db(); $db->prepare("INSERT IGNORE INTO `wallets` (`user_id`,`balance`) VALUES (?,0.00)")->execute([$user_id]);
    $s=$db->prepare("SELECT * FROM `wallets` WHERE `user_id`=?"); $s->execute([$user_id]); return $s->fetch();
}
function topup_wallet(int $user_id, float $amount, string $desc='Пополнение счёта'): array {
    if($amount<=0) return ['ok'=>false,'error'=>'Сумма должна быть больше нуля'];
    if($amount>100000) return ['ok'=>false,'error'=>'Максимум 100 000 ₽'];
    $db=get_db(); $db->beginTransaction();
    try {
        $w=get_wallet($user_id); $bal=round((float)$w['balance']+$amount,2);
        $db->prepare("UPDATE `wallets` SET `balance`=? WHERE `user_id`=?")->execute([$bal,$user_id]);
        $db->prepare("INSERT INTO `wallet_transactions` (`user_id`,`type`,`amount`,`balance_after`,`description`) VALUES (?,'topup',?,?,?)")->execute([$user_id,$amount,$bal,$desc]);
        $db->commit(); return ['ok'=>true,'balance'=>$bal];
    } catch(Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}
function get_wallet_transactions(int $user_id, int $limit=20): array {
    $s=get_db()->prepare("SELECT * FROM `wallet_transactions` WHERE `user_id`=? ORDER BY `created_at` DESC LIMIT ?");
    $s->execute([$user_id,$limit]); return $s->fetchAll();
}
function activate_subscription(int $user_id, string $plan_slug): array {
    $db=get_db(); $plan=get_plan_by_slug($plan_slug);
    if(!$plan||!$plan['is_active']) return ['ok'=>false,'error'=>'Тариф не найден'];
    $price=(float)$plan['price'];
    if($price>0) { $w=get_wallet($user_id); if((float)$w['balance']<$price) return ['ok'=>false,'error'=>'Недостаточно средств','need'=>$price,'balance'=>(float)$w['balance']]; }
    $current=get_active_subscription($user_id);
    $db->beginTransaction();
    try {
        if($price>0) {
            $w=get_wallet($user_id); $bal=round((float)$w['balance']-$price,2);
            $db->prepare("UPDATE `wallets` SET `balance`=? WHERE `user_id`=?")->execute([$bal,$user_id]);
            $db->prepare("INSERT INTO `wallet_transactions` (`user_id`,`type`,`amount`,`balance_after`,`description`) VALUES (?,'charge',?,?,?)")->execute([$user_id,$price,$bal,"Подписка «{$plan['name']}»"]);
        }
        $db->prepare("UPDATE `subscriptions` SET `status`='cancelled',`cancelled_at`=NOW() WHERE `user_id`=? AND `status`='active'")->execute([$user_id]);
        $expires=date('Y-m-d H:i:s',strtotime("+{$plan['duration_days']} days"));
        $db->prepare("INSERT INTO `subscriptions` (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`) VALUES (?,?,'active',NOW(),?)")->execute([$user_id,$plan['id'],$expires]);

        // При апгрейде — снять заморозку с дашбордов которые теперь вмещаются в новый лимит
        $new_max = (int)$plan['max_dashboards'];
        if ($new_max === -1) {
            // Безлимит — разморозить всё
            $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard'")->execute([$user_id]);
        } else {
            // Частичный апгрейд — разморозить столько дашбордов сколько теперь влезает
            $locked_rows = $db->prepare("SELECT `entity_id` FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' ORDER BY `entity_id` ASC");
            $locked_rows->execute([$user_id]);
            $locked_ids = array_column($locked_rows->fetchAll(), 'entity_id');

            // Сколько активных дашбордов уже есть (не заблокированных)
            $_s = $db->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`=?");
            $_s->execute([$user_id]);
            $total_dash = (int)$_s->fetchColumn();
            $active_count = $total_dash - count($locked_ids);
            $can_unlock = max(0, $new_max - $active_count);

            // Разморозить первые $can_unlock из заблокированных
            $to_unlock = array_slice($locked_ids, 0, $can_unlock);
            if ($to_unlock) {
                $ph = implode(',', array_fill(0, count($to_unlock), '?'));
                $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' AND `entity_id` IN ($ph)")
                   ->execute(array_merge([$user_id], array_map('intval', $to_unlock)));
            }
        }

        $db->commit();
        // Уведомление вне транзакции — чтобы её ошибка не откатила подписку
        try { _notify_sub_change($user_id,$current,$plan); } catch(Throwable $e) { /* не критично */ }
        return ['ok'=>true,'plan'=>$plan['name'],'expires_at'=>$expires,'needs_downgrade'=>check_needs_downgrade($user_id,$plan)];
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}
function check_needs_downgrade(int $user_id, array $plan): bool {
    if((int)$plan['max_dashboards']===-1) return false;
    $s=get_db()->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`=?"); $s->execute([$user_id]);
    return (int)$s->fetchColumn()>(int)$plan['max_dashboards'];
}
function apply_downgrade(int $user_id, array $keep_ids): array {
    $db=get_db(); $l=get_user_limits($user_id); if(is_unlimited($l['max_dashboards'])) return ['ok'=>true];
    if(count($keep_ids)>$l['max_dashboards']) return ['ok'=>false,'error'=>"Максимум {$l['max_dashboards']} дашбордов"];
    $s=$db->prepare("SELECT `id` FROM `dashboards` WHERE `owner_id`=?"); $s->execute([$user_id]);
    $all=array_column($s->fetchAll(),'id'); $lock=array_diff($all,array_map('intval',$keep_ids));
    $db->beginTransaction();
    try {
        if($keep_ids) { $ph=implode(',',array_fill(0,count($keep_ids),'?')); $db->prepare("DELETE FROM `locked_entities` WHERE `user_id`=? AND `entity_type`='dashboard' AND `entity_id` IN ($ph)")->execute(array_merge([$user_id],array_map('intval',$keep_ids))); }
        foreach($lock as $did) $db->prepare("INSERT IGNORE INTO `locked_entities` (`user_id`,`entity_type`,`entity_id`) VALUES (?,'dashboard',?)")->execute([$user_id,$did]);
        $db->commit(); return ['ok'=>true,'locked'=>count($lock),'active'=>count($keep_ids)];
    } catch(Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}
function _notify_sub_change(int $user_id, array $old, array $new_plan): void {
    $order=['free'=>0,'level1'=>1,'level2'=>2];
    $oo=$order[$old['slug']??'free']??0; $no=$order[$new_plan['slug']]??0;
    $type=$no>$oo?'subscription_upgrade':($no<$oo?'subscription_downgrade':'subscription_renewed');
    $meta=json_encode(['old_plan'=>$old['plan_name']??'Free','new_plan'=>$new_plan['name'],'expires_at'=>date('Y-m-d H:i:s',strtotime("+{$new_plan['duration_days']} days"))]);
    get_db()->prepare("INSERT INTO `notifications` (`user_id`,`type`,`dashboard_name`,`actor_name`,`role`,`meta_json`) VALUES (?,'$type','','Система','',?)")->execute([$user_id,$meta]);
}
function admin_update_plan(int $plan_id, array $data): array {
    $allowed=['name','price','duration_days','max_dashboards','max_pages','max_members','is_active'];
    $sets=[]; $vals=[];
    foreach($allowed as $f) { if(array_key_exists($f,$data)) { $sets[]="`$f`=?"; $vals[]=$data[$f]; } }
    if(!$sets) return ['ok'=>false,'error'=>'Нет данных'];
    $vals[]=$plan_id; get_db()->prepare("UPDATE `plans` SET ".implode(',',$sets)." WHERE `id`=?")->execute($vals);
    return ['ok'=>true];
}
function admin_topup_user(int $user_id, float $amount): array {
    return topup_wallet($user_id,$amount,'Пополнение администратором');
}