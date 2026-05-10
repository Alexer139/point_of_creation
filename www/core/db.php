<?php
/**
 * core/db.php
 * Point of Creation — MySQL connection + auto-migration
 *
 * Переменные среды (задаются в docker-compose.yml / Railway):
 *   DB_HOST     — хост MySQL           (по умолчанию: 127.0.0.1)
 *   DB_PORT     — порт                 (по умолчанию: 3306)
 *   DB_NAME     — имя базы данных      (по умолчанию: poc)
 *   DB_USER     — пользователь         (по умолчанию: poc)
 *   DB_PASSWORD — пароль               (по умолчанию: '')
 */

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST')     ?: getenv('MYSQLHOST')     ?: '127.0.0.1';
    $port = getenv('DB_PORT')     ?: getenv('MYSQLPORT')     ?: '3306';
    $name = getenv('DB_NAME')     ?: getenv('MYSQLDATABASE') ?: 'poc';
    $user = getenv('DB_USER')     ?: getenv('MYSQLUSER')     ?: 'poc';
    $pass = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Синхронизировать timezone MySQL с PHP (оба в UTC)
    $pdo->exec("SET time_zone = '+00:00'");

    migrate($pdo);

    return $pdo;
}

function migrate(PDO $db): void
{
    // Таблица версий миграций
    $db->exec("
        CREATE TABLE IF NOT EXISTS `_migrations` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(120) NOT NULL,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_migration_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $applied = [];
    foreach ($db->query("SELECT `name` FROM `_migrations`")->fetchAll() as $r) {
        $applied[$r['name']] = true;
    }

    $migrations = [
        '001_initial_schema' => function (PDO $db) {
            // USERS
            $db->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                    `username`      VARCHAR(32)     NOT NULL,
                    `email`         VARCHAR(255)    NOT NULL DEFAULT '',
                    `password_hash` VARCHAR(255)    NOT NULL,
                    `role`          ENUM('admin','user') NOT NULL DEFAULT 'user',
                    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_users_username` (`username`),
                    UNIQUE KEY `uq_users_email`    (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // DASHBOARDS
            $db->exec("
                CREATE TABLE IF NOT EXISTS `dashboards` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `owner_id`   INT UNSIGNED NOT NULL,
                    `name`       VARCHAR(120) NOT NULL DEFAULT 'Мой дашборд',
                    `is_shared`  TINYINT(1)   NOT NULL DEFAULT 0,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_dashboards_owner` (`owner_id`),
                    CONSTRAINT `fk_dashboards_owner`
                        FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // PAGES
            $db->exec("
                CREATE TABLE IF NOT EXISTS `pages` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `dashboard_id` INT UNSIGNED NOT NULL,
                    `name`         VARCHAR(120) NOT NULL DEFAULT 'Страница',
                    `order_index`  SMALLINT     NOT NULL DEFAULT 0,
                    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_pages_dashboard` (`dashboard_id`),
                    CONSTRAINT `fk_pages_dashboard`
                        FOREIGN KEY (`dashboard_id`) REFERENCES `dashboards`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // WIDGETS
            $db->exec("
                CREATE TABLE IF NOT EXISTS `widgets` (
                    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `page_id`       INT UNSIGNED NOT NULL,
                    `type`          VARCHAR(32)  NOT NULL,
                    `title`         VARCHAR(120) NOT NULL DEFAULT '',
                    `settings_json` MEDIUMTEXT   NOT NULL,
                    `position_data` TEXT         NOT NULL,
                    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_widgets_page` (`page_id`),
                    CONSTRAINT `fk_widgets_page`
                        FOREIGN KEY (`page_id`) REFERENCES `pages`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // DASHBOARD_ACCESS
            $db->exec("
                CREATE TABLE IF NOT EXISTS `dashboard_access` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `dashboard_id` INT UNSIGNED NOT NULL,
                    `user_id`      INT UNSIGNED NOT NULL,
                    `role`         ENUM('viewer','editor') NOT NULL DEFAULT 'viewer',
                    `invited_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_access` (`dashboard_id`, `user_id`),
                    KEY `idx_access_user` (`user_id`),
                    CONSTRAINT `fk_access_dashboard`
                        FOREIGN KEY (`dashboard_id`) REFERENCES `dashboards`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_access_user`
                        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Дефолтный admin
            $count = (int) $db->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
            if ($count === 0) {
                $db->prepare("
                    INSERT INTO `users` (`username`, `email`, `password_hash`, `role`)
                    VALUES (?, ?, ?, 'admin')
                ")->execute(['admin', 'admin@localhost', password_hash('admin456', PASSWORD_DEFAULT)]);
            }
        },

        '003_notifications' => function (PDO $db) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `notifications` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id`    INT UNSIGNED NOT NULL COMMENT 'Кому уведомление',
                    `type`       ENUM('invited','role_changed','removed') NOT NULL,
                    `dashboard_id` INT UNSIGNED NULL DEFAULT NULL,
                    `dashboard_name` VARCHAR(120) NOT NULL DEFAULT '',
                    `actor_name` VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'Кто выполнил действие',
                    `role`       VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'Роль (для invited/role_changed)',
                    `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_notif_user` (`user_id`, `is_read`),
                    CONSTRAINT `fk_notif_user`
                        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        },

        '004_billing' => function (PDO $db) {
            $db->exec("CREATE TABLE IF NOT EXISTS `plans` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `slug` VARCHAR(32) NOT NULL,
                `name` VARCHAR(64) NOT NULL,
                `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
                `max_dashboards` INT NOT NULL DEFAULT 3,
                `max_pages` INT NOT NULL DEFAULT 5,
                `max_members` INT NOT NULL DEFAULT 1,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` TINYINT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_plan_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("INSERT IGNORE INTO `plans`
                (`slug`,`name`,`price`,`duration_days`,`max_dashboards`,`max_pages`,`max_members`,`sort_order`)
                VALUES
                ('free','Free',0.00,36500,3,5,1,0),
                ('level1','Level 1',299.00,30,5,10,5,1),
                ('level2','Level 2',699.00,30,-1,-1,-1,2)");

            $db->exec("CREATE TABLE IF NOT EXISTS `wallets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_wallet_user` (`user_id`),
                CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `wallet_transactions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `type` ENUM('topup','charge','refund') NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `balance_after` DECIMAL(10,2) NOT NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_wt_user` (`user_id`),
                CONSTRAINT `fk_wt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `subscriptions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `plan_id` INT UNSIGNED NOT NULL,
                `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
                `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `cancelled_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_sub_user` (`user_id`,`status`),
                CONSTRAINT `fk_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `locked_entities` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `entity_type` ENUM('dashboard') NOT NULL DEFAULT 'dashboard',
                `entity_id` INT UNSIGNED NOT NULL,
                `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_locked` (`user_id`,`entity_type`,`entity_id`),
                CONSTRAINT `fk_locked_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("INSERT IGNORE INTO `wallets` (`user_id`,`balance`) SELECT `id`,0.00 FROM `users`");

            $free = $db->query("SELECT `id` FROM `plans` WHERE `slug`='free' LIMIT 1")->fetch();
            if ($free) {
                $db->prepare("INSERT IGNORE INTO `subscriptions`
                    (`user_id`,`plan_id`,`status`,`started_at`,`expires_at`)
                    SELECT u.`id`,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL 100 YEAR)
                    FROM `users` u
                    WHERE NOT EXISTS (SELECT 1 FROM `subscriptions` s WHERE s.`user_id`=u.`id`)")
                   ->execute([$free['id']]);
            }
        },

        '005_downgrade_resolved' => function (PDO $db) {
            $cols = $db->query("SHOW COLUMNS FROM `subscriptions` LIKE 'downgrade_resolved'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE `subscriptions`
                    ADD COLUMN `downgrade_resolved` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT '0 = нужно показать выбор дашбордов, 1 = уже выбрал'");
            }
        },

    ];

    foreach ($migrations as $name => $fn) {
        if (isset($applied[$name])) {
            continue;
        }
        try {
            $fn($db);
            $db->prepare("INSERT INTO `_migrations` (`name`) VALUES (?)")->execute([$name]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}