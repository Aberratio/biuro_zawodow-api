<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'admin_organization_assignments'");
$tableExists = $tableExistsStmt !== false && $tableExistsStmt->fetch() !== false;

if (!$tableExists) {
    $pdo->exec("
        CREATE TABLE admin_organization_assignments (
            user_id VARCHAR(64) NOT NULL,
            organization_id VARCHAR(64) NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, organization_id),
            KEY idx_admin_organization_assignments_organization_id (organization_id),
            CONSTRAINT fk_admin_organization_assignments_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,
            CONSTRAINT fk_admin_organization_assignments_organization
                FOREIGN KEY (organization_id) REFERENCES organizations(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$assignmentStmt = $pdo->prepare('
    INSERT INTO admin_organization_assignments (user_id, organization_id)
    VALUES (:user_id, :organization_id)
    ON DUPLICATE KEY UPDATE organization_id = VALUES(organization_id)
');

$assignmentStmt->execute(['user_id' => 'u-1', 'organization_id' => 'org-1']);
$assignmentStmt->execute(['user_id' => 'u-1b', 'organization_id' => 'org-2']);

echo "Created admin_organization_assignments and backfilled current admin ownership.\n";
