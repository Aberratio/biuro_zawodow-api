<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$options = getopt('', ['baseline', 'dry-run', 'adopt-existing', 'no-lock', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/run-migrations.php [--dry-run] [--baseline] [--adopt-existing]\n";
    echo "\n";
    echo "  --dry-run         List pending migrations without running them.\n";
    echo "  --baseline        Mark all current migrations as applied without running them.\n";
    echo "  --adopt-existing  Mark pending migrations as applied only when their schema effects already exist.\n";
    echo "  --no-lock         Do not use the database advisory lock.\n";
    exit(0);
}

$selectedModes = array_filter([
    isset($options['dry-run']),
    isset($options['baseline']),
    isset($options['adopt-existing']),
]);
if (count($selectedModes) > 1) {
    fwrite(STDERR, "Choose only one of --dry-run, --baseline or --adopt-existing.\n");
    exit(4);
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

$tableExists = static function (PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute(['table_name' => $tableName]);

    return (int)($stmt->fetch()['total'] ?? 0) > 0;
};

$columnExists = static function (PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int)($stmt->fetch()['total'] ?? 0) > 0;
};

$indexExists = static function (PDO $pdo, string $tableName, string $indexName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        'table_name' => $tableName,
        'index_name' => $indexName,
    ]);

    return (int)($stmt->fetch()['total'] ?? 0) > 0;
};

$enumContains = static function (PDO $pdo, string $tableName, string $columnName, string $value): bool {
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);
    $columnType = (string)($stmt->fetch()['COLUMN_TYPE'] ?? '');

    return str_contains($columnType, "'" . str_replace("'", "''", $value) . "'");
};

$hasLaterSchemaMarker = static function (PDO $pdo) use ($tableExists, $columnExists): bool {
    return $tableExists($pdo, 'event_participant_field_mappings')
        || $tableExists($pdo, 'request_rate_limits')
        || $tableExists($pdo, 'event_sync_state')
        || $tableExists($pdo, 'audit_logs')
        || $columnExists($pdo, 'events', 'deleted_at');
};

