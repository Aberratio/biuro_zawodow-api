<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformPushQueueStore.php';
require_once __DIR__ . '/PlatformPushQueueEntry.php';
require_once __DIR__ . '/PlatformPushRequestBuilder.php';
require_once __DIR__ . '/PlatformPushResultClassifier.php';
require_once __DIR__ . '/PlatformApiClient.php';

/**
 * Sends the queued office changes to the platform, one request per participant. Runs from the command line
 * (cron), never from an office request. Output lines and logs carry ids, field keys and status codes only.
 */
final class PlatformPushService
{
    private const DISABLED_RETRY_SECONDS = 3600;

    private const FIELD_LABELS = [
        'contact_email' => 'e-mail',
        'bib_number' => 'numer startowy',
        'first_name' => 'imię',
        'last_name' => 'nazwisko',
        'category' => 'kategoria',
        'checked_in_at' => 'odprawa',
        'checked_in_by' => 'odprawa',
    ];

    private const ABANDON_REASONS = [
        'platform_registration_not_found' => 'zapis nie istnieje na platformie',
        'platform_validation_rejected' => 'platforma odrzuciła dane',
        'participant_missing' => 'uczestnik nie istnieje lub nie pochodzi z platformy',
        'too_many_attempts' => 'przekroczono liczbę prób',
    ];

    /** @var callable(): string */
    private $clock;

    /** @var callable(string, string, string, array<string, mixed>): void */
    private $logger;

    /**
     * @param null|callable(): string $clock current UTC time as Y-m-d H:i:s
     * @param null|callable(string $level, string $code, string $message, array $context): void $logger
     */
    public function __construct(
        private readonly PlatformPushQueueStore $store,
        private readonly PlatformIntegrationConfig $config,
        private readonly PlatformApiClient $client,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d H:i:s');
        $this->logger = $logger ?? static function (string $level, string $code, string $message, array $context): void {
            ApplicationLogger::log($level, $code, $message, $context);
        };
    }

