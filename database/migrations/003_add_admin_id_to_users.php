<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    ALTER TABLE users
    ADD COLUMN admin_id VARCHAR(64) NULL AFTER organization_id,
    ADD KEY idx_users_admin_id (admin_id),
    ADD CONSTRAINT fk_users_admin
        FOREIGN KEY (admin_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
");

$updateStmt = $pdo->prepare('UPDATE users SET admin_id = :admin_id WHERE id = :id');
$updateStmt->execute(['admin_id' => 'u-1', 'id' => 'u-2']);
$updateStmt->execute(['admin_id' => 'u-1', 'id' => 'u-3']);

echo "Added users.admin_id relationship and backfilled organizer owners.\n";
