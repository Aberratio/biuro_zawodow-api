<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$columnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'participants'
      AND COLUMN_NAME = 'identity_code'
");

if ((int)($columnStmt->fetch()['total'] ?? 0) === 0) {
    $pdo->exec('ALTER TABLE participants ADD COLUMN identity_code VARCHAR(52) NULL AFTER qr_code');
}

// Plain index on purpose: one competitor has one participant row per distance and per event.
$indexStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'participants'
      AND INDEX_NAME = 'idx_participants_identity_code_event'
");

if ((int)($indexStmt->fetch()['total'] ?? 0) === 0) {
    $pdo->exec('ALTER TABLE participants ADD KEY idx_participants_identity_code_event (identity_code, event_id)');
}

echo "Participant identity code column is ready.\n";
