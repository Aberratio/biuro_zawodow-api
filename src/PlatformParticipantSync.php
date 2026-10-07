<?php

declare(strict_types=1);

/**
 * Plans the writes of one pull (pure, no database). Three-way merge without per-field timestamps:
 * a value the platform changed since the previous pull replaces the local one; a value the platform did
 * not change stays as it is in BZ (it may be a local edit). Codes always follow the platform.
 */
final class PlatformParticipantSync
{
    private const NAME_FIELDS = ['first_name', 'last_name'];

    /**
     * @param list<array<string, mixed>> $existing participants of this event
     * @param list<array<string, mixed>> $records mapped platform records
     * @param array<string, string> $aliasByKey layout key => custom field alias
     * @param list<string> $importantAliases aliases new participants get as important fields
     * @param array<string, array{id: int|string, event_id: ?string}> $qrOwners incoming start code => current owner (any event)
     * @return array{
     *     creates: list<array<string, mixed>>,
     *     updates: list<array<string, mixed>>,
     *     unchanged: int,
     *     skipped: list<array{registration_id: string, reason: string}>,
     *     removals: list<int>,
     *     restored: int,
     *     possible_duplicates: list<string>,
     *     warnings: array<string, int>,
     *     had_platform_rows: bool
     * }
     */
    public static function plan(array $existing, array $records, array $aliasByKey, array $importantAliases, array $qrOwners, string $nowUtc): array
    {
        $byRegistration = [];
        $nonPlatformKeys = [];
        foreach ($existing as $row) {
            $registrationId = (string)($row['platform_registration_id'] ?? '');
            if ($registrationId !== '') {
                $byRegistration[$registrationId] = $row;
            } else {
                $nonPlatformKeys[mb_strtolower((string)$row['email']) . '|' . mb_strtolower((string)$row['display_name'])] = true;
            }
        }

        $creates = [];
        $updates = [];
        $skipped = [];
        $duplicates = [];
        $warnings = [];
        $unchanged = 0;
        $restored = 0;
        $seen = [];
        $claimed = [];

        foreach ($records as $record) {
            $registrationId = (string)$record['platform_registration_id'];
            $seen[$registrationId] = true;
            $row = $byRegistration[$registrationId] ?? null;

            $qrCode = $record['qr_code'];
            if ($qrCode !== null && $qrCode !== ($row['qr_code'] ?? null)) {
                $owner = $qrOwners[$qrCode] ?? null;
                if (($owner !== null && (int)$owner['id'] !== (int)($row['id'] ?? 0)) || isset($claimed[$qrCode])) {
                    $skipped[] = ['registration_id' => $registrationId, 'reason' => 'qr_collision'];
                    continue;
                }
            }
            if ($qrCode !== null) {
                $claimed[$qrCode] = true;
            }

            foreach ($record['warnings'] as $warning) {
                if ($warning === 'start_code_generated' && $row !== null) {
                    continue;
                }
                $warnings[$warning] = ($warnings[$warning] ?? 0) + 1;
            }

            if ($row === null) {
                $creates[] = self::buildCreate($record, $aliasByKey, $importantAliases);
                $key = mb_strtolower((string)$record['email']) . '|' . mb_strtolower((string)$record['display_name']);
                if ($record['email'] !== '' && isset($nonPlatformKeys[$key])) {
                    $duplicates[] = $registrationId;
                }
                continue;
            }

            $update = self::buildUpdate($row, $record, $aliasByKey);
            if ($update === null) {
                $unchanged++;
                continue;
            }

            if (in_array('platform_removed_at', $update['changed_fields'], true)) {
                $restored++;
            }
            if ($update['changed_fields'] === []) {
                $unchanged++;
            }
            $updates[] = $update;
        }

        $removals = [];
        if ($records === [] && $byRegistration !== []) {
            $warnings['empty_list_ignored'] = 1;
        } else {
            foreach ($byRegistration as $registrationId => $row) {
                if (!isset($seen[$registrationId]) && ($row['platform_removed_at'] ?? null) === null) {
                    $removals[] = (int)$row['id'];
                }
            }
        }

        return [
            'creates' => $creates,
            'updates' => $updates,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'removals' => $removals,
            'restored' => $restored,
            'possible_duplicates' => $duplicates,
            'warnings' => $warnings,
            'had_platform_rows' => $byRegistration !== [],
        ];
    }

    /** @return array<string, mixed> */
    private static function buildCreate(array $record, array $aliasByKey, array $importantAliases): array
    {
        $customFields = [];
        foreach ($record['fields'] as $key => $value) {
            if (isset($aliasByKey[$key])) {
                $customFields[$aliasByKey[$key]] = (string)$value;
            }
        }

        return [
            'registration_id' => (string)$record['platform_registration_id'],
            'display_name' => $record['display_name'],
            'first_name' => $record['first_name'],
            'last_name' => $record['last_name'],
            'email' => $record['email'],
            'bib_number' => $record['bib_number'],
            'payment_status' => $record['payment_status'],
            'qr_code' => $record['qr_code'],
            'identity_code' => $record['identity_code'],
            'custom_fields' => $customFields,
            'important_field_aliases' => array_values(array_unique($importantAliases)),
            'platform_snapshot' => self::snapshot($record),
            'warnings' => $record['warnings'],
        ];
    }

