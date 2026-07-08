<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_office_locations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id VARCHAR(64) NOT NULL,
        name VARCHAR(190) NOT NULL,
        google_maps_url VARCHAR(2048) NULL,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_office_locations_event_id (event_id),
        CONSTRAINT fk_event_office_locations_event
            FOREIGN KEY (event_id) REFERENCES events(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_office_location_hours (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        location_id BIGINT UNSIGNED NOT NULL,
        opens_at DATETIME NOT NULL,
        closes_at DATETIME NOT NULL,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_office_location_hours_location_id (location_id),
        CONSTRAINT fk_event_office_location_hours_location
            FOREIGN KEY (location_id) REFERENCES event_office_locations(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Backfill: every existing event gets one location (its current `location` text)
// with one hour range (its current office_open_at/office_close_at), so no data
// is lost when this feature ships.
$pdo->exec("
    INSERT INTO event_office_locations (event_id, name, display_order)
    SELECT e.id, e.location, 0
    FROM events e
    WHERE NOT EXISTS (
        SELECT 1 FROM event_office_locations l WHERE l.event_id = e.id
    )
");

$pdo->exec("
    INSERT INTO event_office_location_hours (location_id, opens_at, closes_at, display_order)
    SELECT l.id, e.office_open_at, e.office_close_at, 0
    FROM event_office_locations l
    INNER JOIN events e ON e.id = l.event_id
    WHERE NOT EXISTS (
        SELECT 1 FROM event_office_location_hours h WHERE h.location_id = l.id
    )
");

echo "Event office locations tables are ready.\n";
