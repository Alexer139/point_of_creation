<?php
/**
 * core/auth.php
 * Session management, CSRF, login/register helpers.
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

function register_user(string $username, string $password): array
{
    $username = trim($username);

    if (strlen($username) < 3 || strlen($username) > 32) {
        return ['ok' => false, 'error' => 'Логин: от 3 до 32 символов'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['ok' => false, 'error' => 'Логин: только латиница, цифры и _'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Пароль: минимум 6 символов'];
    }

    $db   = get_db();
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Пользователь с таким логином уже существует'];
    }

    $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')")
       ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

    return ['ok' => true];
}

function login_user(string $username, string $password): array
{
    $db   = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['ok' => false, 'error' => 'Неверный логин или пароль'];
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'       => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
    ];

    return ['ok' => true];
}
