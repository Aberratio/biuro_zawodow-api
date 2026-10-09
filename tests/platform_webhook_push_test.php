<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function whRequire(string $class): void
{
    assertTrue(class_exists($class) || interface_exists($class), "Missing class {$class}");
}

function whSource(string $relative): string
{
    $path = __DIR__ . '/../' . $relative;
    assertTrue(is_file($path), "Missing file {$relative}");

    return (string)file_get_contents($path);
}

function whHeaders(array $overrides = []): array
{
    return array_merge([
        'x-bz-organization-token' => 'tok_WEBHOOKTESTTOKEN0123456789ABCDEFGH',
        'idempotency-key' => 'bz-push:reg-1:cancellation',
        'content-type' => 'application/json',
    ], $overrides);
}

function whBody(array $overrides = []): string
{
    return (string)json_encode(array_merge([
        'eventId' => 'zc-evt-1',
        'registrationId' => 'reg-1',
        'changeType' => 'cancellation',
        'occurredAt' => '2026-10-09T12:00:00+02:00',
        'qrCode' => 'pqr_abc',
    ], $overrides));
}

function whRequest(array $overrides = []): array
{
    $parsed = PlatformWebhookRequest::parse(whHeaders(), whBody($overrides));
    assertTrue($parsed['ok'], 'fixture request must parse');

    return $parsed['request'];
}

/** @return array<string, mixed> */
function whParticipant(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'participant_audit_key' => 'pa-7',
        'display_name' => 'Jan Testowy',
        'email' => 'jan@example.com',
        'bib_number' => '101',
        'payment_status' => 'paid',
        'custom_fields' => [],
        'important_field_aliases' => [],
        'platform_removed_at' => null,
    ], $overrides);
}

if (interface_exists('PlatformWebhookStore')) {
    final class WhFakeStore implements PlatformWebhookStore
    {
        public bool $lockAvailable = true;
        public int $lockCalls = 0;
        public int $unlockCalls = 0;
        public array $receipts = [];
        public ?array $participant;
        public array $pullState = ['last_pull_started_at' => null, 'last_pull_status' => null];
        public array $removed = [];
        public array $pullRequests = [];
        public int $touches = 0;
        public array $changeLogs = [];
        public array $activity = [];
        public bool $raceOnInsert = false;
        public bool $failOnActivity = false;
        public int $writes = 0;
        private ?array $snapshot = null;

        public function __construct()
        {
            $this->participant = whParticipant();
        }

        public function lockEvent(string $eventId, int $waitSeconds): bool
        {
            $this->lockCalls++;

            return $this->lockAvailable;
        }

        public function unlockEvent(string $eventId): void
        {
            $this->unlockCalls++;
        }

        public function findReceipt(string $eventId, string $dedupeKey): ?array
        {
            return $this->receipts[$eventId . '|' . $dedupeKey] ?? null;
        }

        public function begin(): void
        {
            $this->snapshot = ['receipts' => $this->receipts, 'removed' => $this->removed];
        }

        public function commit(): void
        {
            $this->snapshot = null;
        }

        public function rollBack(): void
        {
            if ($this->snapshot !== null) {
                $this->receipts = $this->snapshot['receipts'];
                $this->removed = $this->snapshot['removed'];
                $this->snapshot = null;
            }
        }

        public function insertReceipt(array $receipt): ?int
        {
            if ($this->raceOnInsert) {
                return null;
            }
            $this->writes++;
            $id = count($this->receipts) + 1;
            $this->receipts[$receipt['event_id'] . '|' . $receipt['dedupe_key']] = $receipt + ['id' => $id];

            return $id;
        }

        public function setReceiptOutcome(int $receiptId, string $outcome, ?int $participantId): void
        {
            foreach ($this->receipts as $key => $receipt) {
                if ($receipt['id'] === $receiptId) {
                    $this->receipts[$key]['outcome'] = $outcome;
                }
            }
        }

        public function findParticipant(string $eventId, string $registrationId): ?array
        {
            return $this->participant;
        }

        public function connectionPullState(string $eventId): array
        {
            return $this->pullState;
        }

        public function markParticipantRemoved(int $participantId, string $removedUtc): bool
        {
            $this->removed[$participantId] = $removedUtc;

            return true;
        }

        public function requestPull(string $eventId, string $nowUtc): void
        {
            $this->pullRequests[] = $eventId;
        }

        public function touchWebhook(string $eventId, string $nowUtc): void
        {
            $this->touches++;
        }

        public function addChangeLog(string $eventId, array $entry): void
        {
            $this->changeLogs[] = $entry;
        }

        public function addActivityLog(string $eventId, string $action, array $metadata, ?int $participantId = null, ?string $participantName = null): void
        {
            if ($this->failOnActivity) {
                throw new RuntimeException('boom');
            }
            $this->activity[] = ['action' => $action, 'metadata' => $metadata];
        }
    }
}

function whService(WhFakeStore $store, array &$logs = []): PlatformCriticalChangeService
{
    return new PlatformCriticalChangeService($store, static function (string $level, string $code, string $message, array $context) use (&$logs): void {
        $logs[] = ['level' => $level, 'code' => $code, 'context' => $context];
    });
}

const WH_NOW = '2026-10-09 12:30:00';

// 1-4 PlatformWebhookRequest ------------------------------------------------------------------------------

test('webhook request parses the sender contract', function (): void {
    whRequire('PlatformWebhookRequest');
    $parsed = PlatformWebhookRequest::parse(whHeaders(), whBody());
    assertTrue($parsed['ok']);
    assertNullValue($parsed['error']);
    $request = $parsed['request'];
    assertSame('tok_WEBHOOKTESTTOKEN0123456789ABCDEFGH', $request['token']);
    assertSame('bz-push:reg-1:cancellation', $request['dedupe_key']);
    assertSame('zc-evt-1', $request['event_id']);
    assertSame('reg-1', $request['registration_id']);
    assertSame('cancellation', $request['change_type']);
    assertMatches('/^[0-9a-f]{64}$/', $request['fingerprint']);
});

