<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec('ALTER TABLE organizations ADD COLUMN event_limit INT UNSIGNED NOT NULL DEFAULT 0 AFTER logo');

$updateStmt = $pdo->prepare('UPDATE organizations SET event_limit = :event_limit WHERE id = :id');
$updateStmt->execute(['event_limit' => 4, 'id' => 'org-1']);
$updateStmt->execute(['event_limit' => 2, 'id' => 'org-2']);

echo "Added organizations.event_limit and backfilled default limits.\n";
