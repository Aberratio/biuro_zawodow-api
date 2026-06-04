<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

registerApplicationLogging();

try {
    $fileResult = ApplicationLogger::runMaintenance();
    $auditRetentionDays = max(1, min(3650, (int)(getenv('AUDIT_RETENTION_DAYS') ?: 730)));
    $pdo = Database::connect();
    $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$auditRetentionDays} DAY)");
    $stmt->execute();
    $deletedAuditRows = $stmt->rowCount();

    addAuditLog($pdo, [
        'category' => 'system',
        'action_code' => 'logging.maintenance.completed',
        'action' => 'Wykonano retencje logow',
        'target_type' => 'logging_system',
        'metadata' => [
            'compressed_files' => $fileResult['compressed'],
            'deleted_files' => $fileResult['deleted'],
            'deleted_audit_rows' => $deletedAuditRows,
            'audit_retention_days' => $auditRetentionDays,
        ],
    ]);
    ApplicationLogger::log('info', 'logging.maintenance.completed', 'Log maintenance completed', [
        ...$fileResult,
        'deleted_audit_rows' => $deletedAuditRows,
    ]);
    echo sprintf(
        "Compressed files: %d, deleted files: %d, deleted audit rows: %d\n",
        $fileResult['compressed'],
        $fileResult['deleted'],
        $deletedAuditRows
    );
} catch (Throwable $exception) {
    ApplicationLogger::logException($exception, 'logging.maintenance.failed');
    fwrite(STDERR, "Log maintenance failed: {$exception->getMessage()}\n");
    exit(1);
}
