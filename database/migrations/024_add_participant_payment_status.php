<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$participantColumnsStmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'participants'
");
$participantColumns = [];
if ($participantColumnsStmt !== false) {
    foreach ($participantColumnsStmt->fetchAll() as $column) {
        $participantColumns[(string)$column['COLUMN_NAME']] = true;
    }
}

if (!isset($participantColumns['payment_status'])) {
    $pdo->exec("
        ALTER TABLE participants
        ADD COLUMN payment_status ENUM('unknown', 'paid', 'unpaid') NOT NULL DEFAULT 'unknown' AFTER email_status,
        ADD KEY idx_participants_event_payment_status (event_id, payment_status)
    ");
}

$baselineColumnsStmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_participant_import_baseline_records'
");
$baselineColumns = [];
if ($baselineColumnsStmt !== false) {
    foreach ($baselineColumnsStmt->fetchAll() as $column) {
        $baselineColumns[(string)$column['COLUMN_NAME']] = true;
    }
}

if (!isset($baselineColumns['payment_status'])) {
    $pdo->exec("
        ALTER TABLE event_participant_import_baseline_records
        ADD COLUMN payment_status ENUM('unknown', 'paid', 'unpaid') NOT NULL DEFAULT 'unknown' AFTER bib_number
    ");
}

$pdo->exec("
    ALTER TABLE event_participant_field_mappings
    MODIFY field_role ENUM('email', 'display_name_part', 'bib_number', 'payment_status', 'custom', 'important_custom') NOT NULL
");

echo "Added participant payment status.\n";
