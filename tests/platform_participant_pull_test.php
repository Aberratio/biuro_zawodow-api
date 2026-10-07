<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function pullRequire(string $class): void
{
    assertTrue(class_exists($class) || interface_exists($class), "Missing class {$class}");
}

function pullRequireSodium(): void
{
    assertTrue(function_exists('sodium_crypto_secretbox'), 'libsodium missing: run with php -d extension=sodium');
}

function pullKey(): string
{
    return hash('sha256', 'pull-test-key', true);
}

function pullConfig(array $overrides = []): PlatformIntegrationConfig
{
    $values = array_merge([
        'BZ_PLATFORM_API_URL' => 'http://zc.test/api/v1',
        'BZ_PLATFORM_APP_KEY' => 'bz-app-key-for-tests-0123456789',
        'BZ_PLATFORM_TOKEN_KEY' => base64_encode(pullKey()),
    ], $overrides);

    return PlatformIntegrationConfig::fromEnvironment(
        static fn (string $name): string|false => array_key_exists($name, $values) ? $values[$name] : false,
        true,
        true
    );
}

function pullToken(): string
{
    return 'tok_PULLTESTTOKEN0123456789ABCDEFGHIJ';
}

function pullCode(string $prefix, string $seed): string
{
    return $prefix . '_' . substr(hash('sha256', $seed), 0, 48);
}

function pullEntry(int $i, array $overrides = []): array
{
    return array_merge([
        'registrationId' => sprintf('reg-%04d', $i),
        'distanceName' => $i % 2 === 0 ? '5 km' : '10 km',
        'category' => 'Open',
        'bibNumber' => (string)(100 + $i),
        'qrCode' => pullCode('pqr', "p{$i}"),
        'identityCode' => pullCode('zqr', "z{$i}"),
        'paid' => $i % 3 !== 0,
        'merchandise' => [],
        'formFields' => [
            ['fieldKey' => 'first_name', 'label' => 'Imię', 'value' => 'Jan' . $i],
            ['fieldKey' => 'last_name', 'label' => 'Nazwisko', 'value' => 'Testowy' . $i],
            ['fieldKey' => 'contact_email', 'label' => 'E-mail kontaktowy', 'value' => "Jan{$i}@Example.com"],
            ['fieldKey' => 'custom_alarm', 'label' => 'Numer alarmowy', 'value' => '+48 600 000 ' . sprintf('%03d', $i)],
        ],
    ], $overrides);
}

function pullEntries(int $count): array
{
    $entries = [];
    for ($i = 1; $i <= $count; $i++) {
        $entries[] = pullEntry($i);
    }

    return $entries;
}

function pullBody(array $entries): string
{
    return (string)json_encode(['success' => true, 'data' => $entries], JSON_UNESCAPED_UNICODE);
}

function pullAliases(): array
{
    return [
        'first_name' => 'Imię',
        'last_name' => 'Nazwisko',
        'distance' => 'Dystans',
        'category' => 'Kategoria',
        'merchandise' => 'Pakiet startowy',
        'field:custom_alarm' => 'Numer alarmowy',
    ];
}

function pullMap(array $entries): array
{
    return PlatformParticipantMapper::mapAll($entries);
}

function pullPlan(array $existing, array $entries, array $owners = []): array
{
    $mapped = pullMap($entries);

    return PlatformParticipantSync::plan($existing, $mapped['records'], pullAliases(), ['Dystans'], $owners, '2026-10-07 10:00:00');
}

/** Turns the create entries of a plan into stored participant rows (what the database would hold). */
function pullStore(array $plan, int $firstId = 1): array
{
    $rows = [];
    foreach ($plan['creates'] as $i => $create) {
        $id = $firstId + $i;
        $rows[] = [
            'id' => $id,
            'participant_audit_key' => 'pa-' . $id,
            'platform_registration_id' => $create['registration_id'],
            'display_name' => $create['display_name'],
            'first_name' => $create['first_name'],
            'last_name' => $create['last_name'],
            'email' => $create['email'],
            'bib_number' => $create['bib_number'],
            'payment_status' => $create['payment_status'],
            'qr_code' => $create['qr_code'],
            'identity_code' => $create['identity_code'],
            'custom_fields' => $create['custom_fields'],
            'important_field_aliases' => $create['important_field_aliases'],
            'platform_snapshot' => $create['platform_snapshot'],
            'platform_removed_at' => null,
        ];
    }

    return $rows;
}

function pullApplyUpdates(array $rows, array $plan): array
{
    foreach ($plan['updates'] as $update) {
        foreach ($rows as $index => $row) {
            if ($row['id'] === $update['id']) {
                $rows[$index] = array_merge($row, $update['changes']);
            }
        }
    }
    foreach ($plan['removals'] as $removedId) {
        foreach ($rows as $index => $row) {
            if ($row['id'] === $removedId) {
                $rows[$index]['platform_removed_at'] = '2026-10-07 10:00:00';
            }
        }
    }

    return $rows;
}

function pullEvent(array $overrides = []): array
{
    return array_merge([
        'id' => 'evt-1',
        'name' => 'Bieg testowy',
        'event_date' => '2026-10-10',
        'office_open_at' => '2026-10-10 08:00:00',
        'office_close_at' => '2026-10-10 16:00:00',
        'archived_at' => null,
        'deleted_at' => null,
    ], $overrides);
}

