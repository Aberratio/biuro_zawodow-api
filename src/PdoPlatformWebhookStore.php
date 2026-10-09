<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformWebhookStore.php';
require_once __DIR__ . '/PdoPlatformParticipantStore.php';
require_once __DIR__ . '/PlatformPullLock.php';

/** PDO implementation of the webhook storage. Every statement uses distinct named placeholders. */
final class PdoPlatformWebhookStore implements PlatformWebhookStore
{
    private PdoPlatformParticipantStore $participants;

    public function __construct(private readonly PDO $pdo)
    {
        $this->participants = new PdoPlatformParticipantStore($pdo);
    }

    public function lockEvent(string $eventId, int $waitSeconds): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, :wait_seconds)');
        $stmt->bindValue(':lock_name', PlatformPullLock::name($eventId));
        $stmt->bindValue(':wait_seconds', $waitSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn() === 1;
    }

    public function unlockEvent(string $eventId): void
    {
        $this->participants->unlock($eventId);
    }

    public function findReceipt(string $eventId, string $dedupeKey): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, fingerprint, outcome
            FROM platform_webhook_receipts
            WHERE event_id = :event_id AND dedupe_key = :dedupe_key
            LIMIT 1
        ');
        $stmt->execute(['event_id' => $eventId, 'dedupe_key' => $dedupeKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function begin(): void
    {
        $this->participants->begin();
    }

    public function commit(): void
    {
        $this->participants->commit();
    }

    public function rollBack(): void
    {
        $this->participants->rollBack();
    }

    public function insertReceipt(array $receipt): ?int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO platform_webhook_receipts (
                event_id, dedupe_key, platform_registration_id, change_type, occurred_at, fingerprint, outcome, received_at
            ) VALUES (
                :event_id, :dedupe_key, :platform_registration_id, :change_type, :occurred_at, :fingerprint, :outcome, :received_at
            )
        ');

        try {
            $stmt->execute([
                'event_id' => $receipt['event_id'],
                'dedupe_key' => $receipt['dedupe_key'],
                'platform_registration_id' => $receipt['platform_registration_id'],
                'change_type' => $receipt['change_type'],
                'occurred_at' => $receipt['occurred_at'],
                'fingerprint' => $receipt['fingerprint'],
                'outcome' => $receipt['outcome'],
                'received_at' => $receipt['received_at'],
            ]);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }

        return (int)$this->pdo->lastInsertId();
    }

    public function setReceiptOutcome(int $receiptId, string $outcome, ?int $participantId): void
    {
        $stmt = $this->pdo->prepare('UPDATE platform_webhook_receipts SET outcome = :outcome, participant_id = :participant_id WHERE id = :receipt_id');
        $stmt->bindValue(':outcome', $outcome);
        $stmt->bindValue(':participant_id', $participantId, $participantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':receipt_id', $receiptId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function findParticipant(string $eventId, string $registrationId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, participant_audit_key, display_name, email, bib_number, payment_status, custom_fields_json, platform_removed_at
            FROM participants
            WHERE event_id = :event_id AND platform_registration_id = :registration_id
            LIMIT 1
        ');
        $stmt->execute(['event_id' => $eventId, 'registration_id' => $registrationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $decoded = decodeJsonObject($row['custom_fields_json'] !== null ? (string)$row['custom_fields_json'] : null);
        $important = [];
        if (isset($decoded[PdoPlatformParticipantStore::IMPORTANT_FIELDS_META_KEY]) && is_array($decoded[PdoPlatformParticipantStore::IMPORTANT_FIELDS_META_KEY])) {
            foreach ($decoded[PdoPlatformParticipantStore::IMPORTANT_FIELDS_META_KEY] as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    $important[] = trim($alias);
                }
            }
        }
        unset($decoded[PdoPlatformParticipantStore::IMPORTANT_FIELDS_META_KEY]);
        $custom = [];
        foreach ($decoded as $name => $value) {
            $custom[trim((string)$name)] = is_scalar($value) ? trim((string)$value) : '';
        }

        return [
            'id' => (int)$row['id'],
            'participant_audit_key' => (string)$row['participant_audit_key'],
            'display_name' => (string)$row['display_name'],
            'email' => (string)$row['email'],
            'bib_number' => $row['bib_number'] !== null ? (string)$row['bib_number'] : null,
            'payment_status' => (string)$row['payment_status'],
            'custom_fields' => $custom,
            'important_field_aliases' => array_values(array_unique($important)),
            'platform_removed_at' => $row['platform_removed_at'] !== null ? (string)$row['platform_removed_at'] : null,
        ];
    }

    public function connectionPullState(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT last_pull_started_at, last_pull_status FROM event_platform_connections WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch();

        return [
            'last_pull_started_at' => $row !== false && $row['last_pull_started_at'] !== null ? (string)$row['last_pull_started_at'] : null,
            'last_pull_status' => $row !== false && $row['last_pull_status'] !== null ? (string)$row['last_pull_status'] : null,
        ];
    }

    public function markParticipantRemoved(int $participantId, string $removedUtc): bool
    {
        $stmt = $this->pdo->prepare('UPDATE participants SET platform_removed_at = :removed_at WHERE id = :participant_id AND platform_removed_at IS NULL');
        $stmt->bindValue(':removed_at', $removedUtc);
        $stmt->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function requestPull(string $eventId, string $nowUtc): void
    {
        $stmt = $this->pdo->prepare('UPDATE event_platform_connections SET pull_requested_at = :requested_at WHERE event_id = :event_id');
        $stmt->execute(['requested_at' => $nowUtc, 'event_id' => $eventId]);
    }

    public function touchWebhook(string $eventId, string $nowUtc): void
    {
        $stmt = $this->pdo->prepare('UPDATE event_platform_connections SET last_webhook_at = :webhook_at WHERE event_id = :event_id');
        $stmt->execute(['webhook_at' => $nowUtc, 'event_id' => $eventId]);
    }

    public function addChangeLog(string $eventId, array $entry): void
    {
        $this->participants->addChangeLog($eventId, $entry);
    }

    public function addActivityLog(string $eventId, string $action, array $metadata, ?int $participantId = null, ?string $participantName = null): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO activity_logs (
                id, action, event_id, participant_id, participant_name_snapshot, user_id, user_name_snapshot
            ) VALUES (
                :id, :action, :event_id, :participant_id, :participant_name_snapshot, NULL, :user_name_snapshot
            )
        ');
        $stmt->execute([
            'id' => 'log-' . bin2hex(random_bytes(8)),
            'action' => mb_strlen($action) > 190 ? mb_substr($action, 0, 189) . '…' : $action,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'participant_name_snapshot' => $participantName,
            'user_name_snapshot' => PlatformCriticalChangeService::ACTOR_NAME,
        ]);

        addAuditLog($this->pdo, [
            'action' => $action,
            'actor_user_id' => null,
            'actor_name' => PlatformCriticalChangeService::ACTOR_NAME,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'target_type' => 'participant',
            'target_id' => $participantId !== null ? (string)$participantId : $eventId,
            'metadata' => array_merge(['legacy_activity_log' => true], $metadata),
        ]);
    }
}
