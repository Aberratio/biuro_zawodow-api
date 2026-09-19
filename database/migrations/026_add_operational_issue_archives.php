<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS operational_issue_archives (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        issue_id VARCHAR(190) NOT NULL,
        severity VARCHAR(32) NOT NULL,
        category VARCHAR(64) NOT NULL,
        title VARCHAR(190) NOT NULL,
        description TEXT NOT NULL,
        issue_count INT UNSIGNED NOT NULL DEFAULT 0,
        organization_id VARCHAR(64) NULL,
        event_id VARCHAR(64) NULL,
        archived_by_user_id VARCHAR(64) NOT NULL,
        archived_by_name_snapshot VARCHAR(190) NOT NULL,
        archived_reason TEXT NOT NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_operational_issue_archives_issue_id (issue_id),
        KEY idx_operational_issue_archives_organization_id (organization_id),
        KEY idx_operational_issue_archives_event_id (event_id),
        KEY idx_operational_issue_archives_archived_by_user_id (archived_by_user_id),
        CONSTRAINT fk_operational_issue_archives_organization
            FOREIGN KEY (organization_id) REFERENCES organizations(id)
            ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_operational_issue_archives_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_operational_issue_archives_user
            FOREIGN KEY (archived_by_user_id) REFERENCES users(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Operational issue archives table is ready.\n";
