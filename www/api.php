<?php
/**
 * api.php
 * Point of Creation — REST-like JSON API
 *
 * Все запросы: POST /api.php
 * Body: { "action": "...", "csrf": "...", ...params }
 * Headers: X-CSRF-Token: <token>
 *
 * ── Дашборды ──────────────────────────────
 *   create_dashboard   name
 *   rename_dashboard   id, name
 *   delete_dashboard   id
 *   list_dashboards    —
 *
 * ── Страницы ──────────────────────────────
 *   create_page        dashboard_id, name
 *   rename_page        id, name
 *   delete_page        id
 *   reorder_pages      dashboard_id, page_ids[]
 *
 * ── Виджеты ───────────────────────────────
 *   save_widget        page_id, type, title, settings_json, position_data, [id]
 *   delete_widget      id
 *   update_content     id, settings_json, [title]
 *   save_all           page_id, widgets[]
 *
 * ── Корпоративный доступ ──────────────────
 *   invite_user        dashboard_id, email, role (viewer|editor)
 *   remove_access      dashboard_id, user_id
 *   list_access        dashboard_id
 *   change_access_role dashboard_id, user_id, role
 *
 * ── Контекст ──────────────────────────────
 *   switch_dashboard   dashboard_id
 *   switch_page        page_id
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/access.php';
require_once __DIR__ . '/core/billing_core.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf_body   = $body['csrf'] ?? '';

if (!verify_csrf($csrf_header) && !verify_csrf($csrf_body)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action  = $body['action'] ?? '';
$user_id = (int) current_user()['id'];
$db      = get_db();

// ────────────────────────────────────────────────────────────────
//  Helpers
// ────────────────────────────────────────────────────────────────


// ──────────────────────────────────────────────────────────────
//  Создать уведомление
// ──────────────────────────────────────────────────────────────
function create_notification(
    PDO    $db,
    int    $target_user_id,
    string $type,              // invited | role_changed | removed
    int    $dashboard_id,
    string $dashboard_name,
    string $actor_name,
    string $role = ''
): void {
    $db->prepare("
        INSERT INTO `notifications`
            (`user_id`, `type`, `dashboard_id`, `dashboard_name`, `actor_name`, `role`)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$target_user_id, $type, $dashboard_id, $dashboard_name, $actor_name, $role]);
}

function clean_json(mixed $raw): string
{
    if (is_array($raw))  return json_encode($raw, JSON_UNESCAPED_UNICODE);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return $decoded !== null ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '{}';
    }
    return '{}';
}

// ────────────────────────────────────────────────────────────────
//  Router
// ────────────────────────────────────────────────────────────────

try {
    switch ($action) {

        // ════════════════════════════════════════════════════════
        //  ДАШБОРДЫ
        // ════════════════════════════════════════════════════════

        case 'list_dashboards': {
            $dashboards = get_user_dashboards($user_id);
            // Добавляем количество страниц к каждому дашборду
            foreach ($dashboards as &$d) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM `pages` WHERE `dashboard_id` = ?
                ");
                $stmt->execute([$d['id']]);
                $d['page_count'] = (int) $stmt->fetchColumn();
                $d['id'] = (int) $d['id'];
            }
            unset($d);
            echo json_encode(['ok' => true, 'dashboards' => $dashboards]);
            break;
        }

        case 'create_dashboard': {
            try { $lc=can_create_dashboard($user_id); } catch(Throwable $e) { $lc=['ok'=>true]; }
            if(!$lc['ok']){http_response_code(402);echo json_encode(['ok'=>false,'error'=>$lc['error'],'upgrade'=>true]);break;}
            $name      = substr(trim($body['name'] ?? 'Новый дашборд'), 0, 120);
            $is_shared = (int)(bool)($body['is_shared'] ?? false);

            if ($name === '') {
                $name = 'Новый дашборд';
            }

            $db->beginTransaction();

            $db->prepare("
                INSERT INTO `dashboards` (`owner_id`, `name`, `is_shared`)
                VALUES (?, ?, ?)
            ")->execute([$user_id, $name, $is_shared]);

            $dashboard_id = (int) $db->lastInsertId();

            // Создать первую страницу автоматически
            $db->prepare("
                INSERT INTO `pages` (`dashboard_id`, `name`, `order_index`)
                VALUES (?, 'Главная', 0)
            ")->execute([$dashboard_id]);

            $page_id = (int) $db->lastInsertId();

            $db->commit();

            echo json_encode([
                'ok'           => true,
                'dashboard_id' => $dashboard_id,
                'page_id'      => $page_id,
            ]);
            break;
        }

        case 'rename_dashboard': {
            $dashboard_id = (int)($body['id'] ?? 0);
            $name         = substr(trim($body['name'] ?? ''), 0, 120);

            if ($dashboard_id < 1 || $name === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверные параметры']);
                break;
            }

            require_dashboard_owner($dashboard_id, $user_id);

            $db->prepare("UPDATE `dashboards` SET `name` = ? WHERE `id` = ?")
               ->execute([$name, $dashboard_id]);

            echo json_encode(['ok' => true]);
            break;
        }

        case 'delete_dashboard': {
            $dashboard_id = (int)($body['id'] ?? 0);

            if ($dashboard_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный id']);
                break;
            }

            require_dashboard_owner($dashboard_id, $user_id);

            // Проверяем, что это не последний дашборд пользователя
            $count = (int) $db->prepare("
                SELECT COUNT(*) FROM `dashboards` WHERE `owner_id` = ?
            ")->execute([$user_id]) ? $db->query("
                SELECT COUNT(*) FROM `dashboards` WHERE `owner_id` = {$user_id}
            ")->fetchColumn() : 1;

            $stmt = $db->prepare("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id` = ?");
            $stmt->execute([$user_id]);
            $own_count = (int) $stmt->fetchColumn();

            if ($own_count <= 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Нельзя удалить последний дашборд']);
                break;
            }

            $db->prepare("DELETE FROM `dashboards` WHERE `id` = ? AND `owner_id` = ?")
               ->execute([$dashboard_id, $user_id]);

            echo json_encode(['ok' => true]);
            break;
        }

        // ════════════════════════════════════════════════════════
        //  СТРАНИЦЫ
        // ════════════════════════════════════════════════════════

        case 'create_page': {
            $dashboard_id = (int)($body['dashboard_id'] ?? 0);
            $name         = substr(trim($body['name'] ?? 'Страница'), 0, 120);

            if ($dashboard_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный dashboard_id']);
                break;
            }

            // Проверяем права: только owner или editor могут создавать страницы
            require_dashboard_edit($dashboard_id, $user_id);
            try { if (is_dashboard_locked($dashboard_id, $user_id)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Дашборд заморожен','locked'=>true]); break; } } catch(Throwable $e) {}
            try { $pl=can_create_page($dashboard_id,$user_id); } catch(Throwable $e) { $pl=['ok'=>true]; }
            if(!$pl['ok']){http_response_code(402);echo json_encode(['ok'=>false,'error'=>$pl['error'],'upgrade'=>true]);break;}

            // Получить следующий order_index
            $stmt = $db->prepare("
                SELECT COALESCE(MAX(`order_index`), -1) + 1
                FROM `pages`
                WHERE `dashboard_id` = ?
            ");
            $stmt->execute([$dashboard_id]);
            $next_order = (int) $stmt->fetchColumn();

            $db->prepare("
                INSERT INTO `pages` (`dashboard_id`, `name`, `order_index`)
                VALUES (?, ?, ?)
            ")->execute([$dashboard_id, $name ?: 'Страница', $next_order]);

            $page_id = (int) $db->lastInsertId();

            echo json_encode([
                'ok'          => true,
                'page_id'     => $page_id,
                'name'        => $name,
                'order_index' => $next_order,
            ]);
            break;
        }

        case 'rename_page': {
            $page_id = (int)($body['id'] ?? 0);
            $name    = substr(trim($body['name'] ?? ''), 0, 120);

            if ($page_id < 1 || $name === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверные параметры']);
                break;
            }

            $dashboard_id = get_dashboard_id_by_page($page_id);
            if (!$dashboard_id) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Страница не найдена']);
                break;
            }

            require_dashboard_edit($dashboard_id, $user_id);

            $db->prepare("UPDATE `pages` SET `name` = ? WHERE `id` = ?")
               ->execute([$name, $page_id]);

            echo json_encode(['ok' => true]);
            break;
        }

        case 'delete_page': {
            $page_id = (int)($body['id'] ?? 0);

            if ($page_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный id']);
                break;
            }

            $dashboard_id = get_dashboard_id_by_page($page_id);
            if (!$dashboard_id) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Страница не найдена']);
                break;
            }

            require_dashboard_edit($dashboard_id, $user_id);

            // Нельзя удалить последнюю страницу
            $stmt = $db->prepare("SELECT COUNT(*) FROM `pages` WHERE `dashboard_id` = ?");
            $stmt->execute([$dashboard_id]);
            if ((int)$stmt->fetchColumn() <= 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Нельзя удалить единственную страницу дашборда']);
                break;
            }

            $db->prepare("DELETE FROM `pages` WHERE `id` = ?")->execute([$page_id]);

            echo json_encode(['ok' => true]);
            break;
        }

        case 'reorder_pages': {
            $dashboard_id = (int)($body['dashboard_id'] ?? 0);
            $page_ids     = $body['page_ids'] ?? [];

            if ($dashboard_id < 1 || !is_array($page_ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверные параметры']);
                break;
            }

            require_dashboard_edit($dashboard_id, $user_id);

            $db->beginTransaction();
            foreach ($page_ids as $idx => $pid) {
                $db->prepare("
                    UPDATE `pages` SET `order_index` = ?
                    WHERE `id` = ? AND `dashboard_id` = ?
                ")->execute([(int)$idx, (int)$pid, $dashboard_id]);
            }
            $db->commit();

            echo json_encode(['ok' => true]);
            break;
        }

        // ════════════════════════════════════════════════════════
        //  ВИДЖЕТЫ
        // ════════════════════════════════════════════════════════

        case 'save_widget': {
            $page_id       = (int)($body['page_id'] ?? 0);
            $widget_id     = (int)($body['id'] ?? 0);
            $type          = substr(trim($body['type'] ?? 'note'), 0, 32);
            $title         = substr(trim($body['title'] ?? ''), 0, 120);
            $settings_json = clean_json($body['settings_json'] ?? $body['content'] ?? '{}');
            $position_data = clean_json($body['position_data'] ?? [
                'w'          => max(1, min(4, (int)($body['position_w'] ?? 1))),
                'h'          => max(1, min(3, (int)($body['position_h'] ?? 1))),
                'sort_order' => (int)($body['sort_order'] ?? 0),
            ]);

            if ($widget_id > 0) {
                // UPDATE — проверяем права через дашборд виджета
                $dashboard_id = get_dashboard_id_by_widget($widget_id);
                if (!$dashboard_id) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => 'Виджет не найден']);
                    break;
                }
                require_dashboard_edit($dashboard_id, $user_id);
                try { if (is_dashboard_locked($dashboard_id, $user_id)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Дашборд заморожен','locked'=>true]); break; } } catch(Throwable $e) {}

                $db->prepare("
                    UPDATE `widgets`
                    SET `type` = ?, `title` = ?, `settings_json` = ?, `position_data` = ?
                    WHERE `id` = ?
                ")->execute([$type, $title, $settings_json, $position_data, $widget_id]);

                echo json_encode(['ok' => true, 'id' => $widget_id]);
            } else {
                // INSERT
                if ($page_id < 1) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Необходимо указать page_id']);
                    break;
                }
                $dashboard_id = get_dashboard_id_by_page($page_id);
                if (!$dashboard_id) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => 'Страница не найдена']);
                    break;
                }
                require_dashboard_edit($dashboard_id, $user_id);

                $db->prepare("
                    INSERT INTO `widgets` (`page_id`, `type`, `title`, `settings_json`, `position_data`)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$page_id, $type, $title, $settings_json, $position_data]);

                echo json_encode(['ok' => true, 'id' => (int) $db->lastInsertId()]);
            }
            break;
        }

        case 'delete_widget': {
            $widget_id = (int)($body['id'] ?? 0);

            if ($widget_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный id']);
                break;
            }

            $dashboard_id = get_dashboard_id_by_widget($widget_id);
            if (!$dashboard_id) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Виджет не найден']);
                break;
            }

            require_dashboard_edit($dashboard_id, $user_id);
            try { if (is_dashboard_locked($dashboard_id, $user_id)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Дашборд заморожен','locked'=>true]); break; } } catch(Throwable $e) {}

            $db->prepare("DELETE FROM `widgets` WHERE `id` = ?")->execute([$widget_id]);

            echo json_encode(['ok' => true]);
            break;
        }

        case 'update_content': {
            $widget_id     = (int)($body['id'] ?? 0);
            $settings_json = clean_json($body['settings_json'] ?? $body['content'] ?? '{}');
            $title         = isset($body['title']) ? substr(trim($body['title']), 0, 120) : null;

            if ($widget_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный id']);
                break;
            }

            $dashboard_id = get_dashboard_id_by_widget($widget_id);
            if (!$dashboard_id) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Виджет не найден']);
                break;
            }

            require_dashboard_edit($dashboard_id, $user_id);

            if ($title !== null) {
                $db->prepare("
                    UPDATE `widgets` SET `settings_json` = ?, `title` = ? WHERE `id` = ?
                ")->execute([$settings_json, $title, $widget_id]);
            } else {
                $db->prepare("
                    UPDATE `widgets` SET `settings_json` = ? WHERE `id` = ?
                ")->execute([$settings_json, $widget_id]);
            }

            echo json_encode(['ok' => true]);
            break;
        }

        case 'save_all': {
            $page_id  = (int)($body['page_id'] ?? 0);
            $incoming = $body['widgets'] ?? [];

            if ($page_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Необходимо указать page_id']);
                break;
            }

            $dashboard_id = get_dashboard_id_by_page($page_id);
            if (!$dashboard_id) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Страница не найдена']);
                break;
            }

            require_dashboard_edit($dashboard_id, $user_id);
            try { if (is_dashboard_locked($dashboard_id, $user_id)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Дашборд заморожен','locked'=>true]); break; } } catch(Throwable $e) {}

            if (!is_array($incoming)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'widgets must be array']);
                break;
            }

            $db->beginTransaction();

            $stmt = $db->prepare("SELECT `id` FROM `widgets` WHERE `page_id` = ?");
            $stmt->execute([$page_id]);
            $existing_ids = array_column($stmt->fetchAll(), 'id');
            $seen_ids     = [];

            foreach ($incoming as $idx => $w) {
                $wid           = (int)($w['id'] ?? 0);
                $type          = substr(trim($w['type']  ?? 'note'), 0, 32);
                $title         = substr(trim($w['title'] ?? ''),     0, 120);
                $settings_json = clean_json($w['settings_json'] ?? $w['content'] ?? '{}');
                $position_data = clean_json([
                    'w'          => max(1, min(4, (int)($w['position_w'] ?? $w['w'] ?? 1))),
                    'h'          => max(1, min(3, (int)($w['position_h'] ?? $w['h'] ?? 1))),
                    'sort_order' => $idx,
                ]);

                if ($wid > 0 && in_array($wid, $existing_ids, true)) {
                    $db->prepare("
                        UPDATE `widgets`
                        SET `type`=?,`title`=?,`settings_json`=?,`position_data`=?
                        WHERE `id`=? AND `page_id`=?
                    ")->execute([$type, $title, $settings_json, $position_data, $wid, $page_id]);
                    $seen_ids[] = $wid;
                } else {
                    $db->prepare("
                        INSERT INTO `widgets` (`page_id`,`type`,`title`,`settings_json`,`position_data`)
                        VALUES (?,?,?,?,?)
                    ")->execute([$page_id, $type, $title, $settings_json, $position_data]);
                    $seen_ids[] = (int) $db->lastInsertId();
                }
            }

            $orphans = array_diff($existing_ids, $seen_ids);
            if ($orphans) {
                $ph = implode(',', array_fill(0, count($orphans), '?'));
                $db->prepare("DELETE FROM `widgets` WHERE `id` IN ($ph)")->execute($orphans);
            }

            $db->commit();
            echo json_encode(['ok' => true, 'deleted' => count($orphans)]);
            break;
        }

        // ════════════════════════════════════════════════════════
        //  КОРПОРАТИВНЫЙ ДОСТУП
        // ════════════════════════════════════════════════════════

        case 'invite_user': {
            $dashboard_id = (int)($body['dashboard_id'] ?? 0);
            $email        = trim($body['email'] ?? '');
            $role         = $body['role'] ?? 'viewer';

            if ($dashboard_id < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверные параметры']);
                break;
            }
            if (!in_array($role, ['viewer', 'editor'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Роль должна быть viewer или editor']);
                break;
            }

            require_dashboard_owner($dashboard_id, $user_id);

            // Найти пользователя по email
            $stmt = $db->prepare("SELECT `id`, `username` FROM `users` WHERE `email` = ?");
            $stmt->execute([$email]);
            $invitee = $stmt->fetch();

            if (!$invitee) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Пользователь с таким email не найден']);
                break;
            }

            if ((int)$invitee['id'] === $user_id) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Нельзя пригласить самого себя']);
                break;
            }

            // Upsert доступа
            $db->prepare("
                INSERT INTO `dashboard_access` (`dashboard_id`, `user_id`, `role`)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `role` = VALUES(`role`)
            ")->execute([$dashboard_id, (int)$invitee['id'], $role]);

            // Пометить дашборд как shared
            $db->prepare("UPDATE `dashboards` SET `is_shared` = 1 WHERE `id` = ?")
               ->execute([$dashboard_id]);

            // Получить название дашборда
            $dname = $db->prepare("SELECT `name` FROM `dashboards` WHERE `id` = ?");
            $dname->execute([$dashboard_id]);
            $drow = $dname->fetch();
            $dashboard_name = $drow ? $drow['name'] : 'Дашборд';

            // Уведомление приглашённому
            create_notification(
                $db,
                (int)$invitee['id'],
                'invited',
                $dashboard_id,
                $dashboard_name,
                current_user()['username'],
                $role
            );

            echo json_encode([
                'ok'       => true,
                'username' => $invitee['username'],
                'role'     => $role,
            ]);
            break;
        }

        case 'remove_access': {
            $dashboard_id  = (int)($body['dashboard_id'] ?? 0);
            $target_user   = (int)($body['user_id'] ?? 0);

            if ($dashboard_id < 1 || $target_user < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверные параметры']);
                break;
            }

            require_dashboard_owner($dashboard_id, $user_id);

            $db->prepare("
                DELETE FROM `dashboard_access`
                WHERE `dashboard_id` = ? AND `user_id` = ?
            ")->execute([$dashboard_id, $target_user]);

            // Если больше никого нет — сбросить is_shared
            $stmt = $db->prepare("SELECT COUNT(*) FROM `dashboard_access` WHERE `dashboard_id` = ?");
            $stmt->execute([$dashboard_id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $db->prepare("UPDATE `dashboards` SET `is_shared` = 0 WHERE `id` = ?")
                   ->execute([$dashboard_id]);
            }

            // Уведомление удалённому пользователю
            $dname2 = $db->prepare("SELECT `name` FROM `dashboards` WHERE `id` = ?");
            $dname2->execute([$dashboard_id]);
            $drow2 = $dname2->fetch();
            $dashboard_name2 = $drow2 ? $drow2['name'] : 'Дашборд';

            create_notification(
                $db,
                $target_user,
                'removed',
                $dashboard_id,
                $dashboard_name2,
                current_user()['username']
            );

            echo json_encode(['ok' => true]);
            break;
        }

        case 'list_access': {
            $dashboard_id = (int)($body['dashboard_id'] ?? 0);

            if ($dashboard_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный dashboard_id']);
                break;
            }

            require_dashboard_view($dashboard_id, $user_id);

            $stmt = $db->prepare("
                SELECT u.`id`, u.`username`, u.`email`, da.`role`, da.`invited_at`
                FROM `dashboard_access` da
                JOIN `users` u ON u.`id` = da.`user_id`
                WHERE da.`dashboard_id` = ?
                ORDER BY da.`invited_at` ASC
            ");
            $stmt->execute([$dashboard_id]);
            $members = $stmt->fetchAll();

            foreach ($members as &$m) {
                $m['id'] = (int)$m['id'];
            }
            unset($m);

            echo json_encode(['ok' => true, 'members' => $members]);
            break;
        }

        case 'change_access_role': {
            $dashboard_id = (int)($body['dashboard_id'] ?? 0);
            $target_user  = (int)($body['user_id'] ?? 0);
            $role         = $body['role'] ?? '';

            if ($dashboard_id < 1 || $target_user < 1 || !in_array($role, ['viewer','editor'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверные параметры']);
                break;
            }

            require_dashboard_owner($dashboard_id, $user_id);

            $db->prepare("
                UPDATE `dashboard_access` SET `role` = ?
                WHERE `dashboard_id` = ? AND `user_id` = ?
            ")->execute([$role, $dashboard_id, $target_user]);

            // Уведомление пользователю о смене роли
            $dname3 = $db->prepare("SELECT `name` FROM `dashboards` WHERE `id` = ?");
            $dname3->execute([$dashboard_id]);
            $drow3 = $dname3->fetch();
            $dashboard_name3 = $drow3 ? $drow3['name'] : 'Дашборд';

            create_notification(
                $db,
                $target_user,
                'role_changed',
                $dashboard_id,
                $dashboard_name3,
                current_user()['username'],
                $role
            );

            echo json_encode(['ok' => true]);
            break;
        }

        // ════════════════════════════════════════════════════════
        //  ПЕРЕКЛЮЧЕНИЕ КОНТЕКСТА
        // ════════════════════════════════════════════════════════

        case 'switch_dashboard': {
            $dashboard_id = (int)($body['dashboard_id'] ?? 0);

            if ($dashboard_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный dashboard_id']);
                break;
            }

            require_dashboard_view($dashboard_id, $user_id);

            // Проверить не заблокирован ли дашборд (downgrade)
            try {
                if (is_dashboard_locked($dashboard_id, $user_id)) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => 'Этот дашборд заморожен. Обновите тариф или выберите другие активные дашборды.', 'locked' => true]);
                    break;
                }
            } catch (Throwable $e) { /* таблица ещё не создана */ }

            // Найти первую страницу дашборда
            $stmt = $db->prepare("
                SELECT `id` FROM `pages`
                WHERE `dashboard_id` = ?
                ORDER BY `order_index` ASC, `id` ASC
                LIMIT 1
            ");
            $stmt->execute([$dashboard_id]);
            $page = $stmt->fetch();

            $_SESSION['active_dashboard_id'] = $dashboard_id;
            $_SESSION['active_page_id']      = $page ? (int)$page['id'] : null;

            echo json_encode([
                'ok'           => true,
                'dashboard_id' => $dashboard_id,
                'page_id'      => $_SESSION['active_page_id'],
            ]);
            break;
        }

        case 'switch_page': {
            $page_id = (int)($body['page_id'] ?? 0);

            if ($page_id < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Неверный page_id']);
                break;
            }

            $dashboard_id = get_dashboard_id_by_page($page_id);
            if (!$dashboard_id) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Страница не найдена']);
                break;
            }

            require_dashboard_view($dashboard_id, $user_id);

            $_SESSION['active_page_id'] = $page_id;

            echo json_encode(['ok' => true, 'page_id' => $page_id]);
            break;
        }


        // ════════════════════════════════════════════════════════
        //  УВЕДОМЛЕНИЯ
        // ════════════════════════════════════════════════════════

        case 'get_notifications': {
            $stmt = $db->prepare("
                SELECT `id`, `type`, `dashboard_id`, `dashboard_name`,
                       `actor_name`, `role`, `is_read`, `created_at`
                FROM `notifications`
                WHERE `user_id` = ?
                ORDER BY `created_at` DESC
                LIMIT 50
            ");
            $stmt->execute([$user_id]);
            $notifs = $stmt->fetchAll();

            $unread = 0;
            foreach ($notifs as &$n) {
                $n['id'] = (int)$n['id'];
                $n['dashboard_id'] = (int)$n['dashboard_id'];
                $n['is_read'] = (bool)$n['is_read'];
                if (!$n['is_read']) $unread++;
            }
            unset($n);

            echo json_encode(['ok' => true, 'notifications' => $notifs, 'unread' => $unread]);
            break;
        }

        case 'mark_notifications_read': {
            $ids = $body['ids'] ?? [];
            if ($ids === 'all') {
                $db->prepare("UPDATE `notifications` SET `is_read` = 1 WHERE `user_id` = ?")
                   ->execute([$user_id]);
            } elseif (is_array($ids) && count($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge(array_map('intval', $ids), [$user_id]);
                $db->prepare("UPDATE `notifications` SET `is_read` = 1 WHERE `id` IN ($ph) AND `user_id` = ?")
                   ->execute($params);
            }
            echo json_encode(['ok' => true]);
            break;
        }

        case 'delete_notification': {
            $nid = (int)($body['id'] ?? 0);
            if ($nid > 0) {
                $db->prepare("DELETE FROM `notifications` WHERE `id` = ? AND `user_id` = ?")
                   ->execute([$nid, $user_id]);
            }
            echo json_encode(['ok' => true]);
            break;
        }


        case 'get_billing_info': {
            $sub=get_active_subscription($user_id); $wallet=get_wallet($user_id);
            $limits=get_user_limits($user_id); $plans=get_all_plans(); $txns=get_wallet_transactions($user_id,10);
            $dash_count=(int)$db->query("SELECT COUNT(*) FROM `dashboards` WHERE `owner_id`={$user_id}")->fetchColumn();
            $ls=$db->prepare("SELECT le.`entity_id`,d.`name` FROM `locked_entities` le JOIN `dashboards` d ON d.`id`=le.`entity_id` WHERE le.`user_id`=? AND le.`entity_type`='dashboard'");
            $ls->execute([$user_id]); $locked=$ls->fetchAll();
            echo json_encode(['ok'=>true,'subscription'=>$sub,'wallet'=>['balance'=>(float)$wallet['balance']],'limits'=>$limits,'usage'=>['dashboards'=>$dash_count],'plans'=>$plans,'transactions'=>$txns,'locked_dashboards'=>$locked]);
            break;
        }
        case 'topup_wallet': {
            echo json_encode(topup_wallet($user_id,(float)($body['amount']??0)));
            break;
        }
        case 'activate_plan': {
            $slug=$body['slug']??'';
            $result=activate_subscription($user_id,$slug);
            if($result['ok']){$plan=get_plan_by_slug($slug);$result['needs_downgrade']=$plan?check_needs_downgrade($user_id,$plan):false;}
            echo json_encode($result);
            break;
        }
        case 'apply_downgrade': {
            echo json_encode(apply_downgrade($user_id,array_map('intval',$body['keep_ids']??[])));
            break;
        }
        case 'get_plans': {
            echo json_encode(['ok'=>true,'plans'=>get_all_plans()]);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Unknown action: $action"]);
    }

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}