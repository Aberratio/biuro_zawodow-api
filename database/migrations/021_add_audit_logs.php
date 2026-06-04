<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_logs (
        id VARCHAR(64) NOT NULL,
        category VARCHAR(64) NOT NULL,
        action_code VARCHAR(128) NOT NULL,
        action VARCHAR(190) NOT NULL,
        outcome VARCHAR(32) NOT NULL DEFAULT 'success',
        severity VARCHAR(32) NOT NULL DEFAULT 'info',
        actor_user_id VARCHAR(64) NULL,
        actor_name_snapshot VARCHAR(190) NULL,
        actor_role_snapshot VARCHAR(64) NULL,
        target_type VARCHAR(64) NULL,
        target_id VARCHAR(128) NULL,
        organization_id VARCHAR(64) NULL,
        event_id VARCHAR(64) NULL,
        participant_id BIGINT UNSIGNED NULL,
        request_id VARCHAR(128) NULL,
        ip_hash CHAR(64) NULL,
        metadata_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_audit_logs_created_at (created_at),
        KEY idx_audit_logs_category (category),
        KEY idx_audit_logs_action_code (action_code),
        KEY idx_audit_logs_outcome (outcome),
        KEY idx_audit_logs_severity (severity),
        KEY idx_audit_logs_actor_user_id (actor_user_id),
        KEY idx_audit_logs_target (target_type, target_id),
        KEY idx_audit_logs_organization_id (organization_id),
        KEY idx_audit_logs_event_id (event_id),
        KEY idx_audit_logs_participant_id (participant_id),
        KEY idx_audit_logs_request_id (request_id),
        CONSTRAINT fk_audit_logs_actor_user
            FOREIGN KEY (actor_user_id) REFERENCES users(id)
            ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_audit_logs_organization
            FOREIGN KEY (organization_id) REFERENCES organizations(id)
            ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_audit_logs_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_audit_logs_participant
            FOREIGN KEY (participant_id) REFERENCES participants(id)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$columnExistsStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'event_participant_change_logs'
      AND COLUMN_NAME = 'audit_log_id'
");
if ((int)($columnExistsStmt->fetch()['total'] ?? 0) === 0) {
    $pdo->exec("
        ALTER TABLE event_participant_change_logs
        ADD COLUMN audit_log_id VARCHAR(64) NULL AFTER id,
        ADD KEY idx_event_participant_change_logs_audit_log_id (audit_log_id),
        ADD CONSTRAINT fk_event_participant_change_logs_audit_log
            FOREIGN KEY (audit_log_id) REFERENCES audit_logs(id)
            ON DELETE SET NULL ON UPDATE CASCADE
    ");
}

$legacyRows = $pdo->query("
    SELECT id, action, event_id, participant_id, participant_name_snapshot, user_id, user_name_snapshot, created_at
    FROM activity_logs
    ORDER BY created_at ASC
")->fetchAll();

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO audit_logs (
        id, category, action_code, action, outcome, severity,
        actor_user_id, actor_name_snapshot, target_type, target_id,
        event_id, participant_id, metadata_json, created_at
    ) VALUES (
        :id, :category, :action_code, :action, 'success', 'info',
        :actor_user_id, :actor_name_snapshot, :target_type, :target_id,
        :event_id, :participant_id, :metadata_json, :created_at
    )
");
foreach ($legacyRows as $row) {
    $participantId = isset($row['participant_id']) ? (int)$row['participant_id'] : null;
    $eventId = $row['event_id'] ?? null;
    $action = (string)$row['action'];
    $insertStmt->execute([
        'id' => 'legacy-' . (string)$row['id'],
        'category' => auditCategoryFromAction(auditActionCodeFromLabel($action), $action),
        'action_code' => auditActionCodeFromLabel($action),
        'action' => $action,
        'actor_user_id' => $row['user_id'] ?: null,
        'actor_name_snapshot' => $row['user_name_snapshot'] ?: null,
        'target_type' => $participantId !== null ? 'participant' : ($eventId !== null ? 'event' : null),
        'target_id' => $participantId !== null ? (string)$participantId : $eventId,
        'event_id' => $eventId,
        'participant_id' => $participantId,
        'metadata_json' => encodeJsonValue([
            'participant_name' => $row['participant_name_snapshot'] ?? null,
            'migrated_from_activity_logs' => true,
        ]),
        'created_at' => $row['created_at'],
    ]);
}

echo "Audit logs table is ready.\n";