test('webhook request rejects missing credentials and malformed bodies', function (): void {
    whRequire('PlatformWebhookRequest');
    $noToken = PlatformWebhookRequest::parse(['idempotency-key' => 'k1'], whBody());
    assertFalse($noToken['ok']);
    assertSame('missing_token', $noToken['error']);
    $noKey = PlatformWebhookRequest::parse(['x-bz-organization-token' => 't'], whBody());
    assertSame('missing_idempotency_key', $noKey['error']);
    $badKey = PlatformWebhookRequest::parse(whHeaders(['idempotency-key' => "bad key\n"]), whBody());
    assertSame('missing_idempotency_key', $badKey['error']);
    assertSame('invalid_json', PlatformWebhookRequest::parse(whHeaders(), 'not json')['error']);
    assertSame('invalid_json', PlatformWebhookRequest::parse(whHeaders(), '[1,2]')['error']);
    assertSame('invalid_request', PlatformWebhookRequest::parse(whHeaders(), whBody(['eventId' => '']))['error']);
    assertSame('invalid_request', PlatformWebhookRequest::parse(whHeaders(), whBody(['registrationId' => 'a b']))['error']);
    assertSame('invalid_request', PlatformWebhookRequest::parse(whHeaders(), whBody(['changeType' => 'Cancel!']))['error']);
    assertSame('invalid_request', PlatformWebhookRequest::parse(whHeaders(), (string)json_encode(['eventId' => 'e', 'registrationId' => 'r']))['error']);
    $unknownType = PlatformWebhookRequest::parse(whHeaders(), whBody(['changeType' => 'something_new']));
    assertTrue($unknownType['ok'], 'unknown change types parse; the service ignores them');
});

test('webhook request converts occurredAt to UTC and tolerates garbage', function (): void {
    whRequire('PlatformWebhookRequest');
    assertSame('2026-10-09 10:00:00', whRequest()['occurred_at']);
    assertNullValue(whRequest(['occurredAt' => 'yesterday-ish'])['occurred_at']);
    assertNullValue(whRequest(['occurredAt' => null])['occurred_at']);
});

test('webhook fingerprint does not depend on JSON key order', function (): void {
    whRequire('PlatformWebhookRequest');
    $a = PlatformWebhookRequest::parse(whHeaders(), '{"eventId":"zc-evt-1","registrationId":"reg-1","changeType":"cancellation","occurredAt":"2026-10-09T12:00:00+02:00","qrCode":null}');
    $b = PlatformWebhookRequest::parse(whHeaders(), '{"qrCode":"pqr_x","occurredAt":"2026-10-09T10:00:00Z","changeType":"cancellation","registrationId":"reg-1","eventId":"zc-evt-1"}');
    assertSame($a['request']['fingerprint'], $b['request']['fingerprint']);
    $c = PlatformWebhookRequest::parse(whHeaders(), whBody(['changeType' => 'refund_refunded']));
    assertTrue($a['request']['fingerprint'] !== $c['request']['fingerprint']);
});

// 5-7 PlatformWebhookAuthenticator -------------------------------------------------------------------------

test('webhook authenticator decides all five outcomes', function (): void {
    whRequire('PlatformWebhookAuthenticator');
    $enabled = ['is_enabled' => 1];
    assertSame('ok', PlatformWebhookAuthenticator::decide($enabled, 'tok', 'tok'));
    assertSame('unknown_event', PlatformWebhookAuthenticator::decide(null, null, 'tok'));
    assertSame('connection_disabled', PlatformWebhookAuthenticator::decide(['is_enabled' => 0], 'tok', 'tok'));
    assertSame('token_unreadable', PlatformWebhookAuthenticator::decide($enabled, null, 'tok'));
    assertSame('token_mismatch', PlatformWebhookAuthenticator::decide($enabled, 'tok', 'other'));
});

test('webhook authenticator compares exactly once in every branch', function (): void {
    whRequire('PlatformWebhookAuthenticator');
    $cases = [
        [['is_enabled' => 1], 'tok', 'tok'],
        [null, null, 'tok'],
        [['is_enabled' => 0], 'tok', 'tok'],
        [['is_enabled' => 1], null, 'tok'],
        [['is_enabled' => 1], 'tok', 'x'],
    ];
    foreach ($cases as $index => [$connection, $token, $presented]) {
        $calls = 0;
        $seen = [];
        PlatformWebhookAuthenticator::decide($connection, $token, $presented, static function (string $expected, string $given) use (&$calls, &$seen): bool {
            $calls++;
            $seen[] = $expected;

            return hash_equals($expected, $given);
        });
        assertSame(1, $calls, "case {$index}: compare count");
        if ($token === null || $connection === null) {
            assertSame(64, strlen($seen[0]), "case {$index}: dummy secret has fixed length");
        }
    }
});

test('webhook authenticator never accepts a disabled connection or an unreadable token', function (): void {
    whRequire('PlatformWebhookAuthenticator');
    assertSame('connection_disabled', PlatformWebhookAuthenticator::decide(['is_enabled' => 0], 'tok', 'tok'));
    assertSame('token_unreadable', PlatformWebhookAuthenticator::decide(['is_enabled' => 1], null, ''));
    assertSame('token_mismatch', PlatformWebhookAuthenticator::decide(['is_enabled' => 1], 'tok', ''));
});

// 8-15 PlatformCriticalChangeService ------------------------------------------------------------------------

test('service marks a cancelled participant as withdrawn with a trail', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    $result = whService($store)->handle('evt-1', whRequest(), WH_NOW);
    assertSame(200, $result['http']);
    assertSame('applied', $result['status']);
    assertSame([7 => '2026-10-09 10:00:00'], $store->removed);
    assertSame(1, count($store->changeLogs));
    assertSame('platform_webhook', $store->changeLogs[0]['change_source']);
    assertSame(['platform_removed_at'], $store->changeLogs[0]['changed_fields']);
    assertSame(1, count($store->activity));
    assertSame(1, $store->touches);
    assertSame(1, count($store->receipts));
    assertSame('applied', array_values($store->receipts)[0]['outcome']);
    assertSame(1, $store->unlockCalls);
    $json = json_encode([$store->changeLogs[0]['changed_fields'], $store->activity[0]['metadata']]);
    assertFalse(str_contains((string)$json, 'jan@example.com'));
    assertFalse(str_contains((string)$json, 'Jan Testowy'));
});

