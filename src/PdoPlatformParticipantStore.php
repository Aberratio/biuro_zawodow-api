<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformParticipantStore.php';
require_once __DIR__ . '/PlatformConnectionRepository.php';
require_once __DIR__ . '/PlatformPullLock.php';

/**
 * PDO implementation of the platform pull storage. All statements use distinct named placeholders;
 * participant updates go through a fixed column allowlist that never contains check-in state.
 */
final class PdoPlatformParticipantStore implements PlatformParticipantStore
{
    /** Must equal $participantImportantFieldsMetaKey in public/index.php (contract test). */
    public const IMPORTANT_FIELDS_META_KEY = '__important_field_aliases';

    private const UPDATABLE_COLUMNS = [
        'display_name',
        'first_name',
        'last_name',
        'email',
        'bib_number',
        'payment_status',
        'qr_code',
        'identity_code',
        'custom_fields_json',
        'platform_snapshot_json',
        'platform_removed_at',
    ];

    private const CHUNK = 200;

    private PlatformConnectionRepository $connections;

    public function __construct(private readonly PDO $pdo)
    {
        $this->connections = new PlatformConnectionRepository($pdo);
    }

    public function findEventForPull(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, name, event_date, office_open_at, office_close_at, archived_at, deleted_at
            FROM events
            WHERE id = :event_id
            LIMIT 1
        ');
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findConnection(string $eventId): ?array
    {
        return $this->connections->find($eventId);
    }

    public function listEnabledConnections(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                e.id, e.name, e.event_date, e.office_open_at, e.office_close_at, e.archived_at, e.deleted_at,
                c.platform_event_id, c.last_pull_started_at, c.last_pull_status
            FROM event_platform_connections c
            INNER JOIN events e ON e.id = c.event_id
            WHERE c.is_enabled = 1 AND e.deleted_at IS NULL
            ORDER BY e.event_date ASC, e.id ASC
        ');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return array<string, string> event id => latest pull request (UTC); empty before migration 030 */
    public function loadPullRequests(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT event_id, pull_requested_at FROM event_platform_connections WHERE pull_requested_at IS NOT NULL');
        } catch (PDOException) {
            return [];
        }

        $requests = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $requests[(string)$row['event_id']] = (string)$row['pull_requested_at'];
        }

        return $requests;
    }

    public function tryLock(string $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $stmt->execute(['lock_name' => PlatformPullLock::name($eventId)]);

        return (int)$stmt->fetchColumn() === 1;
    }

