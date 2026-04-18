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

$pdo->exec('DROP TABLE admin_organization_assignments');

echo "Dropped admin_organization_assignments.\n";