test('service never dates a withdrawal in the future and reports already removed rows', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    whService($store)->handle('evt-1', whRequest(['occurredAt' => '2030-01-01T00:00:00+00:00']), WH_NOW);
    assertSame([7 => WH_NOW], $store->removed);

    $store = new WhFakeStore();
    $store->participant = whParticipant(['platform_removed_at' => '2026-10-09 09:00:00']);
    $result = whService($store)->handle('evt-1', whRequest(), WH_NOW);
    assertSame('already_removed', $result['status']);
    assertSame([], $store->removed);
    assertSame([], $store->changeLogs);
    assertSame(1, count($store->activity));
});

test('service ignores a cancellation that a newer pull has already superseded', function (): void {
    whRequire('PlatformCriticalChangeService');
    // occurredAt = 10:00:00 UTC; margin 120 s
    $superseded = function (string $started, string $status): array {
        $store = new WhFakeStore();
        $store->pullState = ['last_pull_started_at' => $started, 'last_pull_status' => $status];
        $result = whService($store)->handle('evt-1', whRequest(), WH_NOW);

        return [$result['status'], $store];
    };
    [$status, $store] = $superseded('2026-10-09 10:02:01', 'ok');
    assertSame('superseded_by_pull', $status);
    assertSame([], $store->removed);
    assertSame(['evt-1'], $store->pullRequests);
    [$status] = $superseded('2026-10-09 10:02:00', 'ok');
    assertSame('applied', $status, 'exactly at the margin is not superseded');
    [$status] = $superseded('2026-10-09 09:59:00', 'ok');
    assertSame('applied', $status, 'a pull from before the cancellation cannot supersede it');
    [$status] = $superseded('2026-10-09 11:00:00', 'partial');
    assertSame('superseded_by_pull', $status);
    [$status] = $superseded('2026-10-09 11:00:00', 'platform_error');
    assertSame('applied', $status, 'a failed pull proves nothing');
});

test('service records an approved refund without changing the participant', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    $result = whService($store)->handle('evt-1', whRequest(['changeType' => 'refund_approved']), WH_NOW);
    assertSame('recorded', $result['status']);
    assertSame([], $store->removed);
    assertSame([], $store->changeLogs);
    assertSame([], $store->pullRequests);
    assertSame(1, count($store->activity));
});

test('service asks for an early pull after a completed refund', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    $result = whService($store)->handle('evt-1', whRequest(['changeType' => 'refund_refunded']), WH_NOW);
    assertSame('recorded', $result['status']);
    assertSame(['evt-1'], $store->pullRequests);
    assertSame([], $store->removed);
});

test('service copes with unknown participants and unknown change types', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    $store->participant = null;
    $result = whService($store)->handle('evt-1', whRequest(['changeType' => 'refund_refunded']), WH_NOW);
    assertSame(200, $result['http']);
    assertSame('participant_not_found', $result['status']);
    assertSame(['evt-1'], $store->pullRequests);

    $store = new WhFakeStore();
    $store->participant = null;
    $result = whService($store)->handle('evt-1', whRequest(), WH_NOW);
    assertSame('participant_not_found', $result['status']);
    assertSame([], $store->pullRequests);

    $store = new WhFakeStore();
    $logs = [];
    $result = whService($store, $logs)->handle('evt-1', whRequest(['changeType' => 'brand_new']), WH_NOW);
    assertSame(200, $result['http']);
    assertSame('ignored_unknown_type', $result['status']);
    assertSame([], $store->removed);
    assertTrue(count($logs) >= 1 && $logs[0]['level'] === 'warning');
});

test('service answers replays without any write and survives a racing delivery', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    $service = whService($store);
    $first = $service->handle('evt-1', whRequest(), WH_NOW);
    assertSame('applied', $first['status']);
    $writesAfterFirst = $store->writes;
    $changeLogs = count($store->changeLogs);
    $lockCalls = $store->lockCalls;

    $replay = $service->handle('evt-1', whRequest(['qrCode' => 'pqr_other']), WH_NOW);
    assertSame(200, $replay['http']);
    assertSame('duplicate', $replay['status']);
    assertSame($writesAfterFirst, $store->writes);
    assertSame($changeLogs, count($store->changeLogs));
    assertSame($lockCalls, $store->lockCalls, 'the fast path takes no lock');
    assertSame(1, count($store->receipts));

    $logs = [];
    $reused = whService($store, $logs)->handle('evt-1', whRequest(['occurredAt' => '2026-10-09T13:00:00+02:00']), WH_NOW);
    assertSame('duplicate', $reused['status']);
    assertSame('platform_webhook.dedupe_key_reused', $logs[0]['code']);

    $race = new WhFakeStore();
    $race->raceOnInsert = true;
    $result = whService($race)->handle('evt-1', whRequest(), WH_NOW);
    assertSame(200, $result['http']);
    assertSame('duplicate', $result['status']);
    assertSame([], $race->removed);
    assertSame(1, $race->unlockCalls);
});

test('service answers 503 while the event lock is busy and rolls back on errors', function (): void {
    whRequire('PlatformCriticalChangeService');
    $store = new WhFakeStore();
    $store->lockAvailable = false;
    $result = whService($store)->handle('evt-1', whRequest(), WH_NOW);
    assertSame(503, $result['http']);
    assertSame('busy', $result['status']);
    assertSame(60, $result['retry_after']);
    assertSame([], $store->receipts);
    assertSame([], $store->removed);

    $failing = new WhFakeStore();
    $failing->failOnActivity = true;
    $result = whService($failing)->handle('evt-1', whRequest(), WH_NOW);
    assertSame(500, $result['http']);
    assertSame([], $failing->receipts, 'the receipt is rolled back so that the retry re-applies');
    assertSame([], $failing->removed);
    assertSame(1, $failing->unlockCalls);
});

// 16-18 PlatformPushFieldMap --------------------------------------------------------------------------------

