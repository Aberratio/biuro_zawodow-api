<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    ALTER TABLE users
    MODIFY COLUMN role ENUM('superadmin', 'admin', 'editor', 'scanner', 'scanner_plus') NOT NULL
");

echo "Added scanner_plus role.\n";
