<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$eventsTable = $pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'
")->fetchColumn();
if ((int)$eventsTable === 0) {
    throw new RuntimeException('events table missing');
}

// Per BZ event connection to a platform (ZC) event. The organization token is stored
// encrypted (PlatformTokenCipher envelope), never in plaintext.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_platform_connections (
        event_id VARCHAR(64) NOT NULL,
        platform_event_id VARCHAR(64) NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        organization_token_ciphertext VARCHAR(512) NOT NULL,
        organization_token_hint CHAR(4) NOT NULL,
        token_updated_at DATETIME NOT NULL,
        last_test_at DATETIME NULL,
        last_test_status VARCHAR(32) NULL,
        created_by_user_id VARCHAR(64) NULL,
        updated_by_user_id VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (event_id),
        UNIQUE KEY uq_event_platform_connections_platform_event_id (platform_event_id),
        CONSTRAINT fk_event_platform_connections_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Created event_platform_connections table.\n";