if (interface_exists('PlatformParticipantStore')) {
    final class InMemoryPlatformParticipantStore implements PlatformParticipantStore
    {
        public array $events = [];
        public array $connections = [];
        public array $participants = [];
        public array $mappings = [];
        public array $changeLogs = [];
        public array $activity = [];
        public array $results = [];
        public array $layouts = [];
        public bool $lockHeld = false;
        public int $lockCalls = 0;
        public int $unlockCalls = 0;
        public int $writeCount = 0;
        public int $removeCalls = 0;
        public int $insertCalls = 0;
        public ?int $failOnInsertNumber = null;
        public ?string $throwOn = null;
        private int $nextId = 1;
        private ?array $txSnapshot = null;

        public function __construct()
        {
            $this->events['evt-1'] = pullEvent();
            $this->connections['evt-1'] = [
                'event_id' => 'evt-1',
                'platform_event_id' => 'p1',
                'is_enabled' => 1,
                'organization_token_ciphertext' => pullConfig()->cipher()->encrypt(pullToken(), 'evt-1'),
                'field_layout_json' => null,
                'last_pull_started_at' => null,
                'last_pull_status' => null,
            ];
        }

        private function maybeThrow(string $method): void
        {
            if ($this->throwOn === $method) {
                throw new RuntimeException('boom in ' . $method);
            }
        }

        public function findEventForPull(string $eventId): ?array
        {
            return $this->events[$eventId] ?? null;
        }

        public function findConnection(string $eventId): ?array
        {
            return $this->connections[$eventId] ?? null;
        }

        public function listEnabledConnections(): array
        {
            $rows = [];
            foreach ($this->connections as $eventId => $connection) {
                if ((int)$connection['is_enabled'] === 1 && isset($this->events[$eventId])) {
                    $rows[] = array_merge($this->events[$eventId], $connection);
                }
            }

            return $rows;
        }

        public function tryLock(string $eventId): bool
        {
            $this->lockCalls++;
            if ($this->lockHeld) {
                return false;
            }
            $this->lockHeld = true;

            return true;
        }

        public function unlock(string $eventId): void
        {
            $this->unlockCalls++;
            $this->lockHeld = false;
        }

        public function markPullStarted(string $eventId, string $startedUtc, string $trigger): void
        {
            $this->connections[$eventId]['last_pull_started_at'] = $startedUtc;
            $this->connections[$eventId]['last_pull_trigger'] = $trigger;
        }

        public function recordPullResult(string $eventId, string $finishedUtc, string $status, array $summary): void
        {
            $this->connections[$eventId]['last_pull_status'] = $status;
            $this->results[] = ['event_id' => $eventId, 'status' => $status, 'summary' => $summary];
        }

        public function loadFieldMappings(string $eventId): array
        {
            $this->maybeThrow('loadFieldMappings');

            return $this->mappings[$eventId] ?? [];
        }

        public function createFieldMappings(string $eventId, array $mappings): void
        {
            $this->writeCount++;
            foreach ($mappings as $mapping) {
                $this->mappings[$eventId][] = $mapping;
            }
        }

        public function saveFieldLayout(string $eventId, array $sourceByKey): void
        {
            $this->writeCount++;
            $this->layouts[$eventId] = $sourceByKey;
            $this->connections[$eventId]['field_layout_json'] = json_encode($sourceByKey);
        }

        public function loadEventParticipants(string $eventId): array
        {
            return array_values(array_filter(
                $this->participants,
                static fn (array $row): bool => $row['event_id'] === $eventId
            ));
        }

        public function findQrCodeOwners(array $codes): array
        {
            $owners = [];
            foreach ($this->participants as $row) {
                if ($row['qr_code'] !== null && in_array($row['qr_code'], $codes, true)) {
                    $owners[$row['qr_code']] = ['id' => $row['id'], 'event_id' => $row['event_id']];
                }
            }

            return $owners;
        }

        public function begin(): void
        {
            $this->txSnapshot = $this->participants;
        }

        public function commit(): void
        {
            $this->txSnapshot = null;
        }

        public function rollBack(): void
        {
            if ($this->txSnapshot !== null) {
                $this->participants = $this->txSnapshot;
            }
            $this->txSnapshot = null;
        }

        private function qrTaken(?string $code, ?int $exceptId): bool
        {
            if ($code === null) {
                return false;
            }
            foreach ($this->participants as $row) {
                if ($row['qr_code'] === $code && $row['id'] !== $exceptId) {
                    return true;
                }
            }

            return false;
        }

        public function insertParticipant(string $eventId, array $create): ?int
        {
            $this->insertCalls++;
            if ($this->failOnInsertNumber !== null && $this->insertCalls === $this->failOnInsertNumber) {
                throw new RuntimeException('simulated database failure');
            }
            if ($this->qrTaken($create['qr_code'], null)) {
                return null;
            }
            $this->writeCount++;
            $id = $this->nextId++;
            $this->participants[$id] = [
                'id' => $id,
                'event_id' => $eventId,
                'participant_audit_key' => 'pa-' . $id,
                'platform_registration_id' => $create['registration_id'],
                'display_name' => $create['display_name'],
                'first_name' => $create['first_name'],
                'last_name' => $create['last_name'],
                'email' => $create['email'],
                'bib_number' => $create['bib_number'],
                'payment_status' => $create['payment_status'],
                'qr_code' => $create['qr_code'] ?? pullCode('pqr', 'generated-' . $id),
                'identity_code' => $create['identity_code'],
                'custom_fields' => $create['custom_fields'],
                'important_field_aliases' => $create['important_field_aliases'],
                'platform_snapshot' => $create['platform_snapshot'],
                'platform_removed_at' => null,
                'status' => 'not_checked_in',
                'checked_in_at' => null,
                'email_status' => 'not_sent',
            ];

            return $id;
        }

        public function updateParticipant(int $participantId, array $changes): bool
        {
            if (isset($changes['qr_code']) && $this->qrTaken($changes['qr_code'], $participantId)) {
                return false;
            }
            $this->writeCount++;
            $this->participants[$participantId] = array_merge($this->participants[$participantId], $changes);

            return true;
        }

        public function markRemoved(array $participantIds, string $removedUtc): void
        {
            $this->removeCalls++;
            $this->writeCount++;
            foreach ($participantIds as $id) {
                $this->participants[$id]['platform_removed_at'] = $removedUtc;
            }
        }

        public function addChangeLog(string $eventId, array $entry): void
        {
            $this->writeCount++;
            $this->changeLogs[] = array_merge(['event_id' => $eventId], $entry);
        }

        public function addActivityLog(string $eventId, string $action, ?string $userId, ?string $userName, array $metadata): void
        {
            $this->activity[] = ['event_id' => $eventId, 'action' => $action, 'user_id' => $userId, 'user_name' => $userName, 'metadata' => $metadata];
        }

        public function seedParticipant(array $overrides = []): int
        {
            $id = $this->nextId++;
            $this->participants[$id] = array_merge([
                'id' => $id,
                'event_id' => 'evt-1',
                'participant_audit_key' => 'pa-' . $id,
                'platform_registration_id' => null,
                'display_name' => 'Plik Uczestnik ' . $id,
                'first_name' => 'Plik',
                'last_name' => 'Uczestnik',
                'email' => "plik{$id}@file.test",
                'bib_number' => null,
                'payment_status' => 'unknown',
                'qr_code' => pullCode('pqr', "seed{$id}"),
                'identity_code' => null,
                'custom_fields' => [],
                'important_field_aliases' => [],
                'platform_snapshot' => null,
                'platform_removed_at' => null,
                'status' => 'not_checked_in',
                'checked_in_at' => null,
                'email_status' => 'not_sent',
            ], $overrides);

            return $id;
        }
    }
}

/**
 * @param array{calls: int, requests: list<array>} $log
 */
function pullService(InMemoryPlatformParticipantStore $store, callable $transport, array &$log, array &$sleeps = [], array &$logged = []): PlatformParticipantPullService
{
    $wrapped = static function (string $method, string $url, array $headers, int $timeout) use ($transport, &$log): array {
        $log['calls']++;
        $log['requests'][] = ['url' => $url, 'timeout' => $timeout];

        return $transport($method, $url, $headers, $timeout);
    };
    $config = pullConfig();

    return new PlatformParticipantPullService(
        $store,
        $config,
        new PlatformApiClient($config, $wrapped),
        static function (string $level, string $code, string $message, array $context) use (&$logged): void {
            $logged[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        },
        static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        }
    );
}

function pullOkTransport(array $entries): callable
{
    return static fn (): array => ['status' => 200, 'body' => pullBody($entries)];
}

function pullFreshLog(): array
{
    return ['calls' => 0, 'requests' => []];
}

test('payload parser accepts the platform envelope and rejects everything else', function (): void {
    pullRequire('PlatformParticipantPayload');

    $ok = PlatformParticipantPayload::parse(pullBody([pullEntry(1)]));
    assertTrue($ok['ok']);
    assertSame('ok', $ok['status']);
    assertSame(1, count($ok['entries']));

    $empty = PlatformParticipantPayload::parse('{"success":true,"data":[]}');
    assertTrue($empty['ok']);
    assertSame([], $empty['entries']);

    foreach (['{"data":[]}', '{"success":false,"data":[]}', '{"success":true,"data":{"a":1}}', '{"success":true}', 'not json', '', '[]'] as $body) {
        $result = PlatformParticipantPayload::parse($body);
        assertFalse($result['ok'], 'rejected: ' . $body);
        assertSame('invalid_response', $result['status']);
        assertSame([], $result['entries']);
    }

    $tooLarge = PlatformParticipantPayload::parse(str_repeat(' ', 16 * 1024 * 1024 + 1));
    assertFalse($tooLarge['ok']);
    assertSame('response_too_large', $tooLarge['status']);
});

