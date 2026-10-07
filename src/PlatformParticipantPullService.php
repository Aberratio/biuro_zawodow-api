<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformParticipantStore.php';
require_once __DIR__ . '/PlatformIntegrationConfig.php';
require_once __DIR__ . '/PlatformApiClient.php';
require_once __DIR__ . '/PlatformParticipantPayload.php';
require_once __DIR__ . '/PlatformParticipantMapper.php';
require_once __DIR__ . '/PlatformFieldLayout.php';
require_once __DIR__ . '/PlatformParticipantSync.php';
require_once __DIR__ . '/PlatformPullStatus.php';

/**
 * One implementation of "pull the participant list of an event from the platform", shared by the
 * HTTP button and the cron script. The download finishes before any participant is written, so a
 * platform failure never changes participants. Results and logs carry counts and registration ids only.
 */
final class PlatformParticipantPullService
{
    private const CHUNK = 200;
    private const CRON_ACTOR_NAME = 'Synchronizacja z platformą';
    private const LISTED_LIMIT = 50;

    /** @var callable(string, string, string, array): void */
    private $logger;
    /** @var callable(int): void */
    private $sleep;
    /** @var callable(): float */
    private $clock;

    /**
     * @param null|callable(string $level, string $code, string $message, array $context): void $logger
     * @param null|callable(int $seconds): void $sleep
     * @param null|callable(): float $clock
     */
    public function __construct(
        private readonly PlatformParticipantStore $store,
        private readonly PlatformIntegrationConfig $config,
        private readonly PlatformApiClient $client,
        ?callable $logger = null,
        ?callable $sleep = null,
        ?callable $clock = null
    ) {
        $this->logger = $logger ?? static function (string $level, string $code, string $message, array $context): void {
            ApplicationLogger::log($level, $code, $message, $context);
        };
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param 'manual'|'cron' $trigger
     * @param ?array{id?: ?string, name?: ?string} $actor the user behind a manual pull
     * @return array{status: string, summary: ?array<string, mixed>, skipped_records: list<array{registration_id: string, reason: string}>, possible_duplicate_registration_ids: list<string>}
     */
    public function pull(string $eventId, string $trigger, ?array $actor, int $maxAttempts): array
    {
        $startedAt = ($this->clock)();

        if (!$this->config->isAvailable()) {
            return $this->result('integration_unavailable');
        }

        $event = $this->store->findEventForPull($eventId);
        if ($event === null) {
            return $this->result('event_not_found');
        }

        $connection = $this->store->findConnection($eventId);
        if (!empty($event['archived_at']) || !empty($event['deleted_at'])) {
            return $connection === null
                ? $this->result('event_inactive')
                : $this->finish($eventId, $connection, $trigger, $actor, 'event_inactive', null, $startedAt);
        }

        if ($connection === null) {
            return $this->result('connection_missing');
        }

        if ((int)$connection['is_enabled'] !== 1) {
            return $this->finish($eventId, $connection, $trigger, $actor, 'connection_disabled', null, $startedAt);
        }

        $token = $this->config->cipher()->decrypt((string)$connection['organization_token_ciphertext'], $eventId);
        if ($token === null) {
            return $this->finish($eventId, $connection, $trigger, $actor, 'token_unreadable', null, $startedAt);
        }

        if (!$this->store->tryLock($eventId)) {
            unset($token);

            return $this->result('in_progress');
        }

        try {
            $this->store->markPullStarted($eventId, gmdate('Y-m-d H:i:s'), $trigger);

            $fetched = $this->client->fetchParticipants((string)$connection['platform_event_id'], $token, $maxAttempts, $this->sleep);
            unset($token);
            if ($fetched['status'] !== 'ok') {
                return $this->finish($eventId, $connection, $trigger, $actor, $fetched['status'], null, $startedAt);
            }

            $payload = PlatformParticipantPayload::parse((string)$fetched['body']);
            unset($fetched);
            if (!$payload['ok']) {
                return $this->finish($eventId, $connection, $trigger, $actor, $payload['status'], null, $startedAt);
            }

            return $this->apply($eventId, $connection, $trigger, $actor, $payload['entries'], $startedAt);
        } finally {
            $this->store->unlock($eventId);
        }
    }

    /**
     * @param array<string, mixed> $connection
     * @param list<mixed> $entries
     */
    private function apply(string $eventId, array $connection, string $trigger, ?array $actor, array $entries, float $startedAt): array
    {
        $mapped = PlatformParticipantMapper::mapAll($entries);
        $records = $mapped['records'];
        $storedLayout = is_string($connection['field_layout_json'] ?? null) ? decodeJsonObject($connection['field_layout_json']) : [];

        $layout = ['alias_by_key' => [], 'source_by_key' => [], 'to_create' => [], 'important_aliases' => []];
        if ($records !== []) {
            $layout = PlatformFieldLayout::plan($this->store->loadFieldMappings($eventId), $mapped['field_definitions'], $storedLayout);
        }

        $existing = $this->store->loadEventParticipants($eventId);
        $codes = [];
        foreach ($records as $record) {
            if ($record['qr_code'] !== null) {
                $codes[] = $record['qr_code'];
            }
        }
        $owners = $codes === [] ? [] : $this->store->findQrCodeOwners($codes);

        $nowUtc = gmdate('Y-m-d H:i:s');
        $plan = PlatformParticipantSync::plan($existing, $records, $layout['alias_by_key'], $layout['important_aliases'], $owners, $nowUtc);

        $skipped = [];
        foreach ($mapped['invalid'] as $invalid) {
            if ($invalid['registration_id'] !== null) {
                $skipped[] = ['registration_id' => $invalid['registration_id'], 'reason' => $invalid['reason']];
            } else {
                $skipped[] = ['registration_id' => '', 'reason' => $invalid['reason']];
            }
        }
        foreach ($plan['skipped'] as $item) {
            $skipped[] = $item;
            $this->log('warning', 'platform_pull.qr_collision', 'Platform start code is already used by another participant', [
                'event_id' => $eventId,
                'platform_registration_id' => $item['registration_id'],
            ]);
        }

        $counts = ['created' => 0, 'updated' => 0, 'removed' => 0];
        $failed = false;

        try {
            $layoutChanged = $layout['to_create'] !== [] || ($records !== [] && $layout['source_by_key'] !== $storedLayout);
            if ($layoutChanged) {
                $this->store->begin();
                $this->store->createFieldMappings($eventId, $layout['to_create']);
                $this->store->saveFieldLayout($eventId, $layout['source_by_key']);
                $this->store->commit();
            }

            $work = [];
            foreach ($plan['creates'] as $create) {
                $work[] = ['create', $create];
            }
            foreach ($plan['updates'] as $update) {
                $work[] = ['update', $update];
            }

            foreach (array_chunk($work, self::CHUNK) as $chunk) {
                $chunkCounts = ['created' => 0, 'updated' => 0];
                $chunkSkipped = [];
                try {
                    $this->store->begin();
                    foreach ($chunk as [$kind, $item]) {
                        if ($kind === 'create') {
                            $id = $this->store->insertParticipant($eventId, $item);
                            if ($id === null) {
                                $chunkSkipped[] = ['registration_id' => $item['registration_id'], 'reason' => 'qr_collision'];
                                continue;
                            }
                            $chunkCounts['created']++;
                            if ($plan['had_platform_rows']) {
                                $this->store->addChangeLog($eventId, [
                                    'change_type' => 'added',
                                    'change_source' => 'platform_sync',
                                    'participant_id' => $id,
                                    'before_state' => [],
                                    'after_state' => PlatformParticipantSync::changeState($item),
                                    'changed_fields' => [],
                                    'user_id' => $actor['id'] ?? null,
                                    'user_name' => $this->actorName($actor),
                                ]);
                            }
                            continue;
                        }

                        if (!$this->store->updateParticipant($item['id'], $item['changes'])) {
                            $chunkSkipped[] = ['registration_id' => $item['registration_id'], 'reason' => 'qr_collision'];
                            continue;
                        }
                        if ($item['changed_fields'] !== []) {
                            $chunkCounts['updated']++;
                            $this->store->addChangeLog($eventId, [
                                'change_type' => 'updated',
                                'change_source' => 'platform_sync',
                                'participant_id' => $item['id'],
                                'participant_audit_key' => $item['audit_key'],
                                'before_state' => $item['before'],
                                'after_state' => $item['after'],
                                'changed_fields' => $item['changed_fields'],
                                'user_id' => $actor['id'] ?? null,
                                'user_name' => $this->actorName($actor),
                            ]);
                        }
                    }
                    $this->store->commit();
                } catch (Throwable $exception) {
                    $this->store->rollBack();
                    throw $exception;
                }

                $counts['created'] += $chunkCounts['created'];
                $counts['updated'] += $chunkCounts['updated'];
                foreach ($chunkSkipped as $item) {
                    $skipped[] = $item;
                    $this->log('warning', 'platform_pull.qr_collision', 'Platform start code is already used by another participant', [
                        'event_id' => $eventId,
                        'platform_registration_id' => $item['registration_id'],
                    ]);
                }
            }

            if ($plan['removals'] !== []) {
                $byId = [];
                foreach ($existing as $row) {
                    $byId[(int)$row['id']] = $row;
                }

                try {
                    $this->store->begin();
                    $this->store->markRemoved($plan['removals'], $nowUtc);
                    foreach ($plan['removals'] as $participantId) {
                        $state = PlatformParticipantSync::changeState($byId[$participantId]);
                        $this->store->addChangeLog($eventId, [
                            'change_type' => 'updated',
                                'change_source' => 'platform_sync',
                            'participant_id' => $participantId,
                            'participant_audit_key' => (string)$byId[$participantId]['participant_audit_key'],
                            'before_state' => $state,
                            'after_state' => $state,
                            'changed_fields' => ['platform_removed_at'],
                            'user_id' => $actor['id'] ?? null,
                            'user_name' => $this->actorName($actor),
                        ]);
                    }
                    $this->store->commit();
                } catch (Throwable $exception) {
                    $this->store->rollBack();
                    throw $exception;
                }
                $counts['removed'] = count($plan['removals']);
            }
        } catch (Throwable $exception) {
            $failed = true;
            $this->log('error', 'platform_pull.apply_failed', 'Platform pull stopped by a database error', [
                'event_id' => $eventId,
                'exception_class' => $exception::class,
            ]);
        }

        $reasons = [];
        foreach ($skipped as $item) {
            $reasons[$item['reason']] = ($reasons[$item['reason']] ?? 0) + 1;
        }
        $warnings = array_merge(['missing_email' => 0, 'start_code_generated' => 0, 'empty_list_ignored' => 0], $plan['warnings']);
        $summary = [
            'fetched' => count($entries),
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'unchanged' => $plan['unchanged'],
            'skipped' => count($skipped),
            'skipped_by_reason' => $reasons,
            'removed' => $counts['removed'],
            'restored' => $failed ? 0 : $plan['restored'],
            'possible_duplicates' => count($plan['possible_duplicates']),
            'warnings' => $warnings,
            'duration_ms' => (int)round((($this->clock)() - $startedAt) * 1000),
        ];

        $status = $failed ? 'apply_failed' : ($summary['skipped'] > 0 || array_sum($warnings) > 0 ? 'partial' : 'ok');

        return $this->finish(
            $eventId,
            $connection,
            $trigger,
            $actor,
            $status,
            $summary,
            $startedAt,
            array_slice($skipped, 0, self::LISTED_LIMIT),
            array_slice($plan['possible_duplicates'], 0, self::LISTED_LIMIT)
        );
    }

    /**
     * Stores the outcome, writes the activity row and the application log.
     *
     * @param array<string, mixed> $connection
     * @param ?array<string, mixed> $summary
     * @param list<array{registration_id: string, reason: string}> $skippedRecords
     * @param list<string> $duplicateIds
     */
    private function finish(
        string $eventId,
        array $connection,
        string $trigger,
        ?array $actor,
        string $status,
        ?array $summary,
        float $startedAt,
        array $skippedRecords = [],
        array $duplicateIds = []
    ): array {
        $this->store->recordPullResult($eventId, gmdate('Y-m-d H:i:s'), $status, $summary ?? []);

        $success = PlatformPullStatus::isSuccess($status);
        $eventful = $summary !== null && (
            $summary['created'] + $summary['updated'] + $summary['removed'] + $summary['restored'] + $summary['skipped'] > 0
        );
        if ($trigger !== 'cron' || !$success || $eventful) {
            $this->store->addActivityLog(
                $eventId,
                PlatformPullStatus::activityAction($status, $trigger, $summary),
                $actor['id'] ?? null,
                $this->actorName($actor),
                $this->activityMetadata($connection, $trigger, $status, $summary)
            );
        }

        $context = ['event_id' => $eventId, 'platform_event_id' => (string)$connection['platform_event_id'], 'status' => $status, 'trigger' => $trigger];
        if ($summary !== null) {
            foreach (['fetched', 'created', 'updated', 'unchanged', 'skipped', 'removed', 'restored', 'duration_ms'] as $key) {
                $context[$key] = $summary[$key];
            }
        }
        $this->log(
            $success ? 'info' : 'warning',
            $success ? 'platform_pull.completed' : (in_array($status, ['connection_disabled', 'token_unreadable', 'event_inactive'], true) ? 'platform_pull.skipped' : 'platform_pull.failed'),
            $success ? 'Platform participants pulled' : 'Platform participant pull did not complete',
            $context
        );

        return [
            'status' => $status,
            'summary' => $summary,
            'skipped_records' => $skippedRecords,
            'possible_duplicate_registration_ids' => $duplicateIds,
        ];
    }

    /** @return array<string, mixed> */
    private function activityMetadata(array $connection, string $trigger, string $status, ?array $summary): array
    {
        $metadata = ['trigger' => $trigger, 'status' => $status, 'platform_event_id' => (string)$connection['platform_event_id']];
        foreach (['created', 'updated', 'unchanged', 'skipped', 'removed', 'restored', 'possible_duplicates', 'duration_ms'] as $key) {
            if ($summary !== null) {
                $metadata[$key] = (int)$summary[$key];
            }
        }

        return $metadata;
    }

    /** @return array{status: string, summary: null, skipped_records: list<never>, possible_duplicate_registration_ids: list<never>} */
    private function result(string $status): array
    {
        return ['status' => $status, 'summary' => null, 'skipped_records' => [], 'possible_duplicate_registration_ids' => []];
    }

    private function actorName(?array $actor): string
    {
        $name = isset($actor['name']) ? trim((string)$actor['name']) : '';

        return $name !== '' ? $name : self::CRON_ACTOR_NAME;
    }

    private function log(string $level, string $code, string $message, array $context): void
    {
        ($this->logger)($level, $code, $message, $context);
    }
}
