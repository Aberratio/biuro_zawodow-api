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

$participantAlterParts = [];
if (!isset($participantColumns['participant_audit_key'])) {
    $participantAlterParts[] = "ADD COLUMN participant_audit_key VARCHAR(64) NULL AFTER event_id";
}
if (!isset($participantColumns['baseline_import_record_id'])) {
    $participantAlterParts[] = "ADD COLUMN baseline_import_record_id BIGINT UNSIGNED NULL AFTER participant_audit_key";
}

if ($participantAlterParts !== []) {
    $pdo->exec("
        ALTER TABLE participants
        " . implode(",\n        ", $participantAlterParts) . "
    ");
}

$participantAuditKeyIndexStmt = $pdo->query("
    SHOW INDEX FROM participants
    WHERE Key_name = 'uq_participants_audit_key'
");
$participantAuditKeyIndexExists = $participantAuditKeyIndexStmt !== false && $participantAuditKeyIndexStmt->fetch() !== false;
if (!$participantAuditKeyIndexExists) {
    $pdo->exec("
        ALTER TABLE participants
        ADD UNIQUE KEY uq_participants_audit_key (participant_audit_key)
    ");
}

$participantBaselineIndexStmt = $pdo->query("
    SHOW INDEX FROM participants
    WHERE Key_name = 'idx_participants_baseline_import_record_id'
");
$participantBaselineIndexExists = $participantBaselineIndexStmt !== false && $participantBaselineIndexStmt->fetch() !== false;
if (!$participantBaselineIndexExists) {
    $pdo->exec("
        ALTER TABLE participants
        ADD KEY idx_participants_baseline_import_record_id (baseline_import_record_id)
    ");
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_participant_import_baseline_records (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id VARCHAR(64) NOT NULL,
        participant_audit_key VARCHAR(64) NOT NULL,
        source_row_number INT UNSIGNED NULL,
        display_name VARCHAR(255) NOT NULL,
        email VARCHAR(190) NOT NULL,
        bib_number VARCHAR(32) NULL,
        custom_fields_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_participant_import_baseline_records_audit_key (participant_audit_key),
        KEY idx_event_participant_import_baseline_records_event_id (event_id),
        CONSTRAINT fk_event_participant_import_baseline_records_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_participant_change_logs (
        id VARCHAR(64) NOT NULL,
        event_id VARCHAR(64) NOT NULL,
        participant_audit_key VARCHAR(64) NOT NULL,
        baseline_record_id BIGINT UNSIGNED NULL,
        participant_id BIGINT UNSIGNED NULL,
        change_type ENUM('added', 'updated', 'deleted') NOT NULL,
        change_source ENUM('csv_import', 'manual', 'participant_edit', 'participant_delete', 'bib_conflict_resolution') NOT NULL,
        changed_fields_json LONGTEXT NULL,
        before_state_json LONGTEXT NULL,
        after_state_json LONGTEXT NULL,
        user_id VARCHAR(64) NULL,
        user_name_snapshot VARCHAR(190) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_participant_change_logs_event_id (event_id),
        KEY idx_event_participant_change_logs_audit_key (participant_audit_key),
        KEY idx_event_participant_change_logs_baseline_record_id (baseline_record_id),
        KEY idx_event_participant_change_logs_participant_id (participant_id),
        KEY idx_event_participant_change_logs_user_id (user_id),
        CONSTRAINT fk_event_participant_change_logs_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        CONSTRAINT fk_event_participant_change_logs_baseline_record
            FOREIGN KEY (baseline_record_id) REFERENCES event_participant_import_baseline_records(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE,
        CONSTRAINT fk_event_participant_change_logs_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$participantBaselineConstraintStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'participants'
      AND COLUMN_NAME = 'baseline_import_record_id'
      AND CONSTRAINT_NAME = 'fk_participants_baseline_import_record'
");
$participantBaselineConstraintExists = (int)($participantBaselineConstraintStmt->fetch()['total'] ?? 0) > 0;
if (!$participantBaselineConstraintExists) {
    $pdo->exec("
        ALTER TABLE participants
        ADD CONSTRAINT fk_participants_baseline_import_record
            FOREIGN KEY (baseline_import_record_id) REFERENCES event_participant_import_baseline_records(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
    ");
}

$participantsWithoutAuditKeyStmt = $pdo->query("
    SELECT id
    FROM participants
    WHERE participant_audit_key IS NULL
       OR participant_audit_key = ''
");
if ($participantsWithoutAuditKeyStmt !== false) {
    $updateAuditKeyStmt = $pdo->prepare("
        UPDATE participants
        SET participant_audit_key = :participant_audit_key
        WHERE id = :id
    ");

    foreach ($participantsWithoutAuditKeyStmt->fetchAll() as $participantRow) {
        $updateAuditKeyStmt->execute([
            'participant_audit_key' => 'pa-' . bin2hex(random_bytes(12)),
            'id' => (int)$participantRow['id'],
        ]);
    }
}

$participantAuditKeyNullStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM participants
    WHERE participant_audit_key IS NULL
");
$participantAuditKeyNullCount = (int)($participantAuditKeyNullStmt->fetch()['total'] ?? 0);
if ($participantAuditKeyNullCount === 0) {
    $pdo->exec("
        ALTER TABLE participants
        MODIFY participant_audit_key VARCHAR(64) NOT NULL
    ");
}

echo "Added baseline participant import audit tables and participant audit keys.\n";
