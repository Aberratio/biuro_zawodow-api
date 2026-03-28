SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS admin_organization_assignments;
DROP TABLE IF EXISTS user_event_assignments;
DROP TABLE IF EXISTS event_participant_field_mappings;
DROP TABLE IF EXISTS participants;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS organizations;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE organizations (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    logo VARCHAR(255) NULL,
    event_limit INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organizations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    event_date DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_organization_id (organization_id),
    CONSTRAINT fk_events_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('superadmin', 'admin', 'editor', 'scanner') NOT NULL,
    organization_id VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_organization_id (organization_id),
    CONSTRAINT fk_users_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_event_assignments (
    user_id VARCHAR(64) NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, event_id),
    KEY idx_user_event_assignments_event_id (event_id),
    CONSTRAINT fk_user_event_assignments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_user_event_assignments_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_organization_assignments (
    user_id VARCHAR(64) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, organization_id),
    UNIQUE KEY uq_admin_organization_assignments_organization_id (organization_id),
    KEY idx_admin_organization_assignments_organization_id (organization_id),
    CONSTRAINT fk_admin_organization_assignments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_admin_organization_assignments_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(64) NULL,
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(190) NOT NULL,
    organization VARCHAR(190) NULL,
    bib_number VARCHAR(32) NULL,
    qr_code VARCHAR(128) NULL,
    custom_fields_json LONGTEXT NULL,
    status ENUM('pending', 'checked_in') NOT NULL DEFAULT 'pending',
    package_status ENUM('not_collected', 'collected') NOT NULL DEFAULT 'not_collected',
    email_status ENUM('not_sent', 'sent') NOT NULL DEFAULT 'not_sent',
    checked_in_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_participants_qr_code (qr_code),
    UNIQUE KEY uq_participants_event_bib (event_id, bib_number),
    KEY idx_participants_event_id (event_id),
    KEY idx_participants_event_email (event_id, email),
    CONSTRAINT fk_participants_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_participant_field_mappings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(64) NOT NULL,
    source_column_name VARCHAR(190) NOT NULL,
    alias VARCHAR(190) NOT NULL,
    field_role ENUM('email', 'display_name_part', 'bib_number', 'custom') NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_logs (
    id VARCHAR(64) NOT NULL,
    action VARCHAR(190) NOT NULL,
    participant_id BIGINT UNSIGNED NULL,
    participant_name_snapshot VARCHAR(190) NULL,
    user_id VARCHAR(64) NULL,
    user_name_snapshot VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_logs_participant_id (participant_id),
    KEY idx_activity_logs_user_id (user_id),
    CONSTRAINT fk_activity_logs_participant
        FOREIGN KEY (participant_id) REFERENCES participants(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_activity_logs_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organizations (id, name, event_limit)
VALUES
    ('org-1', 'SportEvents Pro', 4),
    ('org-2', 'RunPoland', 2)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    event_limit = VALUES(event_limit);

INSERT INTO events (id, name, event_date, location, organization_id)
VALUES
    ('evt-1', 'Bieg Piastowski 10km', '2026-04-12', 'Gniezno, Park Miejski', 'org-1'),
    ('evt-2', 'Triathlon Poznan Sprint', '2026-05-18', 'Poznan, Malta', 'org-1'),
    ('evt-3', 'Maraton Wroclaw', '2026-06-07', 'Wroclaw, Hala Stulecia', 'org-2')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    event_date = VALUES(event_date),
    location = VALUES(location),
    organization_id = VALUES(organization_id);

INSERT INTO users (id, name, email, password, role, organization_id)
VALUES
    ('u-0', 'Super Admin', 'super@biurozawodow.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'superadmin', NULL),
    ('u-1', 'Admin SportEvents', 'admin@sportevents.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'admin', NULL),
    ('u-1b', 'Admin RunPoland', 'admin@runpoland.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'admin', NULL),
    ('u-2', 'Organizator Gniezno', 'org.gniezno@sportevents.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'editor', 'org-1'),
    ('u-3', 'Organizator Poznan', 'org.poznan@sportevents.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'editor', 'org-1'),
    ('u-4', 'Wolontariusz Skaner 1', 'skaner1@sportevents.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'scanner', 'org-1'),
    ('u-5', 'Wolontariusz Skaner 2', 'skaner2@sportevents.pl', '$2y$10$ls0v32FVolwU70qQEiZlY.xpRMoq7AJpTSUgXlFkKrDHN7BVvp/qK', 'scanner', 'org-1')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password = VALUES(password),
    role = VALUES(role),
    organization_id = VALUES(organization_id);

INSERT INTO user_event_assignments (user_id, event_id)
VALUES
    ('u-2', 'evt-1'),
    ('u-3', 'evt-2'),
    ('u-4', 'evt-1'),
    ('u-5', 'evt-2')
ON DUPLICATE KEY UPDATE
    event_id = VALUES(event_id);

INSERT INTO admin_organization_assignments (user_id, organization_id)
VALUES
    ('u-1', 'org-1'),
    ('u-1b', 'org-2')
ON DUPLICATE KEY UPDATE
    organization_id = VALUES(organization_id);

INSERT INTO participants (event_id, first_name, last_name, display_name, email, organization, bib_number, qr_code, custom_fields_json, status, package_status, email_status)
VALUES
    ('evt-1', 'Anna', 'Kowalska', 'Anna Kowalska', 'anna.kowalska@example.com', 'Politechnika Warszawska', '101', 'QR-evt-1-101', NULL, 'pending', 'not_collected', 'not_sent'),
    ('evt-2', 'Jan', 'Nowak', 'Jan Nowak', 'jan.nowak@example.com', 'Uniwersytet Warszawski', '201', 'QR-evt-2-201', NULL, 'pending', 'not_collected', 'not_sent')
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    display_name = VALUES(display_name),
    organization = VALUES(organization),
    bib_number = VALUES(bib_number),
    qr_code = VALUES(qr_code),
    custom_fields_json = VALUES(custom_fields_json),
    status = VALUES(status),
    package_status = VALUES(package_status),
    email_status = VALUES(email_status);

INSERT INTO activity_logs (id, action, participant_id, participant_name_snapshot, user_id, user_name_snapshot)
VALUES
    ('log-1', 'Check-in', 1, 'Anna Kowalska', 'u-4', 'Wolontariusz Skaner 1'),
    ('log-2', 'Wydano pakiet', 2, 'Jan Nowak', 'u-2', 'Organizator Gniezno')
ON DUPLICATE KEY UPDATE
    action = VALUES(action),
    participant_id = VALUES(participant_id),
    participant_name_snapshot = VALUES(participant_name_snapshot),
    user_id = VALUES(user_id),
    user_name_snapshot = VALUES(user_name_snapshot);
