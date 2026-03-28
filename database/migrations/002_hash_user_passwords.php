<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();
$users = $pdo->query('SELECT id, email, password FROM users ORDER BY id ASC')->fetchAll();

$updatedCount = 0;
$updateStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');

foreach ($users as $user) {
    $storedPassword = (string)$user['password'];
    if (isPasswordHash($storedPassword) && !password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
        continue;
    }

    $updateStmt->execute([
        'password' => password_hash($storedPassword, PASSWORD_DEFAULT),
        'id' => $user['id'],
    ]);
    $updatedCount++;
}

echo 'Updated user passwords: ' . $updatedCount . PHP_EOL;
