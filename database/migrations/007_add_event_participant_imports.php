<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$participantEmailIndexStmt = $pdo->query("
    SHOW INDEX FROM participants
    WHERE Key_name = 'uq_participants_email'
");
$participantEmailIndexExists = $participantEmailIndexStmt !== false && $participantEmailIndexStmt->fetch() !== false;

if ($participantEmailIndexExists) {
    $pdo->exec("
        ALTER TABLE participants
        DROP INDEX uq_participants_email
    ");
}

$columnsStmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'participants'
");
$existingColumns = [];
if ($columnsStmt !== false) {
    foreach ($columnsStmt->fetchAll() as $column) {
        $existingColumns[(string)$column['COLUMN_NAME']] = true;
    }
}

$alterParts = [];
if (!isset($existingColumns['display_name'])) {
    $alterParts[] = "ADD COLUMN display_name VARCHAR(255) NOT NULL DEFAULT '' AFTER last_name";
}
if (!isset($existingColumns['custom_fields_json'])) {
    $alterParts[] = "ADD COLUMN custom_fields_json LONGTEXT NULL AFTER qr_code";
}
$alterParts[] = "MODIFY first_name VARCHAR(120) NULL";
$alterParts[] = "MODIFY last_name VARCHAR(120) NULL";

if ($alterParts !== []) {
    $pdo->exec("
        ALTER TABLE participants
        " . implode(",\n        ", $alterParts) . "
    ");
}

if (isset($existingColumns['display_name']) || in_array("ADD COLUMN display_name VARCHAR(255) NOT NULL DEFAULT '' AFTER last_name", $alterParts, true)) {
    $pdo->exec("
        UPDATE participants
        SET display_name = TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))
        WHERE display_name = ''
    ");
}

$participantEventEmailIndexStmt = $pdo->query("
    SHOW INDEX FROM participants
    WHERE Key_name = 'idx_participants_event_email'
");
$participantEventEmailIndexExists = $participantEventEmailIndexStmt !== false && $participantEventEmailIndexStmt->fetch() !== false;

if (!$participantEventEmailIndexExists) {
    $pdo->exec("
        ALTER TABLE participants
        ADD KEY idx_participants_event_email (event_id, email)
    ");
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_participant_field_mappings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id VARCHAR(64) NOT NULL,
        source_column_name VARCHAR(190) NOT NULL,
        alias VARCHAR(190) NOT NULL,
        field_role ENUM('email', 'display_name_part', 'bib_number', 'custom', 'important_custom') NOT NULL,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_participant_field_mappings_event_source (event_id, source_column_name),
        KEY idx_event_participant_field_mappings_event_id (event_id),
        CONSTRAINT fk_event_participant_field_mappings_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Added event-scoped participant import mappings and participant dynamic fields.\n";
