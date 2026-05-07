<?php
/**
 * core/access.php
 * Point of Creation — проверка прав доступа к дашбордам и виджетам
 *
 * Иерархия прав:
 *   owner  > editor > viewer
 *
 * Владелец (owner) может всё.
 * Editor — редактирует виджеты и страницы, не может удалить/переименовать дашборд.
 * Viewer — только просмотр.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ──────────────────────────────────────────────────────────────
//  Получить роль текущего пользователя в дашборде.
//  Возвращает: 'owner' | 'editor' | 'viewer' | null (нет доступа)
// ──────────────────────────────────────────────────────────────
function get_dashboard_role(int $dashboard_id, int $user_id): ?string
{
    $db = get_db();

    // Сначала проверяем — владелец ли это
    $stmt = $db->prepare("SELECT owner_id FROM `dashboards` WHERE `id` = ?");
    $stmt->execute([$dashboard_id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null; // дашборд не существует
    }
    if ((int) $row['owner_id'] === $user_id) {
        return 'owner';
    }

    // Проверяем shared-доступ
    $stmt = $db->prepare("
        SELECT `role` FROM `dashboard_access`
        WHERE `dashboard_id` = ? AND `user_id` = ?
    ");
    $stmt->execute([$dashboard_id, $user_id]);
    $access = $stmt->fetch();

    return $access ? $access['role'] : null;
}

// ──────────────────────────────────────────────────────────────
//  Может ли пользователь ВИДЕТЬ дашборд?
// ──────────────────────────────────────────────────────────────
function can_view_dashboard(int $dashboard_id, int $user_id): bool
{
    return get_dashboard_role($dashboard_id, $user_id) !== null;
}

// ──────────────────────────────────────────────────────────────
//  Может ли пользователь РЕДАКТИРОВАТЬ (виджеты, страницы)?
// ──────────────────────────────────────────────────────────────
function can_edit_dashboard(int $dashboard_id, int $user_id): bool
{
    $role = get_dashboard_role($dashboard_id, $user_id);
    return in_array($role, ['owner', 'editor'], true);
}

// ──────────────────────────────────────────────────────────────
//  Является ли пользователь ВЛАДЕЛЬЦЕМ дашборда?
// ──────────────────────────────────────────────────────────────
function is_dashboard_owner(int $dashboard_id, int $user_id): bool
{
    return get_dashboard_role($dashboard_id, $user_id) === 'owner';
}

// ──────────────────────────────────────────────────────────────
//  Получить dashboard_id по page_id
// ──────────────────────────────────────────────────────────────
function get_dashboard_id_by_page(int $page_id): ?int
{
    $stmt = get_db()->prepare("SELECT `dashboard_id` FROM `pages` WHERE `id` = ?");
    $stmt->execute([$page_id]);
    $row = $stmt->fetch();
    return $row ? (int) $row['dashboard_id'] : null;
}

// ──────────────────────────────────────────────────────────────
//  Получить dashboard_id по widget_id
// ──────────────────────────────────────────────────────────────
function get_dashboard_id_by_widget(int $widget_id): ?int
{
    $stmt = get_db()->prepare("
        SELECT p.`dashboard_id`
        FROM `widgets` w
        JOIN `pages` p ON p.`id` = w.`page_id`
        WHERE w.`id` = ?
    ");
    $stmt->execute([$widget_id]);
    $row = $stmt->fetch();
    return $row ? (int) $row['dashboard_id'] : null;
}

// ──────────────────────────────────────────────────────────────
//  Middleware: прерывает выполнение скрипта с JSON-ошибкой
//  если у пользователя нет нужного уровня прав
// ──────────────────────────────────────────────────────────────
function require_dashboard_view(int $dashboard_id, int $user_id): void
{
    if (!can_view_dashboard($dashboard_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Нет доступа к этому дашборду']);
        exit;
    }
}

function require_dashboard_edit(int $dashboard_id, int $user_id): void
{
    $role = get_dashboard_role($dashboard_id, $user_id);
    if ($role === null) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Нет доступа к этому дашборду']);
        exit;
    }
    if ($role === 'viewer') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'У вас права только для просмотра']);
        exit;
    }
}

function require_dashboard_owner(int $dashboard_id, int $user_id): void
{
    if (!is_dashboard_owner($dashboard_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Только владелец дашборда может выполнить это действие']);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────
//  Получить все дашборды пользователя (свои + расшаренные)
// ──────────────────────────────────────────────────────────────
function get_user_dashboards(int $user_id): array
{
    $db = get_db();

    // Собственные дашборды
    $stmt = $db->prepare("
        SELECT d.`id`, d.`name`, d.`is_shared`, d.`created_at`,
               'owner' AS `my_role`
        FROM `dashboards` d
        WHERE d.`owner_id` = ?
        ORDER BY d.`created_at` ASC
    ");
    $stmt->execute([$user_id]);
    $own = $stmt->fetchAll();

    // Расшаренные (куда пригласили)
    $stmt = $db->prepare("
        SELECT d.`id`, d.`name`, d.`is_shared`, d.`created_at`,
               da.`role` AS `my_role`
        FROM `dashboards` d
        JOIN `dashboard_access` da ON da.`dashboard_id` = d.`id`
        WHERE da.`user_id` = ?
        ORDER BY da.`invited_at` ASC
    ");
    $stmt->execute([$user_id]);
    $shared = $stmt->fetchAll();

    return array_merge($own, $shared);
}