    public function unlock(string $eventId): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $stmt->execute(['lock_name' => PlatformPullLock::name($eventId)]);
        $stmt->fetchAll();
    }

    public function markPullStarted(string $eventId, string $startedUtc, string $trigger): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE event_platform_connections
            SET last_pull_started_at = :started_at,
                last_pull_finished_at = NULL,
                last_pull_status = NULL,
                last_pull_trigger = :trigger_name,
                last_pull_summary_json = NULL
            WHERE event_id = :event_id
        ');
        $stmt->execute(['started_at' => $startedUtc, 'trigger_name' => $trigger, 'event_id' => $eventId]);
    }

    public function recordPullResult(string $eventId, string $finishedUtc, string $status, array $summary): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE event_platform_connections
            SET last_pull_finished_at = :finished_at,
                last_pull_status = :pull_status,
                last_pull_summary_json = :summary_json
            WHERE event_id = :event_id
        ');
        $stmt->execute([
            'finished_at' => $finishedUtc,
            'pull_status' => $status,
            'summary_json' => $summary === [] ? null : encodeJsonValue($summary),
            'event_id' => $eventId,
        ]);
    }

    public function loadFieldMappings(string $eventId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT source_column_name, alias, field_role, display_order, is_active
            FROM event_participant_field_mappings
            WHERE event_id = :event_id
            ORDER BY display_order ASC, id ASC
        ');
        $stmt->execute(['event_id' => $eventId]);

        return $stmt->fetchAll();
    }

    public function createFieldMappings(string $eventId, array $mappings): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO event_participant_field_mappings (
                event_id, source_column_name, alias, field_role, field_type,
                validation_rules_json, display_order, is_required, is_active
            ) VALUES (
                :event_id, :source_column_name, :alias, :field_role, :field_type,
                :validation_rules_json, :display_order, :is_required, :is_active
            )
        ');

        foreach ($mappings as $mapping) {
            $stmt->execute([
                'event_id' => $eventId,
                'source_column_name' => $mapping['source_column_name'],
                'alias' => $mapping['alias'],
                'field_role' => $mapping['field_role'],
                'field_type' => $mapping['field_type'] ?? 'text',
                'validation_rules_json' => ($mapping['validation_rules'] ?? []) !== [] ? encodeJsonValue($mapping['validation_rules']) : null,
                'display_order' => $mapping['display_order'],
                'is_required' => !empty($mapping['is_required']) ? 1 : 0,
                'is_active' => !empty($mapping['is_active']) ? 1 : 0,
            ]);
        }
    }

    public function saveFieldLayout(string $eventId, array $sourceByKey): void
    {
        $stmt = $this->pdo->prepare('UPDATE event_platform_connections SET field_layout_json = :layout_json WHERE event_id = :event_id');
        $stmt->execute(['layout_json' => encodeJsonValue($sourceByKey), 'event_id' => $eventId]);
    }

    public function loadEventParticipants(string $eventId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, participant_audit_key, platform_registration_id, display_name, first_name, last_name,
                   email, bib_number, payment_status, qr_code, identity_code, custom_fields_json,
                   platform_snapshot_json, platform_removed_at
            FROM participants
            WHERE event_id = :event_id
        ');
        $stmt->execute(['event_id' => $eventId]);

        $rows = [];
        while (($row = $stmt->fetch()) !== false) {
            $decoded = decodeJsonObject($row['custom_fields_json'] !== null ? (string)$row['custom_fields_json'] : null);
            $important = [];
            if (isset($decoded[self::IMPORTANT_FIELDS_META_KEY]) && is_array($decoded[self::IMPORTANT_FIELDS_META_KEY])) {
                foreach ($decoded[self::IMPORTANT_FIELDS_META_KEY] as $alias) {
                    if (is_string($alias) && trim($alias) !== '') {
                        $important[] = trim($alias);
                    }
                }
            }
            unset($decoded[self::IMPORTANT_FIELDS_META_KEY]);

            $custom = [];
            foreach ($decoded as $name => $value) {
                $custom[trim((string)$name)] = is_scalar($value) ? trim((string)$value) : '';
            }

            $snapshot = decodeJsonObject($row['platform_snapshot_json'] !== null ? (string)$row['platform_snapshot_json'] : null);

            $rows[] = [
                'id' => (int)$row['id'],
                'participant_audit_key' => (string)$row['participant_audit_key'],
                'platform_registration_id' => $row['platform_registration_id'] !== null ? (string)$row['platform_registration_id'] : null,
                'display_name' => (string)$row['display_name'],
                'first_name' => $row['first_name'] !== null ? (string)$row['first_name'] : null,
                'last_name' => $row['last_name'] !== null ? (string)$row['last_name'] : null,
                'email' => (string)$row['email'],
                'bib_number' => $row['bib_number'] !== null ? (string)$row['bib_number'] : null,
                'payment_status' => (string)$row['payment_status'],
                'qr_code' => $row['qr_code'] !== null ? (string)$row['qr_code'] : null,
                'identity_code' => $row['identity_code'] !== null ? (string)$row['identity_code'] : null,
                'custom_fields' => $custom,
                'important_field_aliases' => array_values(array_unique($important)),
                'platform_snapshot' => $snapshot === [] ? null : $snapshot,
                'platform_removed_at' => $row['platform_removed_at'] !== null ? (string)$row['platform_removed_at'] : null,
            ];
        }

        return $rows;
    }

    public function findQrCodeOwners(array $codes): array
    {
        $owners = [];
        foreach (array_chunk(array_values(array_unique($codes)), self::CHUNK) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $code) {
                $placeholders[] = ':qr_' . $index;
                $params['qr_' . $index] = $code;
            }

            $stmt = $this->pdo->prepare('SELECT id, event_id, qr_code FROM participants WHERE qr_code IN (' . implode(', ', $placeholders) . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $owners[(string)$row['qr_code']] = [
                    'id' => (int)$row['id'],
                    'event_id' => $row['event_id'] !== null ? (string)$row['event_id'] : null,
                ];
            }
        }

        return $owners;
    }

    public function begin(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function insertParticipant(string $eventId, array $create): ?int
    {
        $qrCode = $create['qr_code'] ?? $this->generateUniqueStartCode();
        $stmt = $this->pdo->prepare('
            INSERT INTO participants (
                event_id, participant_audit_key, first_name, last_name, display_name, email, bib_number,
                qr_code, identity_code, platform_registration_id, platform_snapshot_json, custom_fields_json,
                status, email_status, payment_status
            ) VALUES (
                :event_id, :participant_audit_key, :first_name, :last_name, :display_name, :email, :bib_number,
                :qr_code, :identity_code, :platform_registration_id, :platform_snapshot_json, :custom_fields_json,
                :status, :email_status, :payment_status
            )
        ');

        try {
            $stmt->execute([
                'event_id' => $eventId,
                'participant_audit_key' => 'pa-' . bin2hex(random_bytes(12)),
                'first_name' => $create['first_name'],
                'last_name' => $create['last_name'],
                'display_name' => $create['display_name'],
                'email' => $create['email'],
                'bib_number' => $create['bib_number'],
                'qr_code' => $qrCode,
                'identity_code' => $create['identity_code'],
                'platform_registration_id' => $create['registration_id'],
                'platform_snapshot_json' => encodeJsonValue($create['platform_snapshot']),
                'custom_fields_json' => $this->encodeCustomFields($create['custom_fields'], $create['important_field_aliases']),
                'status' => 'not_checked_in',
                'email_status' => 'not_sent',
                'payment_status' => $create['payment_status'],
            ]);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }

        return (int)$this->pdo->lastInsertId();
    }

    public function updateParticipant(int $participantId, array $changes): bool
    {
        $values = [];
        foreach ($changes as $name => $value) {
            switch ($name) {
                case 'custom_fields':
                    $values['custom_fields_json'] = $this->encodeCustomFields($value, $changes['important_field_aliases'] ?? []);
                    break;
                case 'important_field_aliases':
                    break;
                case 'platform_snapshot':
                    $values['platform_snapshot_json'] = encodeJsonValue($value);
                    break;
                default:
                    $values[$name] = $value;
            }
        }

        $sets = [];
        $params = ['participant_id' => $participantId];
        foreach (self::UPDATABLE_COLUMNS as $column) {
            if (array_key_exists($column, $values)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $values[$column];
            }
        }
        if ($sets === []) {
            return true;
        }

        $stmt = $this->pdo->prepare('UPDATE participants SET ' . implode(', ', $sets) . ' WHERE id = :participant_id');
        try {
            $stmt->execute($params);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function markRemoved(array $participantIds, string $removedUtc): void
    {
        foreach (array_chunk(array_values($participantIds), self::CHUNK) as $chunk) {
            $placeholders = [];
            $params = ['removed_at' => $removedUtc];
            foreach ($chunk as $index => $id) {
                $placeholders[] = ':id_' . $index;
                $params['id_' . $index] = $id;
            }

            $stmt = $this->pdo->prepare('UPDATE participants SET platform_removed_at = :removed_at WHERE id IN (' . implode(', ', $placeholders) . ') AND platform_removed_at IS NULL');
            $stmt->execute($params);
        }
    }

    public function addChangeLog(string $eventId, array $entry): void
    {
        $participantId = isset($entry['participant_id']) ? (int)$entry['participant_id'] : null;
        $auditKey = (string)($entry['participant_audit_key'] ?? '');
        if ($auditKey === '' && $participantId !== null) {
            $lookup = $this->pdo->prepare('SELECT participant_audit_key FROM participants WHERE id = :participant_id');
            $lookup->execute(['participant_id' => $participantId]);
            $auditKey = (string)$lookup->fetchColumn();
        }

        $userId = $entry['user_id'] ?? null;
        $userName = $entry['user_name'] ?? null;
        $changeType = (string)$entry['change_type'];
        $changeSource = in_array($entry['change_source'] ?? null, ['platform_sync', 'platform_webhook'], true) ? (string)$entry['change_source'] : 'platform_sync';
        $changedFields = array_values($entry['changed_fields'] ?? []);
        $before = $entry['before_state'] ?? [];
        $after = $entry['after_state'] ?? [];

        $auditLogId = addAuditLog($this->pdo, [
            'category' => 'participants',
            'action_code' => 'participant.change.' . $changeType,
            'action' => 'Zmiana uczestnika: ' . $changeType,
            'actor_user_id' => $userId,
            'actor_name' => $userName,
            'target_type' => 'participant',
            'target_id' => $participantId !== null ? (string)$participantId : $auditKey,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'metadata' => [
                'change_source' => $changeSource,
                'changed_fields' => $changedFields,
                'participant_audit_key' => $auditKey,
            ],
        ]);

        $stmt = $this->pdo->prepare('
            INSERT INTO event_participant_change_logs (
                id, audit_log_id, event_id, participant_audit_key, baseline_record_id, participant_id,
                change_type, change_source, changed_fields_json, before_state_json, after_state_json,
                user_id, user_name_snapshot
            ) VALUES (
                :id, :audit_log_id, :event_id, :participant_audit_key, NULL, :participant_id,
                :change_type, :change_source, :changed_fields_json, :before_state_json, :after_state_json,
                :user_id, :user_name_snapshot
            )
        ');
        $stmt->execute([
            'id' => 'pcl-' . bin2hex(random_bytes(8)),
            'audit_log_id' => $auditLogId,
            'event_id' => $eventId,
            'participant_audit_key' => $auditKey,
            'participant_id' => $participantId,
            'change_type' => $changeType,
            'change_source' => $changeSource,
            'changed_fields_json' => $changedFields !== [] ? encodeJsonValue($changedFields) : null,
            'before_state_json' => $before !== [] ? encodeJsonValue($before) : null,
            'after_state_json' => $after !== [] ? encodeJsonValue($after) : null,
            'user_id' => $userId,
            'user_name_snapshot' => $userName,
        ]);
    }

    public function addActivityLog(string $eventId, string $action, ?string $userId, ?string $userName, array $metadata): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO activity_logs (
                id, action, event_id, participant_id, participant_name_snapshot, user_id, user_name_snapshot
            ) VALUES (
                :id, :action, :event_id, NULL, NULL, :user_id, :user_name_snapshot
            )
        ');
        $stmt->execute([
            'id' => 'log-' . bin2hex(random_bytes(8)),
            'action' => $action,
            'event_id' => $eventId,
            'user_id' => $userId,
            'user_name_snapshot' => $userName,
        ]);

        addAuditLog($this->pdo, [
            'action' => $action,
            'actor_user_id' => $userId,
            'actor_name' => $userName,
            'event_id' => $eventId,
            'target_type' => 'event',
            'target_id' => $eventId,
            'metadata' => array_merge(['legacy_activity_log' => true], $metadata),
        ]);
    }

    private function generateUniqueStartCode(): string
    {
        $check = $this->pdo->prepare('SELECT id FROM participants WHERE qr_code = :qr_code LIMIT 1');

        do {
            $token = QrCodeService::generateToken();
            $check->execute(['qr_code' => $token]);
            $collision = $check->fetch();
        } while ($collision !== false);

        return $token;
    }

    /** @param array<string, string> $customFields @param list<string> $importantAliases */
    private function encodeCustomFields(array $customFields, array $importantAliases): string
    {
        $fields = [];
        foreach ($customFields as $name => $value) {
            $trimmedName = trim((string)$name);
            if ($trimmedName !== '') {
                $fields[$trimmedName] = trim((string)$value);
            }
        }
        if ($importantAliases !== []) {
            $fields[self::IMPORTANT_FIELDS_META_KEY] = array_values(array_unique($importantAliases));
        }

        return (string)encodeJsonValue($fields === [] ? new stdClass() : $fields);
    }
}