    /**
     * @return array{status: string, lines: list<string>, failed: bool}
     *         status: ok | in_progress | integration_unavailable
     */
    public function run(?string $eventId = null, int $limit = 200, bool $retryAbandoned = false): array
    {
        if (!$this->config->isAvailable()) {
            return ['status' => 'integration_unavailable', 'lines' => [], 'failed' => false];
        }
        if (!$this->store->tryGlobalLock()) {
            return ['status' => 'in_progress', 'lines' => ['skipped in_progress'], 'failed' => false];
        }

        $lines = [];
        $failed = false;
        try {
            if ($retryAbandoned) {
                $lines[] = sprintf('retried abandoned=%d', $this->store->retryAbandoned($eventId, ($this->clock)()));
            }

            $connections = [];
            $aliases = [];
            foreach ($this->store->listDue(($this->clock)(), $limit, $eventId) as $row) {
                $outcome = $this->process($row, $connections, $aliases);
                $lines[] = $outcome['line'];
                $failed = $failed || $outcome['failed'];
                if ($outcome['stop_run']) {
                    $lines[] = 'stopped platform_rejected_or_rate_limited';
                    break;
                }
            }
        } finally {
            $this->store->releaseGlobalLock();
        }

        return ['status' => 'ok', 'lines' => $lines, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, ?array{connection: array<string, mixed>, token: ?string}> $connections
     * @param array<string, array<string, string>> $aliases
     * @return array{line: string, failed: bool, stop_run: bool}
     */
    private function process(array $row, array &$connections, array &$aliases): array
    {
        $queueId = (int)$row['id'];
        $eventId = (string)$row['event_id'];
        $participantId = (int)$row['participant_id'];
        $label = sprintf('q=%d %s', $queueId, $eventId);
        $fieldKeys = array_keys($row['fields']);
        $now = ($this->clock)();

        $participant = $this->store->findParticipant($participantId);
        if ($participant === null || trim((string)($participant['platform_registration_id'] ?? '')) === '') {
            $this->store->markAbandoned($queueId, 'participant_missing', null, $now);
            $this->log('warning', 'platform_push.abandoned', $row, 'participant_missing', null, (int)$row['attempts']);

            return ['line' => $label . ' abandoned participant_missing', 'failed' => true, 'stop_run' => false];
        }

        if (!array_key_exists($eventId, $connections)) {
            $connection = $this->store->findConnection($eventId);
            $token = null;
            if ($connection !== null && (int)$connection['is_enabled'] === 1) {
                $token = $this->config->cipher()->decrypt((string)$connection['organization_token_ciphertext'], $eventId);
            }
            $connections[$eventId] = ['connection' => $connection ?? [], 'token' => $token];
        }
        $connectionRow = $connections[$eventId]['connection'];
        $token = $connections[$eventId]['token'];

        if ($connectionRow === [] || (int)($connectionRow['is_enabled'] ?? 0) !== 1) {
            $this->store->defer($queueId, 'connection_disabled', self::DISABLED_RETRY_SECONDS, $now);

            return ['line' => $label . ' waiting connection_disabled', 'failed' => false, 'stop_run' => false];
        }
        if ($token === null) {
            $this->store->defer($queueId, 'token_unreadable', self::DISABLED_RETRY_SECONDS, $now);

            return ['line' => $label . ' waiting token_unreadable', 'failed' => false, 'stop_run' => false];
        }

        $attempts = (int)$row['attempts'];
        if (!$this->store->claim($queueId, $attempts, $now)) {
            return ['line' => $label . ' skipped changed_meanwhile', 'failed' => false, 'stop_run' => false];
        }
        $attempts++;

        $aliases[$eventId] ??= $this->store->aliasByKey($eventId);
        $built = PlatformPushRequestBuilder::build($participant, $row['fields'], $aliases[$eventId]);
        if ($built['fields'] === []) {
            $this->store->settle($queueId, $row['fields'], [], $now, null);

            return ['line' => $label . ' sent fields=-', 'failed' => false, 'stop_run' => false];
        }

        $result = $this->client->patchParticipant(
            (string)$connectionRow['platform_event_id'],
            (string)$participant['platform_registration_id'],
            $token,
            ['fields' => $built['fields'], 'reason' => $built['reason']]
        );
        $decision = PlatformPushResultClassifier::classify($result['status'], $result['http_status'], $attempts);
        $keys = implode(',', array_keys($built['fields']));

        if ($decision['outcome'] === 'sent') {
            $rejections = $result['data']['rejected'] ?? [];
            $status = $this->store->settle($queueId, $row['fields'], $rejections, $now, $result['http_status']);
            $this->log('info', 'platform_push.sent', $row, 'sent', $result['http_status'], $attempts);
            if ($rejections !== []) {
                $this->store->addActivity(
                    $eventId,
                    $participantId,
                    (string)($participant['display_name'] ?? ''),
                    'Platforma Zmierzymy Czas odrzuciła zmiany jako nieaktualne: ' . $this->labels(array_keys($rejections)),
                    ['queue_id' => $queueId, 'field_keys' => array_keys($rejections), 'outcome' => 'rejected']
                );
            }

            return ['line' => sprintf('%s %s fields=%s%s', $label, $status === 'sent' ? 'sent' : 'sent_more_pending', $keys, $rejections === [] ? '' : ' rejected=' . implode(',', array_keys($rejections))), 'failed' => false, 'stop_run' => false];
        }

        if ($decision['outcome'] === 'abandoned') {
            $reason = $decision['retry_in_seconds'] === null && isset(self::ABANDON_REASONS[$result['status']]) ? $result['status'] : 'too_many_attempts';
            $this->store->markAbandoned($queueId, $reason, $result['http_status'], $now);
            $this->store->addActivity(
                $eventId,
                $participantId,
                (string)($participant['display_name'] ?? ''),
                'Nie wysłano odprawy na platformę Zmierzymy Czas: ' . self::ABANDON_REASONS[$reason],
                ['queue_id' => $queueId, 'field_keys' => $fieldKeys, 'outcome' => 'abandoned']
            );
            $this->log('warning', 'platform_push.abandoned', $row, $reason, $result['http_status'], $attempts);

            return ['line' => sprintf('%s abandoned %s', $label, $reason), 'failed' => true, 'stop_run' => $decision['stop_run']];
        }

        $retry = (int)$decision['retry_in_seconds'];
        $this->store->markFailed($queueId, $result['status'], $result['http_status'], $retry, $now);
        $this->log('warning', 'platform_push.failed', $row, $result['status'], $result['http_status'], $attempts);

        return ['line' => sprintf('%s failed %s retry_in=%ds', $label, $result['status'], $retry), 'failed' => true, 'stop_run' => $decision['stop_run']];
    }

    /** @param list<string> $keys */
    private function labels(array $keys): string
    {
        $labels = [];
        foreach ($keys as $key) {
            $labels[] = self::FIELD_LABELS[$key] ?? 'pole formularza';
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    /** @param array<string, mixed> $row */
    private function log(string $level, string $code, array $row, string $outcome, ?int $httpStatus, int $attempts): void
    {
        ($this->logger)($level, $code, 'Platform push ' . $outcome, [
            'queue_id' => (int)$row['id'],
            'event_id' => (string)$row['event_id'],
            'participant_id' => (int)$row['participant_id'],
            'field_keys' => array_keys($row['fields']),
            'http_status' => $httpStatus,
            'outcome' => $outcome,
            'attempts' => $attempts,
        ]);
    }
}
