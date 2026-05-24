<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_sync_state (
        event_id VARCHAR(64) NOT NULL,
        sync_mode ENUM('cloud', 'local_authoritative') NOT NULL DEFAULT 'cloud',
        source_node_id VARCHAR(128) NULL,
        sync_status ENUM('idle', 'pending', 'syncing', 'conflict') NOT NULL DEFAULT 'idle',
        conflicts_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_exported_at DATETIME NULL,
        last_synced_at DATETIME NULL,
        last_report_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (event_id),
        KEY idx_event_sync_state_sync_mode (sync_mode),
        CONSTRAINT fk_event_sync_state_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS client_mutations (
        id VARCHAR(64) NOT NULL,
        mutation_type VARCHAR(64) NOT NULL DEFAULT 'participant_status',
        participant_id BIGINT UNSIGNED NOT NULL,
        event_id VARCHAR(64) NOT NULL,
        device_id VARCHAR(128) NULL,
        source_node_id VARCHAR(128) NULL,
        user_id VARCHAR(64) NULL,
        base_status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL,
        requested_status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL,
        applied_status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL,
        response_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_client_mutations_event_id (event_id),
        KEY idx_client_mutations_participant_id (participant_id),
        KEY idx_client_mutations_user_id (user_id),
        CONSTRAINT fk_client_mutations_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        CONSTRAINT fk_client_mutations_participant
            FOREIGN KEY (participant_id) REFERENCES participants(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        CONSTRAINT fk_client_mutations_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS sync_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        client_mutation_id VARCHAR(64) NULL,
        event_id VARCHAR(64) NOT NULL,
        source_node_id VARCHAR(128) NULL,
        entity_type VARCHAR(64) NOT NULL,
        entity_id VARCHAR(64) NOT NULL,
        action VARCHAR(64) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        status ENUM('pending', 'sent', 'applied', 'conflict') NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_sync_outbox_event_id (event_id),
        KEY idx_sync_outbox_status (status),
        KEY idx_sync_outbox_client_mutation_id (client_mutation_id),
        CONSTRAINT fk_sync_outbox_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Event sync tables are ready.\n";
