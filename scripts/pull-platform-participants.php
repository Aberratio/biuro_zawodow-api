<?php

declare(strict_types=1);

/**
 * Pulls participants from the platform for events with an enabled platform connection.
 *
 *   php scripts/pull-platform-participants.php                 cron mode: only connections that are due
 *   php scripts/pull-platform-participants.php --event=evt-1   one event now (ignores the schedule, honours the lock)
 *   php scripts/pull-platform-participants.php --force         every enabled connection now
 *   php scripts/pull-platform-participants.php --help
 *
 * Exit codes: 0 done (pulled, skipped, not due or already running), 1 at least one pull failed,
 * 2 integration not configured on this server, 3 unexpected error, 64 bad arguments.
 * Output contains event ids, counts and status codes only.
 */

require_once __DIR__ . '/../src/bootstrap.php';

registerApplicationLogging();

$usage = <<<'TXT'
Usage: php scripts/pull-platform-participants.php [--event=ID] [--force] [--help]
  (no option)  pull enabled connections that are due (cron mode, run every 5 minutes)
  --event=ID   pull one event now, regardless of the schedule
  --force      pull every enabled connection now

TXT;

$eventOption = null;
$force = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, $usage);
        exit(0);
    }
    if ($argument === '--force') {
        $force = true;
        continue;
    }
    if (str_starts_with($argument, '--event=') && strlen($argument) > 8) {
        $eventOption = substr($argument, 8);
        continue;
    }

    fwrite(STDERR, "Unknown argument: {$argument}\n" . $usage);
    exit(64);
}

try {
    $config = PlatformIntegrationConfig::fromProcess();
    if (!$config->isAvailable()) {
        fwrite(STDERR, 'Platform integration is not configured: ' . implode(', ', $config->unavailableReasons()) . "\n");
        fwrite(STDERR, PlatformPullStatus::describe('integration_unavailable') . "\n");
        exit(2);
    }

    $pdo = Database::connect();
    $store = new PdoPlatformParticipantStore($pdo);
    $service = new PlatformParticipantPullService($store, $config, new PlatformApiClient($config));
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowLocal = new DateTimeImmutable('now');
    $pullRequests = $eventOption === null && !$force ? $store->loadPullRequests() : [];

    $targets = [];
    if ($eventOption !== null) {
        $targets[] = $eventOption;
    } else {
        foreach ($store->listEnabledConnections() as $row) {
            if (!$force) {
                $interval = PlatformPullSchedule::intervalSeconds($row, $nowLocal);
                if ($interval === null) {
                    fwrite(STDOUT, sprintf("%s not_due schedule_ended\n", $row['id']));
                    continue;
                }
                if (!PlatformPullSchedule::isDue($row['last_pull_started_at'] !== null ? (string)$row['last_pull_started_at'] : null, $interval, $nowUtc, $pullRequests[(string)$row['id']] ?? null)) {
                    fwrite(STDOUT, sprintf(
                        "%s not_due next_in=%ds\n",
                        $row['id'],
                        PlatformPullSchedule::secondsUntilDue($row['last_pull_started_at'] !== null ? (string)$row['last_pull_started_at'] : null, $interval, $nowUtc)
                    ));
                    continue;
                }
            }
            $targets[] = (string)$row['id'];
        }
    }

    $failed = false;
    foreach ($targets as $eventId) {
        $result = $service->pull($eventId, 'cron', null, 3);
        $status = $result['status'];
        $text = PlatformPullStatus::describe($status);

        if (PlatformPullStatus::isSuccess($status)) {
            $summary = $result['summary'] ?? [];
            fwrite(STDOUT, sprintf(
                "%s %s created=%d updated=%d unchanged=%d skipped=%d removed=%d (%.1f s)\n",
                $eventId,
                $status,
                (int)($summary['created'] ?? 0),
                (int)($summary['updated'] ?? 0),
                (int)($summary['unchanged'] ?? 0),
                (int)($summary['skipped'] ?? 0),
                (int)($summary['removed'] ?? 0),
                ((int)($summary['duration_ms'] ?? 0)) / 1000
            ));
            continue;
        }

        if (in_array($status, ['in_progress', 'connection_disabled', 'event_inactive', 'connection_missing', 'event_not_found'], true)) {
            fwrite(STDOUT, sprintf("%s skipped %s — %s\n", $eventId, $status, $text));
            continue;
        }

        $failed = true;
        fwrite(STDOUT, sprintf("%s failed %s — %s\n", $eventId, $status, $text));
    }

    exit($failed ? 1 : 0);
} catch (Throwable $exception) {
    ApplicationLogger::logException($exception, 'platform_pull.cron_failed');
    fwrite(STDERR, 'Platform pull failed unexpectedly: ' . $exception::class . "\n");
    exit(3);
}
