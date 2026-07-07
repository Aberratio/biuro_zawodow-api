<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$columnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'is_test'
");

if ((int)($columnStmt->fetch()['total'] ?? 0) === 0) {
    $pdo->exec('ALTER TABLE events ADD COLUMN is_test BOOLEAN NOT NULL DEFAULT FALSE AFTER office_close_at');
}

echo "Event test mode column is ready.\n";