test('mapper normalises a platform row into a participant record', function (): void {
    pullRequire('PlatformParticipantMapper');
    $entry = pullEntry(7, [
        'bibNumber' => ' 107 ',
        'paid' => true,
        'merchandise' => [
            ['item' => 'Koszulka techniczna', 'size' => 'M', 'quantity' => 1],
            ['item' => 'Czapka', 'size' => null, 'quantity' => 2],
            ['item' => '', 'size' => 'L', 'quantity' => 1],
        ],
    ]);
    $entry['formFields'][2]['value'] = ' Jan.Kowalski@Example.COM ';
    $entry['formFields'][] = ['fieldKey' => 'shirt', 'label' => 'Rozmiar koszulki', 'value' => ['M', 'L']];
    $mapped = pullMap([$entry]);

    assertSame([], $mapped['invalid']);
    $record = $mapped['records'][0];
    assertSame('reg-0007', $record['platform_registration_id']);
    assertSame('Jan7', $record['first_name']);
    assertSame('Testowy7', $record['last_name']);
    assertSame('Jan7 Testowy7', $record['display_name']);
    assertSame('jan.kowalski@example.com', $record['email']);
    assertSame('107', $record['bib_number']);
    assertSame('paid', $record['payment_status']);
    assertSame('Koszulka techniczna (M) ×1; Czapka ×2', $record['fields']['merchandise']);
    assertSame('M, L', $record['fields']['field:shirt']);
    assertSame('10 km', $record['fields']['distance']);
    assertSame('Open', $record['fields']['category']);

    $unpaid = pullMap([pullEntry(3)])['records'][0];
    assertSame('unpaid', $unpaid['payment_status']);

    $keys = array_column($mapped['field_definitions'], 'key');
    assertSame(['first_name', 'last_name', 'contact_email', 'bib_number', 'paid', 'distance', 'category', 'merchandise', 'field:custom_alarm', 'field:shirt'], $keys);
    $byKey = [];
    foreach ($mapped['field_definitions'] as $definition) {
        $byKey[$definition['key']] = $definition;
    }
    assertSame('display_name_part', $byKey['first_name']['role']);
    assertSame('email', $byKey['contact_email']['role']);
    assertSame('bib_number', $byKey['bib_number']['role']);
    assertSame('Numer startowy', $byKey['bib_number']['label']);
    assertSame('payment_status', $byKey['paid']['role']);
    assertSame('important_custom', $byKey['distance']['role']);
    assertSame('Rozmiar koszulki', $byKey['field:shirt']['label']);
    assertSame('custom', $byKey['field:shirt']['role']);
});

test('mapper keeps platform codes only in the expected format', function (): void {
    pullRequire('PlatformParticipantMapper');
    $good = pullMap([pullEntry(1)])['records'][0];
    assertSame(pullCode('pqr', 'p1'), $good['qr_code']);
    assertSame(pullCode('zqr', 'z1'), $good['identity_code']);
    assertSame([], $good['warnings']);

    $bad = pullMap([pullEntry(2, [
        'qrCode' => strtoupper(pullCode('pqr', 'p2')),
        'identityCode' => pullCode('pqr', 'z2'),
    ])])['records'][0];
    assertNullValue($bad['qr_code']);
    assertNullValue($bad['identity_code']);
    assertTrue(in_array('start_code_generated', $bad['warnings'], true));

    $short = pullMap([pullEntry(3, ['qrCode' => 'pqr_abc', 'identityCode' => null])])['records'][0];
    assertNullValue($short['qr_code']);
    assertNullValue($short['identity_code']);

    $missing = pullMap([pullEntry(4, ['qrCode' => null])])['records'][0];
    assertNullValue($missing['qr_code']);
});

test('mapper rejects invalid and duplicate registration ids', function (): void {
    pullRequire('PlatformParticipantMapper');
    $mapped = pullMap([
        pullEntry(1),
        pullEntry(2, ['registrationId' => 'bad id!']),
        pullEntry(3, ['registrationId' => '']),
        pullEntry(4, ['registrationId' => str_repeat('a', 65)]),
        pullEntry(5, ['registrationId' => 123]),
        'not-an-array',
        pullEntry(1, ['bibNumber' => '999']),
        pullEntry(6),
    ]);

    assertSame(['reg-0001', 'reg-0006'], array_column($mapped['records'], 'platform_registration_id'));
    assertSame(6, count($mapped['invalid']));
    $reasons = array_count_values(array_column($mapped['invalid'], 'reason'));
    assertSame(5, $reasons['invalid_record']);
    assertSame(1, $reasons['duplicate_record']);
    assertSame('101', $mapped['records'][0]['bib_number']);
});

test('mapper falls back to e-mail and registration id for the display name and flags missing e-mail', function (): void {
    pullRequire('PlatformParticipantMapper');
    $noNames = pullEntry(1);
    $noNames['formFields'] = [['fieldKey' => 'contact_email', 'label' => 'E-mail kontaktowy', 'value' => 'only@example.com']];
    $record = pullMap([$noNames])['records'][0];
    assertSame('only@example.com', $record['display_name']);
    assertNullValue($record['first_name']);
    assertNullValue($record['last_name']);

    $nothing = pullEntry(2);
    $nothing['formFields'] = [['fieldKey' => 'contact_email', 'label' => 'E-mail kontaktowy', 'value' => 'not-an-email']];
    $record = pullMap([$nothing])['records'][0];
    assertSame('Uczestnik reg-0002', $record['display_name']);
    assertSame('', $record['email']);
    assertTrue(in_array('missing_email', $record['warnings'], true));

    $noFormFields = pullEntry(3);
    unset($noFormFields['formFields']);
    $record = pullMap([$noFormFields])['records'][0];
    assertSame('Uczestnik reg-0003', $record['display_name']);
});

test('layout creates platform mappings with labels as source columns', function (): void {
    pullRequire('PlatformFieldLayout');
    $definitions = pullMap([pullEntry(1)])['field_definitions'];
    $plan = PlatformFieldLayout::plan([], $definitions, []);

    assertSame(9, count($plan['to_create']));
    foreach ($plan['to_create'] as $index => $mapping) {
        assertSame($mapping['source_column_name'], $mapping['alias']);
        assertSame($index, $mapping['display_order']);
        assertSame('text', $mapping['field_type']);
        assertTrue($mapping['is_active']);
        assertFalse($mapping['is_required']);
    }
    $byKey = $plan['source_by_key'];
    assertSame('Imię', $byKey['first_name']);
    assertSame('E-mail kontaktowy', $byKey['contact_email']);
    assertSame('Numer startowy', $byKey['bib_number']);
    assertSame('Opłata', $byKey['paid']);
    assertSame('Dystans', $byKey['distance']);
    assertSame('Pakiet startowy', $byKey['merchandise']);
    assertSame('Numer alarmowy', $byKey['field:custom_alarm']);
    assertSame(['Dystans'], $plan['important_aliases']);
    $roles = array_column($plan['to_create'], 'field_role', 'source_column_name');
    assertSame('email', $roles['E-mail kontaktowy']);
    assertSame('payment_status', $roles['Opłata']);
    assertSame('important_custom', $roles['Dystans']);
    assertSame('custom', $roles['Kategoria']);
});

