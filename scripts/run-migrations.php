<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$options = getopt('', ['baseline', 'dry-run', 'no-lock', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/run-migrations.php [--dry-run] [--baseline]\n";
    echo "\n";
    echo "  --dry-run   List pending migrations without running them.\n";
    echo "  --baseline  Mark all current migrations as applied without running them.\n";
    echo "  --no-lock   Do not use the database advisory lock.\n";
    exit(0);
}

$rootPath = dirname(__DIR__);
$migrationsPath = $rootPath . '/database/migrations';
$migrationFiles = glob($migrationsPath . '/*.php') ?: [];
sort($migrationFiles, SORT_STRING);
$isDryRun = isset($options['dry-run']);

$pdo = Database::connect();
$lockName = 'biuro_zawodow_schema_migrations';
$lockAcquired = false;

if (!isset($options['no-lock'])) {
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 30) AS lock_result');
    $lockStmt->execute(['lock_name' => $lockName]);
    $lockResult = (int)($lockStmt->fetch()['lock_result'] ?? 0);

    if ($lockResult !== 1) {
        fwrite(STDERR, "Could not acquire migration lock: {$lockName}\n");
        exit(3);
    }

    $lockAcquired = true;
    register_shutdown_function(static function () use ($pdo, $lockName): void {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute(['lock_name' => $lockName]);
    });
}

if (!$isDryRun) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$schemaMigrationsExistsStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
");
$schemaMigrationsExists = (int)($schemaMigrationsExistsStmt->fetch()['total'] ?? 0) > 0;
$appliedRows = $schemaMigrationsExists
    ? $pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll()
    : [];
$applied = [];
foreach ($appliedRows as $row) {
    $applied[(string)$row['migration']] = (string)$row['checksum'];
}

$pending = [];
foreach ($migrationFiles as $migrationFile) {
    $migrationName = basename($migrationFile);
    $checksum = hash_file('sha256', $migrationFile);

    if (isset($applied[$migrationName])) {
        if (!hash_equals($applied[$migrationName], $checksum)) {
            fwrite(STDERR, "Migration checksum changed after applying: {$migrationName}\n");
            exit(2);
        }

        continue;
    }

    $pending[] = [
        'name' => $migrationName,
        'path' => $migrationFile,
        'checksum' => $checksum,
    ];
}

if ($pending === []) {
    echo "No pending migrations.\n";
    exit(0);
}

if ($isDryRun) {
    echo "Pending migrations:\n";
    foreach ($pending as $migration) {
        echo "- {$migration['name']}\n";
    }
    exit(0);
}

$insertStmt = $pdo->prepare('
    INSERT INTO schema_migrations (migration, checksum)
    VALUES (:migration, :checksum)
');
$runMigration = static function (string $migrationPath): void {
    require $migrationPath;
};

if (isset($options['baseline'])) {
    foreach ($pending as $migration) {
        $insertStmt->execute([
            'migration' => $migration['name'],
            'checksum' => $migration['checksum'],
        ]);
        echo "Baselined migration: {$migration['name']}\n";
    }

    exit(0);
}

foreach ($pending as $migration) {
    echo "Running migration: {$migration['name']}\n";

    $runMigration($migration['path']);

    $insertStmt->execute([
        'migration' => $migration['name'],
        'checksum' => $migration['checksum'],
    ]);

    echo "Applied migration: {$migration['name']}\n";
}

if ($lockAcquired) {
    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $releaseStmt->execute(['lock_name' => $lockName]);
}