test('push field map translates office corrections to platform keys', function (): void {
    whRequire('PlatformPushFieldMap');
    $alias = ['first_name' => 'Imię', 'last_name' => 'Nazwisko', 'category' => 'Kategoria', 'field:custom_alarm' => 'Numer alarmowy'];
    assertSame(['contact_email', 'bib_number'], PlatformPushFieldMap::platformKeys(['email', 'bib_number'], $alias));
    assertSame(['first_name', 'last_name', 'category', 'custom_alarm'], PlatformPushFieldMap::platformKeys(['Imię', 'Nazwisko', 'Kategoria', 'Numer alarmowy'], $alias));
});

test('push field map never pushes payment, display name, distance or merchandise', function (): void {
    whRequire('PlatformPushFieldMap');
    $alias = ['distance' => 'Dystans', 'merchandise' => 'Pakiet startowy', 'first_name' => 'Imię'];
    assertSame([], PlatformPushFieldMap::platformKeys(['display_name', 'payment_status', 'Dystans', 'Pakiet startowy'], $alias));
    assertSame(['first_name'], PlatformPushFieldMap::platformKeys(['display_name', 'Imię', 'payment_status'], $alias));
});

test('push field map ignores unmapped aliases and duplicates', function (): void {
    whRequire('PlatformPushFieldMap');
    assertSame([], PlatformPushFieldMap::platformKeys(['Kolor skarpetek'], ['first_name' => 'Imię']));
    assertSame(['bib_number'], PlatformPushFieldMap::platformKeys(['bib_number', 'bib_number'], []));
    assertSame([], PlatformPushFieldMap::platformKeys([], ['first_name' => 'Imię']));
});

// 19-20 PlatformPushQueueEntry -----------------------------------------------------------------------------

test('queue entry merge keeps the latest stamp per field', function (): void {
    whRequire('PlatformPushQueueEntry');
    $old = ['checked_in_at' => ['at' => '2026-10-09 10:00:00'], 'checked_in_by' => ['at' => '2026-10-09 10:00:00', 'value' => 'Ala']];
    $new = ['checked_in_at' => ['at' => '2026-10-09 10:05:00'], 'checked_in_by' => ['at' => '2026-10-09 10:05:00', 'value' => ''], 'bib_number' => ['at' => '2026-10-09 10:06:00']];
    $merged = PlatformPushQueueEntry::merge($old, $new);
    assertSame($new, $merged);
    assertSame($old, PlatformPushQueueEntry::merge($old, []));
    assertSame('2026-10-09 10:05:00', PlatformPushQueueEntry::merge($old, ['checked_in_at' => ['at' => '2026-10-09 10:05:00']])['checked_in_at']['at']);
});

test('queue entry settle drops what was sent and keeps newer changes', function (): void {
    whRequire('PlatformPushQueueEntry');
    $sent = ['checked_in_at' => ['at' => '2026-10-09 10:00:00'], 'checked_in_by' => ['at' => '2026-10-09 10:00:00', 'value' => 'Ala']];
    assertSame([], PlatformPushQueueEntry::settle($sent, $sent));
    $stored = $sent;
    $stored['checked_in_at'] = ['at' => '2026-10-09 10:03:00'];
    $stored['checked_in_by'] = ['at' => '2026-10-09 10:03:00', 'value' => ''];
    $stored['bib_number'] = ['at' => '2026-10-09 10:04:00'];
    assertSame($stored, PlatformPushQueueEntry::settle($stored, $sent));
    $partly = $sent + ['bib_number' => ['at' => '2026-10-09 10:04:00']];
    assertSame(['bib_number' => ['at' => '2026-10-09 10:04:00']], PlatformPushQueueEntry::settle($partly, $sent));
    // same second, different value (check-in undone immediately) must survive
    $undone = ['checked_in_at' => ['at' => '2026-10-09 10:00:00'], 'checked_in_by' => ['at' => '2026-10-09 10:00:00', 'value' => '']];
    assertSame($undone['checked_in_by'], PlatformPushQueueEntry::settle($undone, $sent)['checked_in_by']);
});

// 21-22 PlatformPushRequestBuilder ---------------------------------------------------------------------------

test('push builder sends both check-in keys together in ISO UTC and nulls for an undo', function (): void {
    whRequire('PlatformPushRequestBuilder');
    $participant = ['checked_in_at' => '2026-10-09 10:00:00', 'email' => 'jan@example.com', 'bib_number' => '101', 'first_name' => 'Jan', 'last_name' => 'Testowy', 'custom_fields' => []];
    $fields = ['checked_in_at' => ['at' => '2026-10-09 10:00:00'], 'checked_in_by' => ['at' => '2026-10-09 10:00:00', 'value' => 'Ala Biuro']];
    $built = PlatformPushRequestBuilder::build($participant, $fields, []);
    assertSame(['checked_in_at', 'checked_in_by'], array_keys($built['fields']));
    assertSame(['value' => '2026-10-09T10:00:00+00:00', 'changedAt' => '2026-10-09T10:00:00+00:00'], $built['fields']['checked_in_at']);
    assertSame(['value' => 'Ala Biuro', 'changedAt' => '2026-10-09T10:00:00+00:00'], $built['fields']['checked_in_by']);
    assertSame('Biuro Zawodów: odprawa', $built['reason']);

    $undoParticipant = $participant;
    $undoParticipant['checked_in_at'] = null;
    $undoFields = ['checked_in_at' => ['at' => '2026-10-09 10:09:00'], 'checked_in_by' => ['at' => '2026-10-09 10:09:00', 'value' => '']];
    $undo = PlatformPushRequestBuilder::build($undoParticipant, $undoFields, []);
    assertNullValue($undo['fields']['checked_in_at']['value']);
    assertNullValue($undo['fields']['checked_in_by']['value']);
});

