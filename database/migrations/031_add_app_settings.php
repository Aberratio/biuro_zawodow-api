<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
$stmt->execute(['table_name' => 'app_settings']);

if ((int)$stmt->fetchColumn() === 0) {
    $pdo->exec('
        CREATE TABLE app_settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_by VARCHAR(64) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

// The platform integration starts limited to superadmins (PlatformIntegrationAccess::DEFAULT_MODE).
$pdo->exec("
    INSERT IGNORE INTO app_settings (setting_key, setting_value, updated_by, updated_at)
    VALUES ('platform_integration', 'superadmin', NULL, UTC_TIMESTAMP())
");
