<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'admin_organization_assignments'");
$tableExists = $tableExistsStmt !== false && $tableExistsStmt->fetch() !== false;

if (!$tableExists) {
    echo "admin_organization_assignments table does not exist. Nothing to migrate.\n";
    return;
}

$uniqueKeyExistsStmt = $pdo->query("
    SHOW INDEX FROM admin_organization_assignments
    WHERE Key_name = 'uq_admin_organization_assignments_organization_id'
");
$uniqueKeyExists = $uniqueKeyExistsStmt !== false && $uniqueKeyExistsStmt->fetch() !== false;

if ($uniqueKeyExists) {
    $pdo->exec("
        ALTER TABLE admin_organization_assignments
        DROP INDEX uq_admin_organization_assignments_organization_id
    ");
}

echo "Removed unique organization constraint from admin_organization_assignments.\n";