    /** @return ?array<string, mixed> null when nothing at all needs to be written */
    private static function buildUpdate(array $row, array $record, array $aliasByKey): ?array
    {
        $snapshot = is_array($row['platform_snapshot'] ?? null) ? $row['platform_snapshot'] : null;
        $incomingSnapshot = self::snapshot($record);
        $changes = [];
        $changedFields = [];

        $currentCustom = is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : [];
        $newCustom = $currentCustom;
        $changedAliases = [];

        $wins = static function (string $group, mixed $incoming) use ($snapshot): bool {
            if ($snapshot === null || !array_key_exists($group, $snapshot)) {
                return true;
            }

            return json_encode($snapshot[$group]) !== json_encode($incoming);
        };

        // name group: one decision for the display name, both name columns and both name custom fields
        if ($wins('name', $incomingSnapshot['name'])) {
            foreach (['display_name', 'first_name', 'last_name'] as $column) {
                if (($row[$column] ?? null) !== $record[$column]) {
                    $changes[$column] = $record[$column];
                    $changedFields[] = $column;
                }
            }
            foreach (self::NAME_FIELDS as $nameKey) {
                self::mergeCustom($aliasByKey, $nameKey, (string)$record['fields'][$nameKey], $newCustom, $changedAliases);
            }
        }

        foreach (['email', 'bib_number', 'payment_status'] as $column) {
            if ($wins($column, $incomingSnapshot[$column]) && self::normalize($row[$column] ?? null) !== self::normalize($record[$column])) {
                $changes[$column] = $record[$column];
                $changedFields[] = $column;
            }
        }

        foreach ($record['fields'] as $key => $value) {
            if (in_array($key, self::NAME_FIELDS, true)) {
                continue;
            }
            $stored = $snapshot['fields'] ?? null;
            $platformChanged = !is_array($stored) || !array_key_exists($key, $stored) || (string)$stored[$key] !== (string)$value;
            if ($platformChanged) {
                self::mergeCustom($aliasByKey, $key, (string)$value, $newCustom, $changedAliases);
            }
        }

        if ($changedAliases !== []) {
            $changes['custom_fields'] = $newCustom;
            $changes['important_field_aliases'] = array_values($row['important_field_aliases'] ?? []);
            foreach (array_keys($aliasByKey) as $key) {
                $alias = $aliasByKey[$key];
                if (in_array($alias, $changedAliases, true) && !in_array($alias, $changedFields, true)) {
                    $changedFields[] = $alias;
                }
            }
        }

        if ($record['qr_code'] !== null && $record['qr_code'] !== ($row['qr_code'] ?? null)) {
            $changes['qr_code'] = $record['qr_code'];
            $changedFields[] = 'qr_code';
        }
        if ($record['identity_code'] !== ($row['identity_code'] ?? null)) {
            $changes['identity_code'] = $record['identity_code'];
            $changedFields[] = 'identity_code';
        }
        if (($row['platform_removed_at'] ?? null) !== null) {
            $changes['platform_removed_at'] = null;
            $changedFields[] = 'platform_removed_at';
        }

        if (json_encode($snapshot) !== json_encode($incomingSnapshot)) {
            $changes['platform_snapshot'] = $incomingSnapshot;
        }

        if ($changes === []) {
            return null;
        }

        $after = $row;
        foreach ($changes as $column => $value) {
            $after[$column] = $value;
        }

        return [
            'id' => (int)$row['id'],
            'audit_key' => (string)($row['participant_audit_key'] ?? ''),
            'registration_id' => (string)$record['platform_registration_id'],
            'changes' => $changes,
            'changed_fields' => $changedFields,
            'before' => self::changeState($row),
            'after' => self::changeState($after),
        ];
    }

    /**
     * @param array<string, string> $aliasByKey
     * @param array<string, string> $custom
     * @param list<string> $changedAliases
     */
    private static function mergeCustom(array $aliasByKey, string $key, string $value, array &$custom, array &$changedAliases): void
    {
        if (!isset($aliasByKey[$key])) {
            return;
        }

        $alias = $aliasByKey[$key];
        if (trim((string)($custom[$alias] ?? '')) !== $value) {
            $custom[$alias] = $value;
            $changedAliases[] = $alias;
        }
    }

    /** @return array<string, mixed> */
    private static function snapshot(array $record): array
    {
        $fields = [];
        foreach ($record['fields'] as $key => $value) {
            if (!in_array($key, self::NAME_FIELDS, true)) {
                $fields[$key] = (string)$value;
            }
        }

        return [
            'v' => 1,
            'name' => [
                'display_name' => $record['display_name'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
            ],
            'email' => $record['email'],
            'bib_number' => $record['bib_number'],
            'payment_status' => $record['payment_status'],
            'fields' => $fields,
        ];
    }

    /** Same shape as the change-log states of the participant API; codes are never part of it. */
    public static function changeState(array $row): array
    {
        $custom = is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : [];
        ksort($custom);

        return [
            'display_name' => trim((string)($row['display_name'] ?? '')),
            'email' => normalizeEmailAddress((string)($row['email'] ?? '')),
            'bib_number' => self::normalize($row['bib_number'] ?? null),
            'payment_status' => in_array((string)($row['payment_status'] ?? ''), ['unknown', 'paid', 'unpaid'], true)
                ? (string)$row['payment_status']
                : 'unknown',
            'custom_fields' => array_map(static fn (mixed $value): string => trim((string)$value), $custom),
            'important_field_aliases' => array_values($row['important_field_aliases'] ?? []),
        ];
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);

        return $text === '' ? null : $text;
    }
}
