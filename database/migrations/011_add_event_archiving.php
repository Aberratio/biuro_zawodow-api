<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$columnExistsStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'archived_at'
");
$columnExists = (int)($columnExistsStmt->fetch()['total'] ?? 0) > 0;

if (!$columnExists) {
    $pdo->exec("
        ALTER TABLE events
        ADD COLUMN archived_at DATETIME NULL AFTER office_close_at,
        ADD KEY idx_events_archived_at (archived_at)
    ");
}

echo "Event archiving column is ready.\n";
