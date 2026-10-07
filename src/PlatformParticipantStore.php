<?php

declare(strict_types=1);

/** Everything the platform pull needs from the database. */
interface PlatformParticipantStore
{
    /** @return ?array<string, mixed> id, name, event_date, office_open_at, office_close_at, archived_at, deleted_at */
    public function findEventForPull(string $eventId): ?array;

    /** @return ?array<string, mixed> */
    public function findConnection(string $eventId): ?array;

    /** @return list<array<string, mixed>> enabled connections joined with their events (id = BZ event id) */
    public function listEnabledConnections(): array;

    /** Non-blocking per-event lock; false when somebody else holds it. */
    public function tryLock(string $eventId): bool;

    public function unlock(string $eventId): void;

    public function markPullStarted(string $eventId, string $startedUtc, string $trigger): void;

    /** @param array<string, mixed> $summary counts only */
    public function recordPullResult(string $eventId, string $finishedUtc, string $status, array $summary): void;

    /** @return list<array<string, mixed>> */
    public function loadFieldMappings(string $eventId): array;

    /** @param list<array<string, mixed>> $mappings */
    public function createFieldMappings(string $eventId, array $mappings): void;

    /** @param array<string, string> $sourceByKey */
    public function saveFieldLayout(string $eventId, array $sourceByKey): void;

    /** @return list<array<string, mixed>> */
    public function loadEventParticipants(string $eventId): array;

    /**
     * @param list<string> $codes
     * @return array<string, array{id: int, event_id: ?string}> start code => owner
     */
    public function findQrCodeOwners(array $codes): array;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    /** @param array<string, mixed> $create @return ?int new id, null when a unique key refused the row */
    public function insertParticipant(string $eventId, array $create): ?int;

    /** @param array<string, mixed> $changes @return bool false when a unique key refused the change */
    public function updateParticipant(int $participantId, array $changes): bool;

    /** @param list<int> $participantIds */
    public function markRemoved(array $participantIds, string $removedUtc): void;

    /** @param array<string, mixed> $entry */
    public function addChangeLog(string $eventId, array $entry): void;

    /** @param array<string, mixed> $metadata */
    public function addActivityLog(string $eventId, string $action, ?string $userId, ?string $userName, array $metadata): void;
}
