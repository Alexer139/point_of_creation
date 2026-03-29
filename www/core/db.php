<?php
/**
 * core/db.php
 * SQLite connection + auto-migration.
 */

define('DB_PATH', dirname(__DIR__) . '/data/database.sqlite');

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);

    return $pdo;
}

function migrate(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            username   TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            password   TEXT    NOT NULL,
            role       TEXT    NOT NULL DEFAULT 'user' CHECK(role IN ('admin','user')),
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS widgets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type       TEXT    NOT NULL,
            title      TEXT    NOT NULL DEFAULT '',
            content    TEXT    NOT NULL DEFAULT '{}',
            position_w INTEGER NOT NULL DEFAULT 1,
            position_h INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    ");

    // Seed default admin account (runs only when table is empty)
    $count = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')")
           ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
}
