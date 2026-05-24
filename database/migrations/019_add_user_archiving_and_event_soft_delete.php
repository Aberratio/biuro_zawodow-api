<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$usersArchivedAtColumnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'archived_at'
");
$usersArchivedAtColumnExists = (int)($usersArchivedAtColumnStmt->fetch()['total'] ?? 0) > 0;
if (!$usersArchivedAtColumnExists) {
    $pdo->exec("
        ALTER TABLE users
        ADD COLUMN archived_at DATETIME NULL AFTER organization_id
    ");
}

$usersArchivedAtIndexStmt = $pdo->query("
    SHOW INDEX FROM users
    WHERE Key_name = 'idx_users_archived_at'
");
$usersArchivedAtIndexExists = $usersArchivedAtIndexStmt !== false && $usersArchivedAtIndexStmt->fetch() !== false;
if (!$usersArchivedAtIndexExists) {
    $pdo->exec("
        ALTER TABLE users
        ADD KEY idx_users_archived_at (archived_at)
    ");
}

$usersArchivedEmailColumnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'archived_email'
");
$usersArchivedEmailColumnExists = (int)($usersArchivedEmailColumnStmt->fetch()['total'] ?? 0) > 0;
if (!$usersArchivedEmailColumnExists) {
    $pdo->exec("
        ALTER TABLE users
        ADD COLUMN archived_email VARCHAR(190) NULL AFTER archived_at
    ");
}

$eventsArchivedAtColumnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'archived_at'
");
$eventsArchivedAtColumnExists = (int)($eventsArchivedAtColumnStmt->fetch()['total'] ?? 0) > 0;
if (!$eventsArchivedAtColumnExists) {
    $pdo->exec("
        ALTER TABLE events
        ADD COLUMN archived_at DATETIME NULL AFTER office_close_at
    ");
}

$eventsArchivedAtIndexStmt = $pdo->query("
    SHOW INDEX FROM events
    WHERE Key_name = 'idx_events_archived_at'
");
$eventsArchivedAtIndexExists = $eventsArchivedAtIndexStmt !== false && $eventsArchivedAtIndexStmt->fetch() !== false;
if (!$eventsArchivedAtIndexExists) {
    $pdo->exec("
        ALTER TABLE events
        ADD KEY idx_events_archived_at (archived_at)
    ");
}

$eventsDeletedAtColumnStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'deleted_at'
");
$eventsDeletedAtColumnExists = (int)($eventsDeletedAtColumnStmt->fetch()['total'] ?? 0) > 0;
if (!$eventsDeletedAtColumnExists) {
    $pdo->exec("
        ALTER TABLE events
        ADD COLUMN deleted_at DATETIME NULL AFTER archived_at
    ");
}

$eventsDeletedAtIndexStmt = $pdo->query("
    SHOW INDEX FROM events
    WHERE Key_name = 'idx_events_deleted_at'
");
$eventsDeletedAtIndexExists = $eventsDeletedAtIndexStmt !== false && $eventsDeletedAtIndexStmt->fetch() !== false;
if (!$eventsDeletedAtIndexExists) {
    $pdo->exec("
        ALTER TABLE events
        ADD KEY idx_events_deleted_at (deleted_at)
    ");
}

echo "User archiving and event soft delete columns are ready.\n";
