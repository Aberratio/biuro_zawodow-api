<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformPushQueueStore.php';
require_once __DIR__ . '/PlatformPushQueueEntry.php';
require_once __DIR__ . '/PlatformConnectionRepository.php';
require_once __DIR__ . '/PdoPlatformParticipantStore.php';

/**
 * PDO implementation of the push queue. Every statement uses distinct named placeholders; JSON_MERGE_PATCH
 * exists in MariaDB 10.4 and MySQL 8.4 alike.
 */
final class PdoPlatformPushQueueStore implements PlatformPushQueueStore
{
    private const GLOBAL_LOCK = 'bz_platform_push';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(string $eventId, int $participantId, array $fields, string $nowUtc): void
    {
        $json = (string)encodeJsonValue($fields);
        $stmt = $this->pdo->prepare("
            INSERT INTO platform_push_queue (event_id, participant_id, fields_json, status, attempts, created_at, updated_at)
            VALUES (:event_id, :participant_id, :fields_json, 'pending', 0, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                fields_json = JSON_MERGE_PATCH(fields_json, :fields_json_merge),
                status = 'pending',
                attempts = 0,
                next_attempt_at = NULL,
                last_error = NULL,
                updated_at = :updated_at_merge
        ");
        $stmt->bindValue(':event_id', $eventId);
        $stmt->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $stmt->bindValue(':fields_json', $json);
        $stmt->bindValue(':created_at', $nowUtc);
        $stmt->bindValue(':updated_at', $nowUtc);
        $stmt->bindValue(':fields_json_merge', $json);
        $stmt->bindValue(':updated_at_merge', $nowUtc);
        $stmt->execute();
    }

    public function listDue(string $nowUtc, int $limit, ?string $eventId = null): array
    {
        $sql = "SELECT id, event_id, participant_id, fields_json, status, attempts FROM platform_push_queue WHERE status IN ('pending', 'failed')";
        if ($eventId === null) {
            $sql .= ' AND (next_attempt_at IS NULL OR next_attempt_at <= :now_utc)';
        } else {
            $sql .= ' AND event_id = :event_id';
        }
        $sql .= ' ORDER BY id ASC LIMIT :row_limit';

        $stmt = $this->pdo->prepare($sql);
        if ($eventId === null) {
            $stmt->bindValue(':now_utc', $nowUtc);
        } else {
            $stmt->bindValue(':event_id', $eventId);
        }
        $stmt->bindValue(':row_limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int)$row['id'],
                'event_id' => (string)$row['event_id'],
                'participant_id' => (int)$row['participant_id'],
                'fields' => PlatformPushQueueEntry::decode((string)$row['fields_json']),
                'status' => (string)$row['status'],
                'attempts' => (int)$row['attempts'],
            ];
        }

        return $rows;
    }

    public function claim(int $queueId, int $seenAttempts, string $nowUtc): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE platform_push_queue
            SET attempts = attempts + 1, last_attempt_at = :now_utc
            WHERE id = :queue_id AND attempts = :seen_attempts AND status IN ('pending', 'failed')
        ");
        $stmt->bindValue(':now_utc', $nowUtc);
        $stmt->bindValue(':queue_id', $queueId, PDO::PARAM_INT);
        $stmt->bindValue(':seen_attempts', $seenAttempts, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function settle(int $queueId, array $sentFields, array $rejections, string $nowUtc, ?int $httpStatus): string
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $select = $this->pdo->prepare('SELECT fields_json FROM platform_push_queue WHERE id = :queue_id FOR UPDATE');
            $select->bindValue(':queue_id', $queueId, PDO::PARAM_INT);
            $select->execute();
            $stored = PlatformPushQueueEntry::decode((string)$select->fetchColumn());
            $remaining = PlatformPushQueueEntry::settle($stored, $sentFields);
            $status = $remaining === [] ? 'sent' : 'pending';

            $update = $this->pdo->prepare('
                UPDATE platform_push_queue
                SET fields_json = :fields_json, status = :status, attempts = :attempts, next_attempt_at = NULL,
                    last_error = NULL, last_http_status = :http_status, last_rejections_json = :rejections_json,
                    updated_at = :updated_at
                WHERE id = :queue_id
            ');
            $update->bindValue(':fields_json', (string)encodeJsonValue($remaining === [] ? new stdClass() : $remaining));
            $update->bindValue(':status', $status);
            $update->bindValue(':attempts', 0, PDO::PARAM_INT);
            $update->bindValue(':http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $update->bindValue(':rejections_json', $rejections === [] ? null : (string)encodeJsonValue($rejections), $rejections === [] ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $update->bindValue(':updated_at', $nowUtc);
            $update->bindValue(':queue_id', $queueId, PDO::PARAM_INT);
            $update->execute();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $status;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markFailed(int $queueId, string $error, ?int $httpStatus, int $retryInSeconds, string $nowUtc): void
    {
        $this->setState($queueId, 'failed', $error, $httpStatus, gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') + $retryInSeconds), $nowUtc);
    }

    public function defer(int $queueId, string $error, int $retryInSeconds, string $nowUtc): void
    {
        $this->setState($queueId, 'failed', $error, null, gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') + $retryInSeconds), $nowUtc);
    }

    public function markAbandoned(int $queueId, string $error, ?int $httpStatus, string $nowUtc): void
    {
        $this->setState($queueId, 'abandoned', $error, $httpStatus, null, $nowUtc);
    }

    private function setState(int $queueId, string $status, string $error, ?int $httpStatus, ?string $nextAttemptUtc, string $nowUtc): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE platform_push_queue
            SET status = :status, last_error = :last_error, last_http_status = :http_status,
                next_attempt_at = :next_attempt_at, updated_at = :updated_at
            WHERE id = :queue_id
        ');
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':last_error', $error);
        $stmt->bindValue(':http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':next_attempt_at', $nextAttemptUtc, $nextAttemptUtc === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $nowUtc);
        $stmt->bindValue(':queue_id', $queueId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function retryAbandoned(?string $eventId, string $nowUtc): int
    {
        $sql = "UPDATE platform_push_queue SET status = 'pending', attempts = 0, next_attempt_at = NULL, last_error = NULL, updated_at = :updated_at WHERE status = 'abandoned'";
        if ($eventId !== null) {
            $sql .= ' AND event_id = :event_id';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':updated_at', $nowUtc);
        if ($eventId !== null) {
            $stmt->bindValue(':event_id', $eventId);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function findParticipant(int $participantId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, event_id, display_name, first_name, last_name, email, bib_number, checked_in_at,
                   custom_fields_json, platform_registration_id
            FROM participants
            WHERE id = :participant_id
        ');
        $stmt->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $decoded = decodeJsonObject($row['custom_fields_json'] !== null ? (string)$row['custom_fields_json'] : null);
        unset($decoded[PdoPlatformParticipantStore::IMPORTANT_FIELDS_META_KEY]);
        $custom = [];
        foreach ($decoded as $name => $value) {
            $custom[trim((string)$name)] = is_scalar($value) ? trim((string)$value) : '';
        }

        return [
            'id' => (int)$row['id'],
            'event_id' => (string)$row['event_id'],
            'display_name' => (string)$row['display_name'],
            'first_name' => $row['first_name'] !== null ? (string)$row['first_name'] : null,
            'last_name' => $row['last_name'] !== null ? (string)$row['last_name'] : null,
            'email' => (string)$row['email'],
            'bib_number' => $row['bib_number'] !== null ? (string)$row['bib_number'] : null,
            'checked_in_at' => $row['checked_in_at'] !== null ? (string)$row['checked_in_at'] : null,
            'custom_fields' => $custom,
            'platform_registration_id' => $row['platform_registration_id'] !== null ? (string)$row['platform_registration_id'] : null,
        ];
    }

    public function findConnection(string $eventId): ?array
    {
        return (new PlatformConnectionRepository($this->pdo))->find($eventId);
    }

    public function aliasByKey(string $eventId): array
    {
        $layoutStmt = $this->pdo->prepare('SELECT field_layout_json FROM event_platform_connections WHERE event_id = :event_id');
        $layoutStmt->execute(['event_id' => $eventId]);
        $layout = decodeJsonObject(($value = $layoutStmt->fetchColumn()) !== false && $value !== null ? (string)$value : null);
        if ($layout === []) {
            return [];
        }

        $mappingStmt = $this->pdo->prepare('SELECT source_column_name, alias FROM event_participant_field_mappings WHERE event_id = :event_id AND is_active = 1');
        $mappingStmt->execute(['event_id' => $eventId]);
        $aliasBySource = [];
        foreach ($mappingStmt->fetchAll() as $mapping) {
            $aliasBySource[(string)$mapping['source_column_name']] = (string)$mapping['alias'];
        }

        $result = [];
        foreach ($layout as $key => $source) {
            if (is_string($key) && is_string($source) && isset($aliasBySource[$source])) {
                $result[$key] = $aliasBySource[$source];
            }
        }

        return $result;
    }

    /** @return ?array{pending: int, failed: int} null when the queue table is not there yet */
    public function countsForEvent(string $eventId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS total FROM platform_push_queue WHERE event_id = :event_id GROUP BY status');
            $stmt->execute(['event_id' => $eventId]);
            $counts = ['pending' => 0, 'failed' => 0];
            foreach ($stmt->fetchAll() as $row) {
                if (isset($counts[(string)$row['status']])) {
                    $counts[(string)$row['status']] = (int)$row['total'];
                }
            }

            return $counts;
        } catch (PDOException) {
            return null;
        }
    }

    public function addActivity(string $eventId, ?int $participantId, ?string $participantName, string $action, array $metadata): void
    {
        $text = mb_strlen($action) > 190 ? mb_substr($action, 0, 189) . '…' : $action;
        $stmt = $this->pdo->prepare('
            INSERT INTO activity_logs (
                id, action, event_id, participant_id, participant_name_snapshot, user_id, user_name_snapshot
            ) VALUES (
                :id, :action, :event_id, :participant_id, :participant_name_snapshot, NULL, :user_name_snapshot
            )
        ');
        $stmt->execute([
            'id' => 'log-' . bin2hex(random_bytes(8)),
            'action' => $text,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'participant_name_snapshot' => $participantName,
            'user_name_snapshot' => 'Biuro Zawodów',
        ]);

        addAuditLog($this->pdo, [
            'action' => $text,
            'actor_user_id' => null,
            'actor_name' => 'Biuro Zawodów',
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'target_type' => 'participant',
            'target_id' => $participantId !== null ? (string)$participantId : $eventId,
            'metadata' => array_merge(['legacy_activity_log' => true], $metadata),
        ]);
    }

    public function tryGlobalLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $stmt->execute(['lock_name' => self::GLOBAL_LOCK]);

        return (int)$stmt->fetchColumn() === 1;
    }

    public function releaseGlobalLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $stmt->execute(['lock_name' => self::GLOBAL_LOCK]);
        $stmt->fetchAll();
    }
}