test('layout reuses csv mappings with compatible roles and suffixes conflicting labels', function (): void {
    pullRequire('PlatformFieldLayout');
    $definitions = pullMap([pullEntry(1)])['field_definitions'];
    $existing = [
        ['source_column_name' => 'Email', 'alias' => 'Email', 'field_role' => 'email', 'display_order' => 0, 'is_active' => 1],
        ['source_column_name' => 'Numer', 'alias' => 'Numer', 'field_role' => 'bib_number', 'display_order' => 1, 'is_active' => 1],
        ['source_column_name' => 'Kategoria', 'alias' => 'Kat.', 'field_role' => 'custom', 'display_order' => 2, 'is_active' => 1],
        ['source_column_name' => 'Dystans', 'alias' => 'Dystans', 'field_role' => 'display_name_part', 'display_order' => 3, 'is_active' => 1],
    ];
    $plan = PlatformFieldLayout::plan($existing, $definitions, []);

    assertSame('Email', $plan['source_by_key']['contact_email']);
    assertSame('Email', $plan['alias_by_key']['contact_email']);
    assertSame('Numer', $plan['source_by_key']['bib_number']);
    assertSame('Kategoria', $plan['source_by_key']['category']);
    assertSame('Kat.', $plan['alias_by_key']['category']);
    assertSame('Dystans (platforma)', $plan['source_by_key']['distance']);
    assertSame(['Dystans (platforma)'], $plan['important_aliases']);

    $created = array_column($plan['to_create'], 'source_column_name');
    assertSame(['Imię', 'Nazwisko', 'Opłata', 'Dystans (platforma)', 'Pakiet startowy', 'Numer alarmowy'], $created);
    assertSame(4, $plan['to_create'][0]['display_order']);
    assertSame(9, $plan['to_create'][5]['display_order']);
});

test('layout follows the stored layout after an alias rename', function (): void {
    pullRequire('PlatformFieldLayout');
    $definitions = pullMap([pullEntry(1)])['field_definitions'];
    $plan0 = PlatformFieldLayout::plan([], $definitions, []);
    $existing = [];
    foreach ($plan0['to_create'] as $mapping) {
        $existing[] = $mapping;
    }
    foreach ($existing as $index => $mapping) {
        if ($mapping['source_column_name'] === 'Dystans') {
            $existing[$index]['alias'] = 'Odległość';
        }
    }
    $plan = PlatformFieldLayout::plan($existing, $definitions, $plan0['source_by_key']);

    assertSame([], $plan['to_create']);
    assertSame('Odległość', $plan['alias_by_key']['distance']);
    assertSame('Dystans', $plan['source_by_key']['distance']);
    assertSame(['Odległość'], $plan['important_aliases']);
});

test('sync creates new records and reports possible duplicates of file rows', function (): void {
    pullRequire('PlatformParticipantSync');
    $fileRow = [
        'id' => 50, 'participant_audit_key' => 'pa-50', 'platform_registration_id' => null,
        'display_name' => 'Jan1 Testowy1', 'first_name' => 'Jan1', 'last_name' => 'Testowy1', 'email' => 'jan1@example.com',
        'bib_number' => null, 'payment_status' => 'unknown', 'qr_code' => pullCode('pqr', 'file'), 'identity_code' => null,
        'custom_fields' => [], 'important_field_aliases' => [], 'platform_snapshot' => null, 'platform_removed_at' => null,
    ];
    $plan = pullPlan([$fileRow], [pullEntry(1), pullEntry(2)]);

    assertSame(2, count($plan['creates']));
    assertSame([], $plan['updates']);
    assertSame([], $plan['removals']);
    assertSame(['reg-0001'], $plan['possible_duplicates']);
    assertFalse($plan['had_platform_rows']);
    $create = $plan['creates'][0];
    assertSame('reg-0001', $create['registration_id']);
    assertSame(pullCode('pqr', 'p1'), $create['qr_code']);
    assertSame(pullCode('zqr', 'z1'), $create['identity_code']);
    assertSame('Jan1', $create['custom_fields']['Imię']);
    assertSame('Testowy1', $create['custom_fields']['Nazwisko']);
    assertSame('10 km', $create['custom_fields']['Dystans']);
    assertSame('+48 600 000 001', $create['custom_fields']['Numer alarmowy']);
    assertSame(['Dystans'], $create['important_field_aliases']);
    assertSame(1, $create['platform_snapshot']['v']);
    assertSame('jan1@example.com', $create['platform_snapshot']['email']);
});

test('sync leaves identical records untouched', function (): void {
    pullRequire('PlatformParticipantSync');
    $entries = pullEntries(5);
    $first = pullPlan([], $entries);
    $rows = pullStore($first);
    $second = pullPlan($rows, $entries);

    assertSame([], $second['creates']);
    assertSame([], $second['updates']);
    assertSame([], $second['removals']);
    assertSame(5, $second['unchanged']);
    assertTrue($second['had_platform_rows']);
});

test('sync applies platform changes and keeps local edits when the platform did not change', function (): void {
    pullRequire('PlatformParticipantSync');
    $entries = pullEntries(2);
    $rows = pullStore(pullPlan([], $entries));

    // local edit of name and bib while the platform stays the same: nothing happens
    $rows[0]['display_name'] = 'Lokalna Poprawka';
    $rows[0]['bib_number'] = '999';
    $same = pullPlan($rows, $entries);
    assertSame([], $same['updates']);
    assertSame(2, $same['unchanged']);

    // platform changes the last name: the whole name group follows the platform, bib stays local
    $entries[0] = pullEntry(1, []);
    $entries[0]['formFields'][1]['value'] = 'Zmienione';
    $changed = pullPlan($rows, $entries);
    assertSame(1, count($changed['updates']));
    $update = $changed['updates'][0];
    assertSame(1, $update['id']);
    assertSame('Jan1 Zmienione', $update['changes']['display_name']);
    assertSame('Zmienione', $update['changes']['last_name']);
    assertSame(['display_name', 'last_name', 'Nazwisko'], $update['changed_fields']);
    assertFalse(array_key_exists('bib_number', $update['changes']));
    assertSame('Lokalna Poprawka', $update['before']['display_name']);
    assertSame('Jan1 Zmienione', $update['after']['display_name']);
    assertSame('999', $update['after']['bib_number']);
    assertSame('Zmienione', $update['changes']['custom_fields']['Nazwisko']);
    assertSame('Jan1', $update['changes']['custom_fields']['Imię']);
    assertSame('Zmienione', $update['changes']['platform_snapshot']['name']['last_name']);

    // platform changes the bib: platform wins over the local edit
    $entries[0]['bibNumber'] = '555';
    $rowsAfterName = pullApplyUpdates($rows, $changed);
    $bib = pullPlan($rowsAfterName, $entries);
    assertSame(1, count($bib['updates']));
    assertSame('555', $bib['updates'][0]['changes']['bib_number']);
    assertSame(['bib_number'], $bib['updates'][0]['changed_fields']);
    assertSame('999', $bib['updates'][0]['before']['bib_number']);

    // payment and e-mail are their own groups
    $entries[1]['paid'] = false;
    $entries[1]['formFields'][2]['value'] = 'new@example.com';
    $pay = pullPlan($rowsAfterName, $entries);
    $byId = [];
    foreach ($pay['updates'] as $u) {
        $byId[$u['id']] = $u;
    }
    assertSame('unpaid', $byId[2]['changes']['payment_status']);
    assertSame('new@example.com', $byId[2]['changes']['email']);
});

test('sync rotates start and identity codes from the platform', function (): void {
    pullRequire('PlatformParticipantSync');
    $entries = pullEntries(3);
    $rows = pullStore(pullPlan([], $entries));
    $entries[0]['qrCode'] = pullCode('pqr', 'rotated-p1');
    $entries[1]['identityCode'] = pullCode('zqr', 'rotated-z2');
    $entries[2]['identityCode'] = null;
    $plan = pullPlan($rows, $entries);

    $byId = [];
    foreach ($plan['updates'] as $u) {
        $byId[$u['id']] = $u;
    }
    assertSame(pullCode('pqr', 'rotated-p1'), $byId[1]['changes']['qr_code']);
    assertSame(['qr_code'], $byId[1]['changed_fields']);
    assertSame(pullCode('zqr', 'rotated-z2'), $byId[2]['changes']['identity_code']);
    assertTrue(array_key_exists('identity_code', $byId[3]['changes']));
    assertNullValue($byId[3]['changes']['identity_code']);
    $states = json_encode([$byId[1]['before'], $byId[1]['after']]);
    assertFalse(str_contains((string)$states, 'pqr_'), 'codes must not be in change states');

    // a record without a usable start code keeps the stored one
    $entries = pullEntries(1);
    $entries[0]['qrCode'] = null;
    $keep = pullPlan($rows, $entries);
    assertSame([], $keep['updates']);
});

