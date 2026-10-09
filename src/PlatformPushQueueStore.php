<?php

declare(strict_types=1);

/** Everything the push queue needs from the database. */
interface PlatformPushQueueStore
{
    /** Merges the fields into the participant's queue row (one row per participant) and makes it pending again. */
    public function enqueue(string $eventId, int $participantId, array $fields, string $nowUtc): void;

    /**
     * @return list<array{id: int, event_id: string, participant_id: int, fields: array<string, array<string, string>>, status: string, attempts: int}>
     */
    public function listDue(string $nowUtc, int $limit, ?string $eventId = null): array;

    /** Counts the attempt; false when somebody else touched the row since it was listed. */
    public function claim(int $queueId, int $seenAttempts, string $nowUtc): bool;

    /**
     * Removes the delivered fields; fields that changed meanwhile stay queued.
     *
     * @param array<string, array<string, string>> $sentFields
     * @param array<string, string> $rejections field key => reason code
     * @return 'sent'|'pending'
     */
    public function settle(int $queueId, array $sentFields, array $rejections, string $nowUtc, ?int $httpStatus): string;

    public function markFailed(int $queueId, string $error, ?int $httpStatus, int $retryInSeconds, string $nowUtc): void;

    /** Waits without consuming an attempt (disabled connection, unreadable token). */
    public function defer(int $queueId, string $error, int $retryInSeconds, string $nowUtc): void;

    public function markAbandoned(int $queueId, string $error, ?int $httpStatus, string $nowUtc): void;

    /** @return int rows made pending again */
    public function retryAbandoned(?string $eventId, string $nowUtc): int;

    /** @return ?array<string, mixed> checked_in_at, email, bib_number, first_name, last_name, display_name, custom_fields, platform_registration_id */
    public function findParticipant(int $participantId): ?array;

    /** @return ?array<string, mixed> event_platform_connections row */
    public function findConnection(string $eventId): ?array;

    /** @return array<string, string> platform field key => BZ alias */
    public function aliasByKey(string $eventId): array;

    public function addActivity(string $eventId, ?int $participantId, ?string $participantName, string $action, array $metadata): void;

    public function tryGlobalLock(): bool;

    public function releaseGlobalLock(): void;
}
