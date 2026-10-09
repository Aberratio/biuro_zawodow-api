<?php

declare(strict_types=1);

/**
 * Sends queued office changes (check-ins, undo, data corrections) to the platform.
 *
 *   php scripts/push-platform-changes.php                              due rows (cron mode, run every minute)
 *   php scripts/push-platform-changes.php --event=evt-1                only that event, ignores the retry time
 *   php scripts/push-platform-changes.php --retry-abandoned [--event=] abandoned rows become pending again
 *   php scripts/push-platform-changes.php --help
 *
 * Exit codes: 0 done (also when nothing was due or another run is active), 1 at least one change failed,
 * 2 integration not configured on this server, 3 unexpected error, 64 bad arguments.
 * Output contains queue ids, event ids, field keys and status codes only.
 */

require_once __DIR__ . '/../src/bootstrap.php';

registerApplicationLogging();

$usage = <<<'TXT'
Usage: php scripts/push-platform-changes.php [--event=ID] [--retry-abandoned] [--help]
  (no option)         send due changes, at most 200 per run (cron mode, run every minute)
  --event=ID          send the changes of one event now, regardless of the retry time
  --retry-abandoned   make abandoned changes pending again (all events, or only --event)

TXT;

$eventOption = null;
$retryAbandoned = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, $usage);
        exit(0);
    }
    if ($argument === '--retry-abandoned') {
        $retryAbandoned = true;
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
        exit(2);
    }

    $service = new PlatformPushService(new PdoPlatformPushQueueStore(Database::connect()), $config, new PlatformApiClient($config));
    $result = $service->run($eventOption, 200, $retryAbandoned);

    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . "\n");
    }

    exit($result['failed'] ? 1 : 0);
} catch (Throwable $exception) {
    ApplicationLogger::logException($exception, 'platform_push.cron_failed');
    fwrite(STDERR, 'Platform push failed unexpectedly: ' . $exception::class . "\n");
    exit(3);
}
