<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$count = static function (PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
};
$columnExists = static fn (string $table, string $column): bool => $count(
    $pdo,
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name',
    ['table_name' => $table, 'column_name' => $column]
) > 0;
$indexExists = static fn (string $table, string $index): bool => $count(
    $pdo,
    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name',
    ['table_name' => $table, 'index_name' => $index]
) > 0;
$tableExists = static fn (string $table): bool => $count(
    $pdo,
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name',
    ['table_name' => $table]
) > 0;

foreach (['participants', 'event_platform_connections', 'event_participant_change_logs'] as $required) {
    if (!$tableExists($required)) {
        throw new RuntimeException($required . ' table missing');
    }
}

// One ALTER per effect so a half-applied state heals itself on a rerun.
if (!$columnExists('participants', 'platform_registration_id')) {
    $pdo->exec('ALTER TABLE participants ADD COLUMN platform_registration_id VARCHAR(64) NULL AFTER identity_code');
}
if (!$columnExists('participants', 'platform_snapshot_json')) {
    $pdo->exec('ALTER TABLE participants ADD COLUMN platform_snapshot_json LONGTEXT NULL AFTER platform_registration_id');
}
if (!$columnExists('participants', 'platform_removed_at')) {
    $pdo->exec('ALTER TABLE participants ADD COLUMN platform_removed_at DATETIME NULL AFTER platform_snapshot_json');
}
// NULL registration ids (file imports, manual rows) are distinct, so existing rows are unaffected.
if (!$indexExists('participants', 'uq_participants_event_platform_registration')) {
    $pdo->exec('ALTER TABLE participants ADD UNIQUE KEY uq_participants_event_platform_registration (event_id, platform_registration_id)');
}

foreach ([
    'field_layout_json' => 'LONGTEXT NULL',
    'last_pull_started_at' => 'DATETIME NULL',
    'last_pull_finished_at' => 'DATETIME NULL',
    'last_pull_status' => 'VARCHAR(32) NULL',
    'last_pull_trigger' => 'VARCHAR(16) NULL',
    'last_pull_summary_json' => 'LONGTEXT NULL',
] as $column => $definition) {
    if (!$columnExists('event_platform_connections', $column)) {
        $pdo->exec('ALTER TABLE event_platform_connections ADD COLUMN ' . $column . ' ' . $definition);
    }
}

// The new value is appended at the end of the ENUM, which is a metadata-only change.
$typeStmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
$typeStmt->execute(['table_name' => 'event_participant_change_logs', 'column_name' => 'change_source']);
if (!str_contains((string)$typeStmt->fetchColumn(), "'platform_sync'")) {
    $pdo->exec("ALTER TABLE event_participant_change_logs MODIFY COLUMN change_source ENUM('csv_import', 'manual', 'participant_edit', 'participant_delete', 'bib_conflict_resolution', 'platform_sync') NOT NULL");
}

echo "Platform participant sync columns are ready.\n";