test('sync skips records whose start code belongs to another participant', function (): void {
    pullRequire('PlatformParticipantSync');
    $entries = pullEntries(6);
    $owners = [pullCode('pqr', 'p5') => ['id' => 999, 'event_id' => 'evt-2']];
    $plan = pullPlan([], $entries, $owners);

    assertSame(5, count($plan['creates']));
    assertSame([['registration_id' => 'reg-0005', 'reason' => 'qr_collision']], $plan['skipped']);

    $dup = pullEntries(2);
    $dup[1]['qrCode'] = $dup[0]['qrCode'];
    $twice = pullPlan([], $dup);
    assertSame(1, count($twice['creates']));
    assertSame([['registration_id' => 'reg-0002', 'reason' => 'qr_collision']], $twice['skipped']);

    // the participant's own code is never a collision
    $rows = pullStore(pullPlan([], pullEntries(2)));
    $owned = [pullCode('pqr', 'p1') => ['id' => 1, 'event_id' => 'evt-1']];
    $again = pullPlan($rows, pullEntries(2), $owned);
    assertSame([], $again['skipped']);
    assertSame(2, $again['unchanged']);
});

test('sync marks missing platform rows as removed and restores returning ones', function (): void {
    pullRequire('PlatformParticipantSync');
    $entries = pullEntries(3);
    $rows = pullStore(pullPlan([], $entries));

    $removed = pullPlan($rows, [$entries[0], $entries[2]]);
    assertSame([2], $removed['removals']);
    assertSame(0, $removed['restored']);
    $rows = pullApplyUpdates($rows, $removed);
    assertSame('2026-10-07 10:00:00', $rows[1]['platform_removed_at']);

    $again = pullPlan($rows, [$entries[0], $entries[2]]);
    assertSame([], $again['removals'], 'already removed rows are not flagged twice');

    $back = pullPlan($rows, $entries);
    assertSame([], $back['removals']);
    assertSame(1, $back['restored']);
    assertSame(1, count($back['updates']));
    assertNullValue($back['updates'][0]['changes']['platform_removed_at']);
    assertSame(['platform_removed_at'], $back['updates'][0]['changed_fields']);
});

test('sync ignores an empty platform list instead of removing everyone', function (): void {
    pullRequire('PlatformParticipantSync');
    $rows = pullStore(pullPlan([], pullEntries(3)));
    $plan = pullPlan($rows, []);

    assertSame([], $plan['removals']);
    assertSame(1, $plan['warnings']['empty_list_ignored']);

    $noRows = pullPlan([], []);
    assertSame([], $noRows['removals']);
    assertFalse(isset($noRows['warnings']['empty_list_ignored']));
});

test('sync never touches rows without a platform registration id', function (): void {
    pullRequire('PlatformParticipantSync');
    $fileRow = [
        'id' => 77, 'participant_audit_key' => 'pa-77', 'platform_registration_id' => null,
        'display_name' => 'Jan1 Testowy1', 'first_name' => 'Jan1', 'last_name' => 'Testowy1', 'email' => 'jan1@example.com',
        'bib_number' => '101', 'payment_status' => 'unpaid', 'qr_code' => pullCode('pqr', 'file77'), 'identity_code' => null,
        'custom_fields' => [], 'important_field_aliases' => [], 'platform_snapshot' => null, 'platform_removed_at' => null,
    ];
    $plan = pullPlan([$fileRow], [pullEntry(1, ['qrCode' => pullCode('pqr', 'file77')])], [pullCode('pqr', 'file77') => ['id' => 77, 'event_id' => 'evt-1']]);

    assertSame([], $plan['updates']);
    assertSame([], $plan['removals']);
    assertSame(0, count($plan['creates']), 'start code owned by the file row is a collision, not a merge');
    assertSame('qr_collision', $plan['skipped'][0]['reason']);

    $other = pullPlan([$fileRow], pullEntries(2));
    assertSame([], $other['removals']);
    foreach ($other['updates'] as $update) {
        assertTrue($update['id'] !== 77);
    }
});

test('api client fetches the participant list with both headers and the pull timeout', function (): void {
    pullRequire('PlatformApiClient');
    $captured = [];
    $transport = static function (string $method, string $url, array $headers, int $timeout) use (&$captured): array {
        $captured = compact('method', 'url', 'headers', 'timeout');

        return ['status' => 200, 'body' => '{"success":true,"data":[]}'];
    };
    $client = new PlatformApiClient(pullConfig(), $transport);
    $result = $client->fetchParticipants('p 1', pullToken());

    assertSame('ok', $result['status']);
    assertSame(1, $result['attempts']);
    assertSame(200, $result['http_status']);
    assertSame('{"success":true,"data":[]}', $result['body']);
    assertSame('GET', $captured['method']);
    assertSame('http://zc.test/api/v1/events/p%201/bz/participants', $captured['url']);
    assertSame('bz-app-key-for-tests-0123456789', $captured['headers']['X-API-Key']);
    assertSame(pullToken(), $captured['headers']['X-BZ-Organization-Token']);
    assertSame(60, $captured['timeout']);
    assertSame(array_keys($result), ['status', 'http_status', 'body', 'attempts']);

    $client = new PlatformApiClient(pullConfig(['BZ_PLATFORM_PULL_TIMEOUT_SECONDS' => '500']), $transport);
    $client->fetchParticipants('p1', pullToken());
    assertSame(120, $captured['timeout']);
});

