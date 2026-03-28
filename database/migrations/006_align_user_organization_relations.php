<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$adminAssignmentTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'admin_organization_assignments'");
$adminAssignmentTableExists = $adminAssignmentTableExistsStmt !== false && $adminAssignmentTableExistsStmt->fetch() !== false;

if ($adminAssignmentTableExists) {
    $uniqueKeyExistsStmt = $pdo->query("
        SHOW INDEX FROM admin_organization_assignments
        WHERE Key_name = 'uq_admin_organization_assignments_organization_id'
    ");
    $uniqueKeyExists = $uniqueKeyExistsStmt !== false && $uniqueKeyExistsStmt->fetch() !== false;

    if (!$uniqueKeyExists) {
        $pdo->exec("
            ALTER TABLE admin_organization_assignments
            ADD UNIQUE KEY uq_admin_organization_assignments_organization_id (organization_id)
        ");
    }
}

if ($adminAssignmentTableExists) {
    $pdo->exec("
        UPDATE users u
        INNER JOIN admin_organization_assignments aoa ON aoa.user_id = u.id
        SET u.organization_id = NULL
        WHERE u.role = 'admin'
    ");
}

$pdo->exec("
    DELETE uea
    FROM user_event_assignments uea
    INNER JOIN users u ON u.id = uea.user_id
    WHERE u.role <> 'scanner'
");

$adminIdColumnStmt = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'admin_id'
");
$adminIdExists = $adminIdColumnStmt !== false && $adminIdColumnStmt->fetch() !== false;

if ($adminIdExists) {
    $foreignKeyStmt = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'admin_id'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $foreignKey = $foreignKeyStmt !== false ? $foreignKeyStmt->fetch() : false;
    if ($foreignKey !== false && isset($foreignKey['CONSTRAINT_NAME'])) {
        $pdo->exec(sprintf(
            "ALTER TABLE users DROP FOREIGN KEY `%s`",
            str_replace('`', '``', (string)$foreignKey['CONSTRAINT_NAME'])
        ));
    }

    $indexStmt = $pdo->query("
        SHOW INDEX FROM users
        WHERE Key_name = 'idx_users_admin_id'
    ");
    $indexExists = $indexStmt !== false && $indexStmt->fetch() !== false;

    if ($indexExists) {
        $pdo->exec("
            ALTER TABLE users
            DROP INDEX idx_users_admin_id
        ");
    }

    $pdo->exec("
        ALTER TABLE users
        DROP COLUMN admin_id
    ");
}

echo "Aligned user-organization relations with organization-owned organizers/scanners.\n";
