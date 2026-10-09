<?php

declare(strict_types=1);

/** Persistence for event_platform_connections. Every statement uses distinct named placeholders. */
final class PlatformConnectionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT *, UNIX_TIMESTAMP(updated_at) AS updated_at_unix FROM event_platform_connections WHERE event_id = :event_id LIMIT 1');
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findEventIdByPlatformEventId(string $platformEventId, string $exceptEventId): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT event_id FROM event_platform_connections
            WHERE platform_event_id = :platform_event_id AND event_id <> :except_event_id
            LIMIT 1
        ');
        $stmt->execute(['platform_event_id' => $platformEventId, 'except_event_id' => $exceptEventId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string)$value;
    }

    /** The connection for a platform (ZC) event id, which is unique across all BZ events. */
    public function findByPlatformEventId(string $platformEventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_platform_connections WHERE platform_event_id = :platform_event_id LIMIT 1');
        $stmt->execute(['platform_event_id' => $platformEventId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the connection. A null $ciphertext keeps the stored token (and its hint).
     * A new token or a new platform event id clears the last test result.
     */
    public function save(
        string $eventId,
        string $platformEventId,
        bool $isEnabled,
        ?string $ciphertext,
        ?string $hint,
        string $userId
    ): void {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $select = $this->pdo->prepare('SELECT platform_event_id FROM event_platform_connections WHERE event_id = :event_id FOR UPDATE');
            $select->execute(['event_id' => $eventId]);
            $existing = $select->fetch();
            $now = gmdate('Y-m-d H:i:s');

            if ($existing === false) {
                if ($ciphertext === null || $hint === null) {
                    throw new InvalidArgumentException('token_required');
                }

                $insert = $this->pdo->prepare('
                    INSERT INTO event_platform_connections (
                        event_id, platform_event_id, is_enabled, organization_token_ciphertext,
                        organization_token_hint, token_updated_at, created_by_user_id, updated_by_user_id
                    ) VALUES (
                        :event_id, :platform_event_id, :is_enabled, :ciphertext,
                        :hint, :token_updated_at, :created_by_user_id, :updated_by_user_id
                    )
                ');
                $insert->execute([
                    'event_id' => $eventId,
                    'platform_event_id' => $platformEventId,
                    'is_enabled' => $isEnabled ? 1 : 0,
                    'ciphertext' => $ciphertext,
                    'hint' => $hint,
                    'token_updated_at' => $now,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                ]);
            } else {
                $resetTest = $ciphertext !== null || (string)$existing['platform_event_id'] !== $platformEventId;
                $sql = 'UPDATE event_platform_connections SET
                        platform_event_id = :platform_event_id,
                        is_enabled = :is_enabled,
                        updated_by_user_id = :updated_by_user_id';
                $params = [
                    'platform_event_id' => $platformEventId,
                    'is_enabled' => $isEnabled ? 1 : 0,
                    'updated_by_user_id' => $userId,
                    'event_id' => $eventId,
                ];
                if ($ciphertext !== null && $hint !== null) {
                    $sql .= ', organization_token_ciphertext = :ciphertext, organization_token_hint = :hint, token_updated_at = :token_updated_at';
                    $params['ciphertext'] = $ciphertext;
                    $params['hint'] = $hint;
                    $params['token_updated_at'] = $now;
                }
                if ($resetTest) {
                    $sql .= ', last_test_at = NULL, last_test_status = NULL';
                }
                $sql .= ' WHERE event_id = :event_id';
                $this->pdo->prepare($sql)->execute($params);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function recordTest(string $eventId, string $status, string $testedAtUtc): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE event_platform_connections
            SET last_test_at = :last_test_at, last_test_status = :last_test_status
            WHERE event_id = :event_id
        ');
        $stmt->execute(['last_test_at' => $testedAtUtc, 'last_test_status' => $status, 'event_id' => $eventId]);
    }

    public function delete(string $eventId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM event_platform_connections WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);

        return $stmt->rowCount() > 0;
    }
}