test('push builder sends current values of corrected fields and turns empty text into null', function (): void {
    whRequire('PlatformPushRequestBuilder');
    $participant = ['checked_in_at' => null, 'email' => 'nowy@example.com', 'bib_number' => '', 'first_name' => 'Jan', 'last_name' => 'Testowy',
        'custom_fields' => ['Imię' => 'Janusz', 'Nazwisko' => '', 'Numer alarmowy' => '+48 600 000 001']];
    $alias = ['first_name' => 'Imię', 'last_name' => 'Nazwisko', 'field:custom_alarm' => 'Numer alarmowy'];
    $at = ['at' => '2026-10-09 10:00:00'];
    $built = PlatformPushRequestBuilder::build($participant, [
        'contact_email' => $at, 'bib_number' => $at, 'first_name' => $at, 'last_name' => $at, 'custom_alarm' => $at,
    ], $alias);
    assertSame('nowy@example.com', $built['fields']['contact_email']['value']);
    assertNullValue($built['fields']['bib_number']['value']);
    assertSame('Janusz', $built['fields']['first_name']['value']);
    assertSame('Testowy', $built['fields']['last_name']['value'], 'an empty mapped value falls back to the column');
    assertSame('+48 600 000 001', $built['fields']['custom_alarm']['value']);
    assertSame('Biuro Zawodów: poprawka danych', $built['reason']);
    assertFalse(array_key_exists('payment_status', $built['fields']));
});

// 23-25 PlatformPushResultClassifier -----------------------------------------------------------------------

test('push classifier maps every platform answer to an outcome', function (): void {
    whRequire('PlatformPushResultClassifier');
    $c = static fn (string $status, ?int $http, int $attempts = 1): array => PlatformPushResultClassifier::classify($status, $http, $attempts);
    assertSame('sent', $c('ok', 200)['outcome']);
    assertFalse($c('ok', 200)['stop_run']);
    assertSame(['failed', true], [$c('app_key_rejected', 401)['outcome'], $c('app_key_rejected', 401)['stop_run']]);
    assertSame(['failed', false], [$c('token_rejected', 403)['outcome'], $c('token_rejected', 403)['stop_run']]);
    assertSame(['abandoned', false], [$c('platform_registration_not_found', 404)['outcome'], $c('platform_registration_not_found', 404)['stop_run']]);
    assertSame('abandoned', $c('platform_validation_rejected', 422)['outcome']);
    assertSame(['failed', true], [$c('platform_rate_limited', 429)['outcome'], $c('platform_rate_limited', 429)['stop_run']]);
    foreach ([['platform_error', 503], ['network_error', null], ['invalid_response', 200]] as [$status, $http]) {
        assertSame(['failed', false], [$c($status, $http)['outcome'], $c($status, $http)['stop_run']], $status);
    }
});

test('push classifier backs off 1 2 5 10 30 60 then stays at 60 minutes', function (): void {
    whRequire('PlatformPushResultClassifier');
    $seconds = [];
    for ($attempt = 1; $attempt <= 9; $attempt++) {
        $seconds[] = PlatformPushResultClassifier::classify('platform_error', 503, $attempt)['retry_in_seconds'];
    }
    assertSame([60, 120, 300, 600, 1800, 3600, 3600, 3600, 3600], $seconds);
    assertTrue(PlatformPushResultClassifier::classify('platform_rate_limited', 429, 1)['retry_in_seconds'] >= 300, 'rate limit waits at least 5 minutes');
});

test('push classifier abandons a failing row after 24 attempts', function (): void {
    whRequire('PlatformPushResultClassifier');
    assertSame('failed', PlatformPushResultClassifier::classify('platform_error', 503, 23)['outcome']);
    assertSame('abandoned', PlatformPushResultClassifier::classify('platform_error', 503, 24)['outcome']);
    assertSame('abandoned', PlatformPushResultClassifier::classify('network_error', null, 30)['outcome']);
    assertNullValue(PlatformPushResultClassifier::classify('platform_registration_not_found', 404, 1)['retry_in_seconds']);
});

// 26 PlatformApiClient::patchParticipant ---------------------------------------------------------------------

test('api client patches a participant with both headers, a JSON body and no redirects', function (): void {
    whRequire('PlatformApiClient');
    assertTrue(method_exists('PlatformApiClient', 'patchParticipant'), 'patchParticipant missing');
    $config = PlatformIntegrationConfig::fromEnvironment(static fn (string $n): string|false => [
        'BZ_PLATFORM_API_URL' => 'http://zc.test/api/v1',
        'BZ_PLATFORM_APP_KEY' => 'bz-app-key-for-tests-0123456789',
        'BZ_PLATFORM_TOKEN_KEY' => base64_encode(hash('sha256', 'k', true)),
    ][$n] ?? false, true, true);
    $calls = [];
    $reply = ['status' => 200, 'body' => '{"success":true,"data":{"applied":{"bib_number":{"old":"1","new":"2"}},"rejected":{"contact_email":{"reason":"stale","incomingValue":"SECRET-MARKER","currentValue":"SECRET-MARKER"}}}}'];
    $client = new PlatformApiClient($config, static function (string $method, string $url, array $headers, int $timeout, ?string $body = null) use (&$calls, &$reply): array {
        $calls[] = compact('method', 'url', 'headers', 'timeout', 'body');

        return $reply;
    });
    $body = ['fields' => ['bib_number' => ['value' => '2', 'changedAt' => '2026-10-09T10:00:00+00:00']], 'reason' => 'Biuro Zawodów: poprawka danych'];
    $result = $client->patchParticipant('zc evt/1', 'reg 1', 'tok_SECRET', $body);

    assertSame('ok', $result['status']);
    assertSame(200, $result['http_status']);
    assertSame(['bib_number'], $result['data']['applied']);
    assertSame(['contact_email' => 'stale'], $result['data']['rejected']);
    assertSame('PATCH', $calls[0]['method']);
    assertSame('http://zc.test/api/v1/events/zc%20evt%2F1/bz/participants/reg%201', $calls[0]['url']);
    assertSame('bz-app-key-for-tests-0123456789', $calls[0]['headers']['X-API-Key']);
    assertSame('tok_SECRET', $calls[0]['headers']['X-BZ-Organization-Token']);
    assertSame('application/json', $calls[0]['headers']['Content-Type']);
    assertSame($body, json_decode((string)$calls[0]['body'], true));
    assertSame(10, $calls[0]['timeout']);
    assertFalse(str_contains((string)json_encode($result), 'SECRET'), 'result never carries values or the token');

    $source = whSource('src/PlatformApiClient.php');
    assertStringContains("'content'", $source);
    assertStringContains("'max_redirects' => 0", $source);

    foreach ([[401, 'app_key_rejected'], [403, 'token_rejected'], [404, 'platform_registration_not_found'], [422, 'platform_validation_rejected'], [429, 'platform_rate_limited'], [500, 'platform_error']] as [$http, $status]) {
        $reply = ['status' => $http, 'body' => 'x'];
        assertSame($status, $client->patchParticipant('e', 'r', 't', $body)['status'], (string)$http);
    }
    $reply = ['status' => 200, 'body' => 'not json'];
    assertSame('invalid_response', $client->patchParticipant('e', 'r', 't', $body)['status']);
    $throwing = new PlatformApiClient($config, static function (): array {
        throw new RuntimeException('curl said SECRET');
    });
    $failed = $throwing->patchParticipant('e', 'r', 'tok_SECRET', $body);
    assertSame('network_error', $failed['status']);
    assertFalse(str_contains((string)json_encode($failed), 'SECRET'));
});

