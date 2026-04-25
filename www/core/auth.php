<?php
/**
 * core/auth.php
 * Point of Creation — аутентификация и сессии
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function require_auth(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function require_admin(): void
{
    require_auth();
    if (!is_admin()) {
        header('Location: /');
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(string $token): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', $token);
}

function set_flash(string $key, string $msg): void
{
    $_SESSION['flash'][$key] = $msg;
}

function get_flash(string $key): string
{
    $msg = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $msg;
}

// ──────────────────────────────────────────────────────────────
//  Регистрация пользователя
// ──────────────────────────────────────────────────────────────
function register_user(string $username, string $email, string $password): array
{
    $username = trim($username);
    $email    = trim($email);

    if (strlen($username) < 3 || strlen($username) > 32) {
        return ['ok' => false, 'error' => 'Логин: от 3 до 32 символов'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['ok' => false, 'error' => 'Логин: только латиница, цифры и _'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Некорректный email'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Пароль: минимум 6 символов'];
    }

    $db = get_db();

    $stmt = $db->prepare('SELECT `id` FROM `users` WHERE `username` = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Пользователь с таким логином уже существует'];
    }

    $stmt = $db->prepare('SELECT `id` FROM `users` WHERE `email` = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Этот email уже зарегистрирован'];
    }

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO `users` (`username`, `email`, `password_hash`, `role`)
            VALUES (?, ?, ?, 'user')
        ")->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);

        $user_id = (int) $db->lastInsertId();

        // Создаём дефолтный личный дашборд
        $db->prepare("
            INSERT INTO `dashboards` (`owner_id`, `name`, `is_shared`)
            VALUES (?, 'Мой дашборд', 0)
        ")->execute([$user_id]);

        $dashboard_id = (int) $db->lastInsertId();

        // Создаём первую страницу
        $db->prepare("
            INSERT INTO `pages` (`dashboard_id`, `name`, `order_index`)
            VALUES (?, 'Главная', 0)
        ")->execute([$dashboard_id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'error' => 'Ошибка при создании аккаунта: ' . $e->getMessage()];
    }

    return ['ok' => true];
}

// ──────────────────────────────────────────────────────────────
//  Авторизация
// ──────────────────────────────────────────────────────────────
function login_user(string $login, string $password): array
{
    $db = get_db();

    // Логин по username ИЛИ email
    $stmt = $db->prepare('
        SELECT * FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1
    ');
    $stmt->execute([trim($login), trim($login)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Неверный логин или пароль'];
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'       => (int) $user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'role'     => $user['role'],
    ];

    // Запомнить последний активный дашборд (сбросить при входе)
    unset($_SESSION['active_dashboard_id'], $_SESSION['active_page_id']);

    return ['ok' => true];
}
