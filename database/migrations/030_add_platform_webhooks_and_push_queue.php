<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$count = static function (PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
};
$columnExists = static fn (string $table, string $column): bool => $count(
    $pdo,
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name',
    ['table_name' => $table, 'column_name' => $column]
) > 0;
$tableExists = static fn (string $table): bool => $count(
    $pdo,
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name',
    ['table_name' => $table]
) > 0;

foreach (['participants', 'event_platform_connections', 'event_participant_change_logs'] as $required) {
    if (!$tableExists($required)) {
        throw new RuntimeException($required . ' table missing');
    }
}

// One statement per effect so a half-applied state heals itself on a rerun.
if (!$tableExists('platform_webhook_receipts')) {
    $pdo->exec('
        CREATE TABLE platform_webhook_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NOT NULL,
            dedupe_key VARCHAR(190) NOT NULL,
            platform_registration_id VARCHAR(64) NOT NULL,
            change_type VARCHAR(32) NOT NULL,
            occurred_at DATETIME NULL,
            fingerprint CHAR(64) NOT NULL,
            participant_id BIGINT UNSIGNED NULL,
            outcome VARCHAR(32) NOT NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY uq_platform_webhook_receipts_dedupe (event_id, dedupe_key),
            KEY idx_platform_webhook_receipts_registration (event_id, platform_registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

if (!$tableExists('platform_push_queue')) {
    $pdo->exec('
        CREATE TABLE platform_push_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            fields_json LONGTEXT NOT NULL,
            status VARCHAR(16) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            last_error VARCHAR(64) NULL,
            last_http_status SMALLINT NULL,
            last_rejections_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_platform_push_queue_participant (participant_id),
            KEY idx_platform_push_queue_due (status, next_attempt_at),
            KEY idx_platform_push_queue_event (event_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

foreach ([
    'pull_requested_at' => 'DATETIME NULL',
    'last_webhook_at' => 'DATETIME NULL',
] as $column => $definition) {
    if (!$columnExists('event_platform_connections', $column)) {
        $pdo->exec('ALTER TABLE event_platform_connections ADD COLUMN ' . $column . ' ' . $definition);
    }
}

// The new value is appended at the end of the ENUM, which is a metadata-only change.
$typeStmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
$typeStmt->execute(['table_name' => 'event_participant_change_logs', 'column_name' => 'change_source']);
if (!str_contains((string)$typeStmt->fetchColumn(), "'platform_webhook'")) {
    $pdo->exec("ALTER TABLE event_participant_change_logs MODIFY COLUMN change_source ENUM('csv_import', 'manual', 'participant_edit', 'participant_delete', 'bib_conflict_resolution', 'platform_sync', 'platform_webhook') NOT NULL");
}

echo "Platform webhook receipts and push queue are ready.\n";