// 27-28 are in platform_participant_pull_test.php (isDue) and platform_connection_test.php (presenter).

// 29 wiring ------------------------------------------------------------------------------------------------

test('index wires the webhook, the queue hooks and the withdrawn flag', function (): void {
    $source = whSource('public/index.php');
    $webhook = strpos($source, "'/webhooks/critical-changes'");
    $login = strpos($source, "\$path === '/auth/login'");
    assertTrue($webhook !== false && $login !== false && $webhook < $login, 'webhook handler precedes /auth/login');
    $handler = substr($source, (int)$webhook, (int)$login - (int)$webhook);
    assertFalse(str_contains($handler, 'requireAuth'), 'the webhook has its own token authentication');
    assertFalse(str_contains($handler, 'requireAnyRole'));
    foreach (['PlatformWebhookRequest::parse', 'PlatformWebhookAuthenticator::decide', 'PlatformCriticalChangeService', 'Retry-After', 'hash_equals'] as $needle) {
        if ($needle === 'hash_equals') {
            continue;
        }
        assertStringContains($needle, $handler);
    }
    $parse = strpos($handler, 'PlatformWebhookRequest::parse');
    $decide = strpos($handler, 'PlatformWebhookAuthenticator::decide');
    $service = strpos($handler, 'PlatformCriticalChangeService');
    assertTrue($parse < $decide && $decide < $service, 'parse, authenticate, then apply');

    preg_match_all('/\$updateParticipantStatus\(([^;]*?)\);/s', $source, $calls);
    assertSame(4, count($calls[1]), 'four callers of the status closure');
    foreach ($calls[1] as $arguments) {
        assertSame(4, count(array_filter(array_map('trim', explode(',', $arguments)), static fn (string $part): bool => $part !== '')), 'caller passes the actor: ' . $arguments);
        assertStringContains('$authUser', $arguments);
    }

    $serializer = strpos($source, '$serializeScanParticipant = static function');
    assertTrue($serializer !== false);
    assertStringContains('platform_removed_at', substr($source, (int)$serializer, 1800));
    foreach (['$loadParticipantById', '$loadParticipantByQrCode', '$loadParticipantsByIdentityCode', '$loadParticipantsByEventId', '$loadParticipantsByEventIds'] as $loader) {
        $start = strpos($source, $loader . ' = static function');
        assertTrue($start !== false, "{$loader} missing");
        assertStringContains('platform_removed_at', substr($source, (int)$start, 1500), "{$loader} selects the withdrawn flag");
        assertStringContains('platform_registration_id', substr($source, (int)$start, 1500), "{$loader} selects the registration id");
    }
    assertStringContains("'platform_webhook' => 'Zmiana zgłoszona przez platformę'", $source);
    assertStringContains('$enqueuePlatformPush', $source);
});

// 30 static SQL and logging --------------------------------------------------------------------------------

test('new storage classes and migration 030 are portable and log no personal data', function (): void {
    $files = ['src/PdoPlatformWebhookStore.php', 'src/PdoPlatformPushQueueStore.php', 'database/migrations/030_add_platform_webhooks_and_push_queue.php'];
    foreach ($files as $file) {
        $source = whSource($file);
        preg_match_all('/\'\s*((?:SELECT|INSERT|UPDATE|DELETE)(?:[^\'\\\\]|\\\\.)*)\'/s', $source, $matches);
        assertTrue(count($matches[1]) >= 3, "expected SQL strings in {$file}");
        foreach ($matches[1] as $query) {
            preg_match_all('/:([a-z_][a-z0-9_]*)/', $query, $named);
            assertSame($named[1], array_values(array_unique($named[1])), "repeated named placeholder in {$file}: " . substr($query, 0, 60));
            assertFalse(str_contains($query, 'VALUES('), "VALUES( in {$file}");
        }
        assertSame(0, preg_match('/IF NOT EXISTS/i', $source), "IF NOT EXISTS in {$file}");
    }
    $migration = whSource('database/migrations/030_add_platform_webhooks_and_push_queue.php');
    foreach (['platform_webhook_receipts', 'platform_push_queue', 'uq_platform_webhook_receipts_dedupe', 'uq_platform_push_queue_participant', 'pull_requested_at', 'last_webhook_at', "'platform_webhook'", 'information_schema.COLUMNS', 'information_schema.TABLES'] as $needle) {
        assertStringContains($needle, $migration);
    }
    $init = whSource('database/init/001_init.sql');
    assertStringContains("'bib_conflict_resolution', 'platform_sync', 'platform_webhook'", $init);
    assertStringContains("'030_add_platform_webhooks_and_push_queue.php'", whSource('scripts/run-migrations.php'));

    $pattern = '/password|passphrase|secret|token|authorization|cookie|qr_code|identity_code|dsn|smtp|db_|body|payload|csv/i';
    foreach (['src/PlatformCriticalChangeService.php', 'src/PlatformPushService.php', 'src/PdoPlatformWebhookStore.php', 'src/PdoPlatformPushQueueStore.php', 'public/index.php'] as $file) {
        $source = whSource($file);
        preg_match_all('/(?:platform_webhook|platform_push)\.[a-z_]+\'[^\n;]*?\[([^\]]*)\]/', $source, $contexts);
        foreach ($contexts[1] as $context) {
            preg_match_all("/'([a-z_]+)'\s*=>/", $context, $keys);
            foreach ($keys[1] as $key) {
                assertSame(0, preg_match($pattern, $key), "log context key {$key} would be redacted in {$file}");
                assertFalse(in_array($key, ['email', 'display_name', 'name'], true), "personal key {$key} in log context of {$file}");
            }
        }
    }
});

