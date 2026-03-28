<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    UPDATE participants
    SET status = CASE
        WHEN package_status = 'collected' THEN 'checked_in'
        WHEN status = 'checked_in' THEN 'checked_in'
        ELSE 'not_checked_in'
    END
");

$pdo->exec("
    ALTER TABLE participants
    MODIFY COLUMN status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL DEFAULT 'not_checked_in'
");

$pdo->exec('ALTER TABLE participants DROP COLUMN package_status');

echo "Unified participant status and removed package_status column.\n";