test('api client retries network and server errors but not authorisation errors', function (): void {
    pullRequire('PlatformApiClient');
    $script = static function (array $responses): array {
        $calls = 0;
        $sleeps = [];
        $transport = static function () use (&$calls, $responses): array {
            $response = $responses[min($calls, count($responses) - 1)];
            $calls++;
            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        };
        $client = new PlatformApiClient(pullConfig(), $transport);
        $result = $client->fetchParticipants('p1', pullToken(), 3, static function (int $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        return [$result, $calls, $sleeps];
    };

    [$result, $calls, $sleeps] = $script([['status' => 500, 'body' => 'x'], ['status' => 200, 'body' => '{"success":true,"data":[]}']]);
    assertSame('ok', $result['status']);
    assertSame(2, $result['attempts']);
    assertSame([1], $sleeps);

    [$result, $calls] = $script([['status' => 403, 'body' => '{}']]);
    assertSame('token_rejected', $result['status']);
    assertSame(1, $result['attempts']);
    assertSame(1, $calls);

    foreach ([[401, 'app_key_rejected'], [404, 'platform_event_not_found']] as [$http, $expected]) {
        [$result, $calls] = $script([['status' => $http, 'body' => '{}']]);
        assertSame($expected, $result['status']);
        assertSame(1, $calls);
    }

    [$result, $calls, $sleeps] = $script([new RuntimeException('a'), new RuntimeException('b'), new RuntimeException('c')]);
    assertSame('network_error', $result['status']);
    assertSame(3, $result['attempts']);
    assertSame([1, 3], $sleeps);
    assertNullValue($result['http_status']);
    assertNullValue($result['body']);

    [$result, , $sleeps] = $script([['status' => 429, 'body' => '{}'], ['status' => 429, 'body' => '{}'], ['status' => 429, 'body' => '{}']]);
    assertSame('platform_rate_limited', $result['status']);
    assertSame([1, 3], $sleeps);

    $single = (new PlatformApiClient(pullConfig(), static fn (): array => ['status' => 500, 'body' => 'x']))
        ->fetchParticipants('p1', pullToken(), 1, static function (): void {
            fail('must not sleep with one attempt');
        });
    assertSame('platform_error', $single['status']);
    assertSame(1, $single['attempts']);
});

test('pull service skips disabled connections and unreadable tokens without calling the platform', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $log = pullFreshLog();
    $service = pullService($store, pullOkTransport(pullEntries(2)), $log);

    $store->connections['evt-1']['is_enabled'] = 0;
    $result = $service->pull('evt-1', 'manual', ['id' => 'u-1', 'name' => 'Edytor'], 2);
    assertSame('connection_disabled', $result['status']);
    assertSame(0, $log['calls']);
    assertSame('connection_disabled', $store->results[0]['status']);
    assertSame(PlatformPullStatus::describe('connection_disabled'), 'Integracja z platformą jest wyłączona dla tego wydarzenia.');
    assertSame(1, count($store->activity));
    assertSame('u-1', $store->activity[0]['user_id']);

    $store->connections['evt-1']['is_enabled'] = 1;
    $store->connections['evt-1']['organization_token_ciphertext'] = (new PlatformTokenCipher(hash('sha256', 'other', true)))->encrypt(pullToken(), 'evt-1');
    $result = $service->pull('evt-1', 'manual', null, 2);
    assertSame('token_unreadable', $result['status']);
    assertSame(0, $log['calls']);
    assertSame('token_unreadable', $store->results[1]['status']);
    assertFalse($store->lockHeld);

    assertSame('event_not_found', $service->pull('evt-9', 'manual', null, 2)['status']);
    unset($store->connections['evt-1']);
    assertSame('connection_missing', $service->pull('evt-1', 'manual', null, 2)['status']);
    $store->events['evt-1']['archived_at'] = '2026-10-01 00:00:00';
    assertSame('event_inactive', $service->pull('evt-1', 'manual', null, 2)['status']);
    assertFalse($store->lockHeld);
});

test('pull service reports a pull already in progress without recording it', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $store->lockHeld = true;
    $log = pullFreshLog();
    $service = pullService($store, pullOkTransport(pullEntries(2)), $log);
    $result = $service->pull('evt-1', 'manual', null, 2);

    assertSame('in_progress', $result['status']);
    assertSame(0, $log['calls']);
    assertSame([], $store->results);
    assertSame([], $store->activity);
    assertSame(0, $store->unlockCalls, 'a lock owned by someone else is never released');
    assertSame(0, $store->writeCount);
});

test('pull service applies a list and is idempotent on the second run', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $log = pullFreshLog();
    $service = pullService($store, pullOkTransport(pullEntries(2000)), $log);

    $first = $service->pull('evt-1', 'manual', ['id' => 'u-1', 'name' => 'Edytor'], 2);
    assertSame('ok', $first['status']);
    assertSame(2000, $first['summary']['created']);
    assertSame(2000, $first['summary']['fetched']);
    assertSame(0, $first['summary']['updated']);
    assertSame(2000, count($store->participants));
    $sample = $store->participants[7];
    assertSame(pullCode('pqr', 'p7'), $sample['qr_code']);
    assertSame(pullCode('zqr', 'z7'), $sample['identity_code']);
    assertSame('not_checked_in', $sample['status']);
    assertSame(9, count($store->mappings['evt-1']));
    assertSame(9, count($store->layouts['evt-1']));
    assertSame([], $store->changeLogs, 'first pull writes no per-row add logs');
    assertSame(1, count($store->activity));
    assertSame('ok', $store->results[0]['status']);
    assertFalse($store->lockHeld);

    $writes = $store->writeCount;
    $second = $service->pull('evt-1', 'manual', ['id' => 'u-1', 'name' => 'Edytor'], 2);
    assertSame('ok', $second['status']);
    assertSame(0, $second['summary']['created']);
    assertSame(0, $second['summary']['updated']);
    assertSame(2000, $second['summary']['unchanged']);
    assertSame($writes, $store->writeCount, 'second pull writes nothing');
    assertSame([], $store->changeLogs);
    assertSame(2, count($store->activity), 'manual trigger writes one activity row per pull');

    $cron = $service->pull('evt-1', 'cron', null, 3);
    assertSame('ok', $cron['status']);
    assertSame(2, count($store->activity), 'an unchanged cron pull stays silent');
    assertSame($writes, $store->writeCount);
    assertSame('cron', $store->connections['evt-1']['last_pull_trigger']);
});

test('pull service logs added and updated participants after the first pull', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $log = pullFreshLog();
    $entries = pullEntries(4);
    $current = $entries;
    $service = pullService($store, static function () use (&$current): array {
        return ['status' => 200, 'body' => pullBody($current)];
    }, $log);
    $service->pull('evt-1', 'manual', null, 2);

    $current = [$entries[0], $entries[2], $entries[3], pullEntry(9)];
    $current[1]['formFields'][1]['value'] = 'Zmienione';
    $result = $service->pull('evt-1', 'cron', null, 3);

    assertSame(1, $result['summary']['created']);
    assertSame(1, $result['summary']['updated']);
    assertSame(1, $result['summary']['removed']);
    $types = array_column($store->changeLogs, 'change_type');
    sort($types);
    assertSame(['added', 'updated', 'updated'], $types);
    foreach ($store->changeLogs as $entry) {
        assertSame('platform_sync', $entry['change_source']);
        assertFalse(str_contains((string)json_encode($entry), 'pqr_'));
        assertFalse(str_contains((string)json_encode($entry), 'zqr_'));
    }
    assertSame(2, count($store->activity));
    assertSame('Synchronizacja z platformą', $store->activity[1]['user_name']);
    assertNullValue($store->activity[1]['user_id']);
});

test('pull service records platform failures without touching participants', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $cases = [
        ['status' => 403, 'body' => '{}', 'expect' => 'token_rejected'],
        ['status' => 401, 'body' => '{}', 'expect' => 'app_key_rejected'],
        ['status' => 404, 'body' => '{}', 'expect' => 'platform_event_not_found'],
        ['status' => 200, 'body' => '<html>', 'expect' => 'invalid_response'],
        ['status' => 200, 'body' => str_repeat(' ', 16 * 1024 * 1024 + 1), 'expect' => 'response_too_large'],
        ['status' => 500, 'body' => 'x', 'expect' => 'platform_error'],
        ['throw' => true, 'expect' => 'network_error'],
    ];
    foreach ($cases as $case) {
        $store = new InMemoryPlatformParticipantStore();
        $store->seedParticipant();
        $before = $store->participants;
        $log = pullFreshLog();
        $sleeps = [];
        $service = pullService($store, static function () use ($case): array {
            if (isset($case['throw'])) {
                throw new RuntimeException('connection refused');
            }

            return ['status' => $case['status'], 'body' => $case['body']];
        }, $log, $sleeps);
        $result = $service->pull('evt-1', 'manual', ['id' => 'u-1', 'name' => 'Edytor'], 2);

        assertSame($case['expect'], $result['status']);
        assertSame($before, $store->participants);
        assertSame(0, $store->writeCount);
        assertSame($case['expect'], $store->results[0]['status']);
        assertSame(1, count($store->activity));
        assertFalse($store->lockHeld);
    }
});

test('pull service stops on a database error and keeps committed chunks', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $store->failOnInsertNumber = 250;
    $store->seedParticipant(['platform_registration_id' => 'reg-gone']);
    $log = pullFreshLog();
    $service = pullService($store, pullOkTransport(pullEntries(450)), $log);
    $result = $service->pull('evt-1', 'manual', null, 2);

    assertSame('apply_failed', $result['status']);
    assertSame(200 + 1, count($store->participants), 'the first chunk is kept, the failing chunk is rolled back');
    assertSame(0, $store->removeCalls);
    assertFalse($store->lockHeld);
    assertSame('apply_failed', $store->results[0]['status']);
    assertSame(200, $store->results[0]['summary']['created']);
    assertFalse(str_contains((string)json_encode($result), 'simulated'));

    // a re-pull converges
    $store->failOnInsertNumber = null;
    $again = $service->pull('evt-1', 'cron', null, 3);
    assertSame('ok', $again['status']);
    assertSame(450 + 1, count($store->participants));
});

