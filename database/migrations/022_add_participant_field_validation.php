<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$columnsStmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_participant_field_mappings'
");

$existingColumns = [];
if ($columnsStmt !== false) {
    foreach ($columnsStmt->fetchAll() as $column) {
        $existingColumns[(string)$column['COLUMN_NAME']] = true;
    }
}

$alterParts = [];
if (!isset($existingColumns['field_type'])) {
    $alterParts[] = "ADD COLUMN field_type ENUM('text', 'number', 'date', 'select') NOT NULL DEFAULT 'text' AFTER field_role";
}
if (!isset($existingColumns['validation_rules_json'])) {
    $alterParts[] = "ADD COLUMN validation_rules_json LONGTEXT NULL AFTER field_type";
}

if ($alterParts !== []) {
    $pdo->exec("
        ALTER TABLE event_participant_field_mappings
        " . implode(",\n        ", $alterParts) . "
    ");
}

echo "Added participant field validation configuration.\n";