$adoptionChecks = [
    '002_hash_user_passwords.php' => static function (PDO $pdo) use ($tableExists): bool {
        if (!$tableExists($pdo, 'users')) {
            return false;
        }

        $rows = $pdo->query('SELECT password FROM users')->fetchAll();
        foreach ($rows as $row) {
            if (!isPasswordHash((string)$row['password'])) {
                return false;
            }
        }

        return true;
    },
    '003_add_admin_id_to_users.php' => static function (PDO $pdo) use ($columnExists, $hasLaterSchemaMarker): bool {
        return $columnExists($pdo, 'users', 'admin_id') || $hasLaterSchemaMarker($pdo);
    },
    '004_add_event_limit_to_organizations.php' => static function (PDO $pdo) use ($columnExists): bool {
        return $columnExists($pdo, 'organizations', 'event_limit');
    },
    '005_create_admin_organization_assignments.php' => static function (PDO $pdo) use ($tableExists, $hasLaterSchemaMarker): bool {
        return $tableExists($pdo, 'admin_organization_assignments')
            || (!$tableExists($pdo, 'admin_organization_assignments') && $hasLaterSchemaMarker($pdo));
    },
    '006_align_user_organization_relations.php' => static function (PDO $pdo) use ($columnExists, $hasLaterSchemaMarker): bool {
        return !$columnExists($pdo, 'users', 'admin_id') || $hasLaterSchemaMarker($pdo);
    },
    '007_add_event_participant_imports.php' => static function (PDO $pdo) use ($tableExists, $columnExists): bool {
        return $columnExists($pdo, 'participants', 'display_name')
            && $columnExists($pdo, 'participants', 'custom_fields_json')
            && $tableExists($pdo, 'event_participant_field_mappings');
    },
    '008_create_password_resets.php' => static function (PDO $pdo) use ($tableExists): bool {
        return $tableExists($pdo, 'password_resets');
    },
    '009_backfill_participant_qr_codes.php' => static function (PDO $pdo) use ($columnExists): bool {
        return $columnExists($pdo, 'participants', 'qr_code');
    },
    '010_unify_participant_status.php' => static function (PDO $pdo) use ($columnExists, $enumContains): bool {
        return !$columnExists($pdo, 'participants', 'package_status')
            && $enumContains($pdo, 'participants', 'status', 'checked_in_not_starting');
    },
    '011_add_event_archiving.php' => static function (PDO $pdo) use ($columnExists): bool {
        return $columnExists($pdo, 'events', 'archived_at');
    },
    '012_add_scanner_plus_role.php' => static function (PDO $pdo) use ($enumContains): bool {
        return $enumContains($pdo, 'users', 'role', 'scanner_plus');
    },
    '013_allow_multiple_admins_per_organization.php' => static function (PDO $pdo) use ($tableExists, $indexExists, $hasLaterSchemaMarker): bool {
        if (!$tableExists($pdo, 'admin_organization_assignments')) {
            return $hasLaterSchemaMarker($pdo);
        }

        return !$indexExists($pdo, 'admin_organization_assignments', 'uq_admin_organization_assignments_organization_id');
    },
    '014_drop_admin_organization_assignments.php' => static function (PDO $pdo) use ($tableExists): bool {
        return !$tableExists($pdo, 'admin_organization_assignments');
    },
    '015_allow_duplicate_participant_bib_numbers.php' => static function (PDO $pdo) use ($indexExists): bool {
        return !$indexExists($pdo, 'participants', 'uq_participants_event_bib')
            && $indexExists($pdo, 'participants', 'idx_participants_event_bib');
    },
    '016_add_important_participant_field_role.php' => static function (PDO $pdo) use ($enumContains): bool {
        return $enumContains($pdo, 'event_participant_field_mappings', 'field_role', 'important_custom');
    },
    '017_add_event_participant_change_audit.php' => static function (PDO $pdo) use ($tableExists, $columnExists): bool {
        return $columnExists($pdo, 'participants', 'participant_audit_key')
            && $columnExists($pdo, 'participants', 'baseline_import_record_id')
            && $tableExists($pdo, 'event_participant_import_baseline_records')
            && $tableExists($pdo, 'event_participant_change_logs');
    },
    '018_add_request_rate_limits_and_activity_event.php' => static function (PDO $pdo) use ($tableExists, $columnExists): bool {
        return $tableExists($pdo, 'request_rate_limits')
            && $columnExists($pdo, 'activity_logs', 'event_id');
    },
    '019_add_user_archiving_and_event_soft_delete.php' => static function (PDO $pdo) use ($columnExists): bool {
        return $columnExists($pdo, 'users', 'archived_at')
            && $columnExists($pdo, 'users', 'archived_email')
            && $columnExists($pdo, 'events', 'deleted_at');
    },
    '020_add_event_sync_tables.php' => static function (PDO $pdo) use ($tableExists): bool {
        return $tableExists($pdo, 'event_sync_state')
            && $tableExists($pdo, 'client_mutations')
            && $tableExists($pdo, 'sync_outbox');
    },
    '021_add_audit_logs.php' => static function (PDO $pdo) use ($tableExists, $columnExists): bool {
        return $tableExists($pdo, 'audit_logs')
            && $columnExists($pdo, 'event_participant_change_logs', 'audit_log_id');
    },
    '022_add_participant_field_validation.php' => static function (PDO $pdo) use ($columnExists): bool {
        return $columnExists($pdo, 'event_participant_field_mappings', 'field_type')
            && $columnExists($pdo, 'event_participant_field_mappings', 'validation_rules_json');
    },
    '027_add_participant_identity_code.php' => static function (PDO $pdo) use ($columnExists, $indexExists): bool {
        return $columnExists($pdo, 'participants', 'identity_code')
            && $indexExists($pdo, 'participants', 'idx_participants_identity_code_event');
    },
    '029_add_platform_participant_sync.php' => static function (PDO $pdo) use ($columnExists, $indexExists): bool {
        return $columnExists($pdo, 'participants', 'platform_registration_id')
            && $columnExists($pdo, 'participants', 'platform_removed_at')
            && $indexExists($pdo, 'participants', 'uq_participants_event_platform_registration')
            && $columnExists($pdo, 'event_platform_connections', 'last_pull_summary_json');
    },
    '030_add_platform_webhooks_and_push_queue.php' => static function (PDO $pdo) use ($columnExists, $tableExists): bool {
        return $tableExists($pdo, 'platform_webhook_receipts')
            && $tableExists($pdo, 'platform_push_queue')
            && $columnExists($pdo, 'event_platform_connections', 'pull_requested_at')
            && $columnExists($pdo, 'event_platform_connections', 'last_webhook_at');
    },
];

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

if (isset($options['adopt-existing'])) {
    $adoptedCount = 0;
    $remaining = [];

    $pdo->beginTransaction();

    try {
        foreach ($pending as $index => $migration) {
            $migrationName = $migration['name'];
            $canAdopt = isset($adoptionChecks[$migrationName])
                && $adoptionChecks[$migrationName]($pdo);

            if (!$canAdopt) {
                $remaining = array_slice($pending, $index);
                break;
            }

            $insertStmt->execute([
                'migration' => $migrationName,
                'checksum' => $migration['checksum'],
            ]);
            $adoptedCount++;
            echo "Adopted existing migration: {$migrationName}\n";
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    if ($adoptedCount === 0) {
        echo "No existing migrations were adopted.\n";
    }

    if ($remaining !== []) {
        echo "Migrations still pending:\n";
        foreach ($remaining as $migration) {
            echo "- {$migration['name']}\n";
        }
        echo "Run php scripts/run-migrations.php to apply pending migrations.\n";
    } else {
        echo "No pending migrations remain after adoption.\n";
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