test('pull service always releases the lock', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $store->throwOn = 'loadFieldMappings';
    $log = pullFreshLog();
    $service = pullService($store, pullOkTransport(pullEntries(2)), $log);

    $thrown = false;
    try {
        $service->pull('evt-1', 'manual', null, 2);
    } catch (RuntimeException) {
        $thrown = true;
    }
    assertTrue($thrown, 'unexpected errors surface to the caller');
    assertFalse($store->lockHeld);
    assertSame(1, $store->unlockCalls);

    $store->throwOn = null;
    $ok = $service->pull('evt-1', 'manual', null, 2);
    assertSame('ok', $ok['status']);
    assertSame(2, $store->unlockCalls);
});

test('pull results and logs never contain secrets or personal data', function (): void {
    pullRequire('PlatformParticipantPullService');
    pullRequireSodium();
    $store = new InMemoryPlatformParticipantStore();
    $store->seedParticipant(['qr_code' => pullCode('pqr', 'p5')]);
    $log = pullFreshLog();
    $logged = [];
    $sleeps = [];
    $entries = pullEntries(8);
    $entries[] = pullEntry(10, ['formFields' => [['fieldKey' => 'contact_email', 'label' => 'E-mail kontaktowy', 'value' => 'not-an-email']], 'qrCode' => 'bad']);
    $service = pullService($store, pullOkTransport($entries), $log, $sleeps, $logged);
    $result = $service->pull('evt-1', 'manual', ['id' => 'u-1', 'name' => 'Edytor'], 2);

    assertSame('partial', $result['status']);
    assertSame(1, $result['summary']['skipped']);
    assertSame(['qr_collision' => 1], $result['summary']['skipped_by_reason']);
    assertSame([['registration_id' => 'reg-0005', 'reason' => 'qr_collision']], $result['skipped_records']);

    $haystack = json_encode([$result, $store->activity, $store->results, $store->connections, $logged, $store->changeLogs]);
    foreach ([pullToken(), 'bz-app-key-for-tests', 'pqr_', 'zqr_', '@example.com', 'Testowy', 'Jan1'] as $needle) {
        assertFalse(str_contains((string)$haystack, $needle), 'leaked: ' . $needle);
    }
    $codes = array_column($logged, 'code');
    assertTrue(in_array('platform_pull.qr_collision', $codes, true));
    assertTrue(in_array('platform_pull.completed', $codes, true));
    foreach ($logged as $entry) {
        foreach (array_keys($entry['context']) as $key) {
            assertSame(0, preg_match('/password|passphrase|secret|token|authorization|cookie|qr_code|identity_code|dsn|smtp|db_|body|payload|csv/i', (string)$key), 'redacted key: ' . $key);
        }
    }
    foreach ($store->activity as $row) {
        assertTrue(mb_strlen($row['action']) <= 190);
    }
});

