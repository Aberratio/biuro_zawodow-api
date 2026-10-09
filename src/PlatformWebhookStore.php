<?php

declare(strict_types=1);

/** Everything the critical-change webhook needs from the database. */
interface PlatformWebhookStore
{
    /** Per-event lock shared with the participant pull; waits up to $waitSeconds. */
    public function lockEvent(string $eventId, int $waitSeconds): bool;

    public function unlockEvent(string $eventId): void;

    /** @return ?array<string, mixed> */
    public function findReceipt(string $eventId, string $dedupeKey): ?array;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * @param array<string, mixed> $receipt event_id, dedupe_key, platform_registration_id, change_type, occurred_at, fingerprint, outcome, received_at
     * @return ?int new id, null when the dedupe key already exists
     */
    public function insertReceipt(array $receipt): ?int;

    public function setReceiptOutcome(int $receiptId, string $outcome, ?int $participantId): void;

    /** @return ?array<string, mixed> id, participant_audit_key, display_name, email, bib_number, payment_status, custom_fields, important_field_aliases, platform_removed_at */
    public function findParticipant(string $eventId, string $registrationId): ?array;

    /** @return array{last_pull_started_at: ?string, last_pull_status: ?string} */
    public function connectionPullState(string $eventId): array;

    /** @return bool false when the participant was already flagged */
    public function markParticipantRemoved(int $participantId, string $removedUtc): bool;

    public function requestPull(string $eventId, string $nowUtc): void;

    public function touchWebhook(string $eventId, string $nowUtc): void;

    /** @param array<string, mixed> $entry */
    public function addChangeLog(string $eventId, array $entry): void;

    /** @param array<string, mixed> $metadata */
    public function addActivityLog(string $eventId, string $action, array $metadata, ?int $participantId = null, ?string $participantName = null): void;
}