// Extra: push service with a fake store -----------------------------------------------------------------------

if (interface_exists('PlatformPushQueueStore')) {
    final class WhFakePushStore implements PlatformPushQueueStore
    {
        public array $rows = [];
        public array $participants = [];
        public ?array $connection;
        public array $log = [];
        public array $activity = [];
        public array $settled = [];
        public bool $locked = false;
        public bool $lockAvailable = true;
        public array $aliases = [];

        public function __construct()
        {
            $this->connection = [
                'event_id' => 'evt-1', 'platform_event_id' => 'zc-evt-1', 'is_enabled' => 1,
                'organization_token_ciphertext' => whConfig()->cipher()->encrypt('tok_PUSHTESTTOKEN0123456789ABCDEFGHIJ', 'evt-1'),
            ];
        }

        public function add(int $id, int $participantId, array $fields, int $attempts = 0): void
        {
            $this->rows[$id] = ['id' => $id, 'event_id' => 'evt-1', 'participant_id' => $participantId, 'fields' => $fields, 'status' => 'pending', 'attempts' => $attempts];
            $this->participants[$participantId] ??= [
                'id' => $participantId, 'event_id' => 'evt-1', 'display_name' => 'Jan Testowy', 'first_name' => 'Jan', 'last_name' => 'Testowy',
                'email' => 'jan@example.com', 'bib_number' => '101', 'checked_in_at' => '2026-10-09 10:00:00', 'custom_fields' => [],
                'platform_registration_id' => 'reg-' . $participantId,
            ];
        }

        public function enqueue(string $eventId, int $participantId, array $fields, string $nowUtc): void
        {
        }

        public function listDue(string $nowUtc, int $limit, ?string $eventId = null): array
        {
            return array_values(array_filter($this->rows, static fn (array $r): bool => in_array($r['status'], ['pending', 'failed'], true)));
        }

        public function claim(int $queueId, int $seenAttempts, string $nowUtc): bool
        {
            if ($this->rows[$queueId]['attempts'] !== $seenAttempts) {
                return false;
            }
            $this->rows[$queueId]['attempts']++;

            return true;
        }

        public function settle(int $queueId, array $sentFields, array $rejections, string $nowUtc, ?int $httpStatus): string
        {
            $this->settled[$queueId] = ['rejections' => $rejections, 'http' => $httpStatus];
            $remaining = PlatformPushQueueEntry::settle($this->rows[$queueId]['fields'], $sentFields);
            $this->rows[$queueId]['fields'] = $remaining;
            $this->rows[$queueId]['status'] = $remaining === [] ? 'sent' : 'pending';

            return $this->rows[$queueId]['status'];
        }

        public function markFailed(int $queueId, string $error, ?int $httpStatus, int $retryInSeconds, string $nowUtc): void
        {
            $this->rows[$queueId]['status'] = 'failed';
            $this->log[] = ['failed', $queueId, $error, $retryInSeconds];
        }

        public function defer(int $queueId, string $error, int $retryInSeconds, string $nowUtc): void
        {
            $this->rows[$queueId]['status'] = 'failed';
            $this->log[] = ['deferred', $queueId, $error, $retryInSeconds];
        }

        public function markAbandoned(int $queueId, string $error, ?int $httpStatus, string $nowUtc): void
        {
            $this->rows[$queueId]['status'] = 'abandoned';
            $this->log[] = ['abandoned', $queueId, $error];
        }

        public function retryAbandoned(?string $eventId, string $nowUtc): int
        {
            $n = 0;
            foreach ($this->rows as $id => $row) {
                if ($row['status'] === 'abandoned') {
                    $this->rows[$id]['status'] = 'pending';
                    $this->rows[$id]['attempts'] = 0;
                    $n++;
                }
            }

            return $n;
        }

        public function findParticipant(int $participantId): ?array
        {
            return $this->participants[$participantId] ?? null;
        }

        public function findConnection(string $eventId): ?array
        {
            return $this->connection;
        }

        public function aliasByKey(string $eventId): array
        {
            return $this->aliases;
        }

        public function addActivity(string $eventId, ?int $participantId, ?string $participantName, string $action, array $metadata): void
        {
            $this->activity[] = ['action' => $action, 'metadata' => $metadata];
        }

        public function tryGlobalLock(): bool
        {
            $this->locked = $this->lockAvailable;

            return $this->lockAvailable;
        }

        public function releaseGlobalLock(): void
        {
            $this->locked = false;
        }
    }
}

function whConfig(): PlatformIntegrationConfig
{
    return PlatformIntegrationConfig::fromEnvironment(static fn (string $n): string|false => [
        'BZ_PLATFORM_API_URL' => 'http://zc.test/api/v1',
        'BZ_PLATFORM_APP_KEY' => 'bz-app-key-for-tests-0123456789',
        'BZ_PLATFORM_TOKEN_KEY' => base64_encode(hash('sha256', 'wh-test-key', true)),
    ][$n] ?? false, true, true);
}

function whCheckIn(string $at = '2026-10-09 10:00:00'): array
{
    return ['checked_in_at' => ['at' => $at], 'checked_in_by' => ['at' => $at, 'value' => 'Ala Biuro']];
}

