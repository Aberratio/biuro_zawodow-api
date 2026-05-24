<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS request_rate_limits (
        bucket_key VARCHAR(128) NOT NULL,
        bucket_name VARCHAR(64) NOT NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (bucket_key),
        KEY idx_request_rate_limits_bucket_name (bucket_name),
        KEY idx_request_rate_limits_blocked_until (blocked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$activityLogEventIdColumnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'activity_logs'
      AND COLUMN_NAME = 'event_id'
");
$activityLogEventIdColumnExists = (int)($activityLogEventIdColumnStmt->fetch()['total'] ?? 0) > 0;
if (!$activityLogEventIdColumnExists) {
    $pdo->exec("
        ALTER TABLE activity_logs
        ADD COLUMN event_id VARCHAR(64) NULL AFTER action
    ");
}

$activityLogEventIdIndexStmt = $pdo->query("
    SHOW INDEX FROM activity_logs
    WHERE Key_name = 'idx_activity_logs_event_id'
");
$activityLogEventIdIndexExists = $activityLogEventIdIndexStmt !== false && $activityLogEventIdIndexStmt->fetch() !== false;
if (!$activityLogEventIdIndexExists) {
    $pdo->exec("
        ALTER TABLE activity_logs
        ADD KEY idx_activity_logs_event_id (event_id)
    ");
}

$activityLogEventConstraintStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'activity_logs'
      AND COLUMN_NAME = 'event_id'
      AND CONSTRAINT_NAME = 'fk_activity_logs_event'
");
$activityLogEventConstraintExists = (int)($activityLogEventConstraintStmt->fetch()['total'] ?? 0) > 0;
if (!$activityLogEventConstraintExists) {
    $pdo->exec("
        ALTER TABLE activity_logs
        ADD CONSTRAINT fk_activity_logs_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
    ");
}

echo "Request rate limits and activity log event relation are ready.\n";