test('schedule pulls more often as the event approaches', function (): void {
    pullRequire('PlatformPullSchedule');
    $event = pullEvent();
    $at = static fn (string $local): DateTimeImmutable => new DateTimeImmutable($local);

    assertSame(21600, PlatformPullSchedule::intervalSeconds($event, $at('2026-09-20 12:00:00')));
    assertSame(21600, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-02 12:00:00')));
    assertSame(3600, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-03 12:00:00')));
    assertSame(3600, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-09 23:00:00')));
    assertSame(300, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-10 00:30:00')));
    assertSame(300, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-10 18:00:00')));
    assertSame(300, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-10 23:59:00')));
    assertSame(3600, PlatformPullSchedule::intervalSeconds($event, $at('2026-10-11 10:00:00')));
    assertNullValue(PlatformPullSchedule::intervalSeconds($event, $at('2026-10-12 00:00:01')));
    assertNullValue(PlatformPullSchedule::intervalSeconds(pullEvent(['archived_at' => '2026-10-01 00:00:00']), $at('2026-10-10 12:00:00')));
    assertNullValue(PlatformPullSchedule::intervalSeconds(pullEvent(['deleted_at' => '2026-10-01 00:00:00']), $at('2026-10-10 12:00:00')));

    $now = new DateTimeImmutable('2026-10-07 12:00:00', new DateTimeZone('UTC'));
    assertTrue(PlatformPullSchedule::isDue(null, 300, $now));
    assertFalse(PlatformPullSchedule::isDue(null, null, $now));
    assertFalse(PlatformPullSchedule::isDue('2026-10-07 11:58:00', 300, $now));
    assertTrue(PlatformPullSchedule::isDue('2026-10-07 11:50:00', 300, $now));
    assertTrue(PlatformPullSchedule::isDue('2026-10-07 11:55:10', 300, $now), 'cron jitter tolerance');
    assertFalse(PlatformPullSchedule::isDue('2026-10-07 10:00:00', 21600, $now));
    assertTrue(PlatformPullSchedule::isDue('2026-10-07 05:59:00', 21600, $now));
});

test('status texts are Polish for every code and activity entries fit the column', function (): void {
    pullRequire('PlatformPullStatus');
    $codes = [
        'ok', 'partial', 'connection_disabled', 'connection_missing', 'token_unreadable', 'integration_unavailable',
        'event_inactive', 'event_not_found', 'in_progress', 'app_key_rejected', 'token_rejected', 'platform_event_not_found',
        'platform_rate_limited', 'network_error', 'invalid_response', 'platform_error', 'response_too_large', 'apply_failed',
    ];
    foreach ($codes as $code) {
        $text = PlatformPullStatus::describe($code);
        assertTrue($text !== '' && $text !== $code, 'text for ' . $code);
        assertTrue((bool)preg_match('/[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $text));
        foreach (['manual', 'cron'] as $trigger) {
            $action = PlatformPullStatus::activityAction($code, $trigger, ['created' => 99999, 'updated' => 99999, 'unchanged' => 99999, 'skipped' => 99999, 'removed' => 99999]);
            assertTrue(mb_strlen($action) <= 190, $code . ' action too long: ' . mb_strlen($action));
            assertTrue(str_contains($action, 'platformy Zmierzymy Czas'));
        }
    }
    assertSame('Pobrano listę uczestników z platformy.', PlatformPullStatus::describe('ok'));
    assertSame('Platforma zwróciła nieoczekiwaną odpowiedź.', PlatformPullStatus::describe('platform_error'));
    assertSame('Platforma zwróciła nieoczekiwaną odpowiedź.', PlatformPullStatus::describe('invalid_response'));
    $ok = PlatformPullStatus::activityAction('ok', 'manual', ['created' => 12, 'updated' => 3, 'unchanged' => 1985, 'skipped' => 1, 'removed' => 2]);
    assertSame('Pobrano uczestników z platformy Zmierzymy Czas (ręcznie): nowi 12, zaktualizowani 3, bez zmian 1985, pominięci 1, usunięci na platformie 2', $ok);
    assertTrue(str_contains(PlatformPullStatus::activityAction('ok', 'cron', ['created' => 1]), '(automatycznie)'));
    assertTrue(str_starts_with(PlatformPullStatus::activityAction('network_error', 'manual', null), 'Nie udało się pobrać uczestników z platformy Zmierzymy Czas (ręcznie): '));
    foreach (['qr_collision', 'invalid_record', 'duplicate_record'] as $reason) {
        assertTrue(PlatformPullStatus::skipReason($reason) !== $reason);
    }
    assertTrue(PlatformPullStatus::isRecorded('token_rejected'));
    assertFalse(PlatformPullStatus::isRecorded('in_progress'));
    assertFalse(PlatformPullStatus::isRecorded('connection_missing'));
});

test('config exposes a clamped pull timeout', function (): void {
    pullRequire('PlatformIntegrationConfig');
    assertSame(60, pullConfig()->pullTimeoutSeconds());
    assertSame(5, pullConfig(['BZ_PLATFORM_PULL_TIMEOUT_SECONDS' => '3'])->pullTimeoutSeconds());
    assertSame(120, pullConfig(['BZ_PLATFORM_PULL_TIMEOUT_SECONDS' => '500'])->pullTimeoutSeconds());
    assertSame(60, pullConfig(['BZ_PLATFORM_PULL_TIMEOUT_SECONDS' => 'abc'])->pullTimeoutSeconds());
    assertSame(45, pullConfig(['BZ_PLATFORM_PULL_TIMEOUT_SECONDS' => '45'])->pullTimeoutSeconds());
    assertSame(10, pullConfig()->timeoutSeconds(), 'the connection test timeout is unchanged');
    assertTrue(pullConfig(['BZ_PLATFORM_PULL_TIMEOUT_SECONDS' => 'abc'])->isAvailable(), 'the pull timeout is optional');
});

test('migration 029 is portable and idempotent by construction', function (): void {
    $path = __DIR__ . '/../database/migrations/029_add_platform_participant_sync.php';
    assertTrue(is_file($path), 'Missing migration 029');
    $sql = (string)file_get_contents($path);

    foreach (['information_schema.COLUMNS', 'information_schema.STATISTICS', 'uq_participants_event_platform_registration', "'platform_sync'", 'platform_registration_id', 'platform_snapshot_json', 'platform_removed_at', 'last_pull_summary_json', 'field_layout_json'] as $needle) {
        assertStringContains($needle, $sql);
    }
    assertSame(0, preg_match('/ADD\s+(COLUMN|CONSTRAINT|INDEX|KEY)[^;]*IF NOT EXISTS/i', $sql));
    assertFalse(str_contains($sql, 'VALUES('));
    assertSame(0, preg_match('/IF NOT EXISTS/i', $sql));

    $init = (string)file_get_contents(__DIR__ . '/../database/init/001_init.sql');
    foreach (['platform_registration_id VARCHAR(64) NULL', 'platform_snapshot_json LONGTEXT NULL', 'platform_removed_at DATETIME NULL', 'UNIQUE KEY uq_participants_event_platform_registration (event_id, platform_registration_id)', "'bib_conflict_resolution', 'platform_sync'"] as $needle) {
        assertStringContains($needle, $init);
    }
    $runner = (string)file_get_contents(__DIR__ . '/../scripts/run-migrations.php');
    assertStringContains("'029_add_platform_participant_sync.php'", $runner);
});

test('pdo store uses distinct placeholders and never touches check-in columns', function (): void {
    pullRequire('PdoPlatformParticipantStore');
    $source = (string)file_get_contents(__DIR__ . '/../src/PdoPlatformParticipantStore.php');

    preg_match_all('/\'\s*((?:SELECT|INSERT|UPDATE|DELETE)(?:[^\'\\\\]|\\\\.)*)\'/s', $source, $matches);
    assertTrue(count($matches[1]) >= 10, 'expected the SQL strings of the store');
    foreach ($matches[1] as $query) {
        preg_match_all('/:([a-z_][a-z0-9_]*)/', $query, $named);
        assertSame($named[1], array_values(array_unique($named[1])), 'repeated named placeholder in: ' . substr($query, 0, 60));
        assertFalse(str_contains($query, 'VALUES('));
        if (str_starts_with($query, 'UPDATE participants')) {
            foreach (['checked_in_at', 'email_status', 'status ', 'organization', 'event_id'] as $forbidden) {
                assertFalse(str_contains($query, $forbidden . ' ='), 'participants update touches ' . $forbidden);
            }
        }
    }

    $allowlist = (new ReflectionClassConstant(PdoPlatformParticipantStore::class, 'UPDATABLE_COLUMNS'))->getValue();
    foreach (['status', 'checked_in_at', 'email_status', 'organization', 'baseline_import_record_id', 'event_id', 'id'] as $forbidden) {
        assertFalse(in_array($forbidden, $allowlist, true), 'allowlist contains ' . $forbidden);
    }
    foreach (['display_name', 'first_name', 'last_name', 'email', 'bib_number', 'payment_status', 'qr_code', 'identity_code', 'custom_fields_json', 'platform_snapshot_json', 'platform_removed_at'] as $required) {
        assertTrue(in_array($required, $allowlist, true), 'allowlist lacks ' . $required);
    }
    assertStringContains('GET_LOCK(:lock_name, 0)', $source);
    assertStringContains('RELEASE_LOCK(:lock_name)', $source);

    $index = (string)file_get_contents(__DIR__ . '/../public/index.php');
    assertSame(1, preg_match("/\\\$participantImportantFieldsMetaKey = '([^']+)'/", $index, $meta));
    assertSame($meta[1], PdoPlatformParticipantStore::IMPORTANT_FIELDS_META_KEY);
});

test('pull endpoint checks access before the platform and replace refuses while syncing', function (): void {
    $source = (string)file_get_contents(__DIR__ . '/../public/index.php');
    $start = strpos($source, "preg_match('#^/events/([^/]+)/platform-connection/pull\$#'");
    assertTrue($start !== false, 'pull handler missing');
    $end = strpos($source, "preg_match('#^/events/([^/]+)/platform-connection\$#', \$path, \$matches) === 1 && \$method === 'DELETE'", (int)$start);
    assertTrue($end !== false);
    $handler = substr($source, (int)$start, (int)$end - (int)$start);

    $role = strpos($handler, "requireAnyRole(['superadmin', 'admin', 'editor']");
    $event = strpos($handler, '$loadEventById');
    $org = strpos($handler, '$canManageEventParticipants');
    $config = strpos($handler, 'PlatformIntegrationConfig::fromProcess');
    $service = strpos($handler, 'PlatformParticipantPullService');
    assertTrue($role !== false && $event !== false && $org !== false && $config !== false && $service !== false);
    assertTrue($role < $event && $event < $org && $org < $config && $config < $service);
    assertStringContains('ignore_user_abort(true)', $handler);
    assertStringContains('platform_pull_in_progress', $handler);
    assertStringContains('platform_pull_throttled', $handler);
    assertStringContains('platform_connection_disabled', $handler);
    assertStringContains("'manual'", $handler);

    $replaceStart = strpos($source, "preg_match('#^/events/([^/]+)/participant-imports/replace\$#'");
    assertTrue($replaceStart !== false);
    $replace = substr($source, (int)$replaceStart, 3000);
    $manage = strpos($replace, '$canManageEventParticipants');
    $guard = strpos($replace, 'platform_sync_active');
    $csv = strpos($replace, "\$input['csv_content']");
    assertTrue($manage !== false && $guard !== false && $csv !== false);
    assertTrue($manage < $guard && $guard < $csv, 'the guard sits between the access check and the body');
    assertStringContains('409', substr($replace, (int)$guard - 400, 480));
});

test('masks platform snapshots in the database viewer', function (): void {
    assertTrue(isSensitiveDatabaseColumn('platform_snapshot_json'));
    assertTrue(isSensitiveDatabaseColumn('Platform_Snapshot_Json'));
});

test('lock names are stable, short and per event', function (): void {
    pullRequire('PlatformPullLock');
    $a = PlatformPullLock::name('evt-1');
    assertSame('bz_platform_pull_' . substr(sha1('evt-1'), 0, 32), $a);
    assertTrue(strlen($a) <= 64);
    assertTrue($a !== PlatformPullLock::name('evt-2'));
});