/** @param list<array{status: int, body: string}|Throwable> $replies */
function whPush(WhFakePushStore $store, array $replies, array &$requests = []): array
{
    $client = new PlatformApiClient(whConfig(), static function (string $method, string $url, array $headers, int $timeout, ?string $body = null) use (&$replies, &$requests): array {
        $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => json_decode((string)$body, true)];
        $reply = array_shift($replies) ?? ['status' => 200, 'body' => '{"success":true,"data":{"applied":{},"rejected":{}}}'];
        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return $reply;
    });
    $logs = [];
    $service = new PlatformPushService($store, whConfig(), $client, static fn (): string => '2026-10-09 12:00:00', static function (string $level, string $code, string $message, array $context) use (&$logs): void {
        $logs[] = ['code' => $code, 'context' => $context];
    });

    return ['result' => $service->run(), 'logs' => $logs];
}

test('push service delivers a check-in in one request and settles the row', function (): void {
    whRequire('PlatformPushService');
    $store = new WhFakePushStore();
    $store->add(1, 7, whCheckIn());
    $requests = [];
    $run = whPush($store, [['status' => 200, 'body' => '{"success":true,"data":{"applied":{"checked_in_at":{},"checked_in_by":{}},"rejected":{}}}']], $requests);
    assertSame(1, count($requests));
    assertSame('PATCH', $requests[0]['method']);
    assertSame(['checked_in_at', 'checked_in_by'], array_keys($requests[0]['body']['fields']));
    assertSame('sent', $store->rows[1]['status']);
    assertSame('ok', $run['result']['status']);
    assertFalse($run['result']['failed']);
    assertTrue(str_starts_with($run['result']['lines'][0], 'q=1 evt-1 sent fields=checked_in_at,checked_in_by'));
    $logText = (string)json_encode($run['logs']) . (string)json_encode($run['result']);
    foreach (['jan@example.com', 'Jan Testowy', 'Ala Biuro', 'tok_PUSHTEST'] as $secret) {
        assertFalse(str_contains($logText, $secret), "{$secret} leaked");
    }
});

test('push service keeps a change that arrived while the request was in flight', function (): void {
    whRequire('PlatformPushService');
    $store = new WhFakePushStore();
    $store->add(1, 7, whCheckIn());
    $client = new PlatformApiClient(whConfig(), static function () use ($store): array {
        $store->rows[1]['fields'] = whCheckIn('2026-10-09 10:05:00'); // merged into the queue row meanwhile

        return ['status' => 200, 'body' => '{"success":true,"data":{"applied":{},"rejected":{}}}'];
    });
    $service = new PlatformPushService($store, whConfig(), $client, static fn (): string => '2026-10-09 12:00:00', static function (): void {
    });
    $result = $service->run();
    assertSame('pending', $store->rows[1]['status']);
    assertTrue(str_contains($result['lines'][0], 'sent_more_pending'));
});

test('push service backs off, stops on rate limits and abandons on 404', function (): void {
    whRequire('PlatformPushService');
    $store = new WhFakePushStore();
    $store->add(1, 7, whCheckIn());
    whPush($store, [['status' => 503, 'body' => 'x']]);
    assertSame('failed', $store->rows[1]['status']);
    assertSame(['failed', 1, 'platform_error', 60], $store->log[0]);

    $limited = new WhFakePushStore();
    $limited->add(1, 7, whCheckIn());
    $limited->add(2, 8, whCheckIn());
    $requests = [];
    $run = whPush($limited, [['status' => 429, 'body' => '']], $requests);
    assertSame(1, count($requests), 'the run stops after the first 429');
    assertSame('pending', $limited->rows[2]['status']);
    assertTrue($run['result']['failed']);

    $unauthorised = new WhFakePushStore();
    $unauthorised->add(1, 7, whCheckIn());
    $unauthorised->add(2, 8, whCheckIn());
    $requests = [];
    whPush($unauthorised, [['status' => 401, 'body' => '']], $requests);
    assertSame(1, count($requests), 'the run stops on a rejected application key');

    $missing = new WhFakePushStore();
    $missing->add(1, 7, whCheckIn());
    $missing->add(2, 8, whCheckIn());
    $requests = [];
    whPush($missing, [['status' => 404, 'body' => '{}']], $requests);
    assertSame('abandoned', $missing->rows[1]['status']);
    assertSame('sent', $missing->rows[2]['status'], '404 does not stop the run');
    assertStringContains('Nie wysłano odprawy na platformę Zmierzymy Czas', $missing->activity[0]['action']);

    $network = new WhFakePushStore();
    $network->add(1, 7, whCheckIn());
    whPush($network, [new RuntimeException('curl SECRET')]);
    assertSame('failed', $network->rows[1]['status']);
    assertSame('network_error', $network->log[0][2]);
});

test('push service records stale rejections and waits for disabled connections without attempts', function (): void {
    whRequire('PlatformPushService');
    $store = new WhFakePushStore();
    $store->add(1, 7, ['bib_number' => ['at' => '2026-10-09 10:00:00']]);
    whPush($store, [['status' => 200, 'body' => '{"success":true,"data":{"applied":{},"rejected":{"bib_number":{"reason":"stale","incomingValue":"1","currentValue":"2"}}}}']]);
    assertSame('sent', $store->rows[1]['status']);
    assertSame(['bib_number' => 'stale'], $store->settled[1]['rejections']);
    assertSame('Platforma Zmierzymy Czas odrzuciła zmiany jako nieaktualne: numer startowy', $store->activity[0]['action']);

    $requests = [];
    $disabled = new WhFakePushStore();
    $disabled->add(1, 7, whCheckIn());
    $disabled->connection['is_enabled'] = 0;
    whPush($disabled, [], $requests);
    assertSame([], $requests);
    assertSame(0, $disabled->rows[1]['attempts'], 'no attempt is consumed');
    assertSame('connection_disabled', $disabled->log[0][2]);

    $orphan = new WhFakePushStore();
    $orphan->add(1, 7, whCheckIn());
    unset($orphan->participants[7]);
    whPush($orphan, [], $requests);
    assertSame('abandoned', $orphan->rows[1]['status']);

    $busy = new WhFakePushStore();
    $busy->lockAvailable = false;
    $busy->add(1, 7, whCheckIn());
    $run = whPush($busy, [], $requests);
    assertSame('in_progress', $run['result']['status']);
    assertSame('pending', $busy->rows[1]['status']);
});
