<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

test('accepts a platform start code byte for byte', function (): void {
    $code = 'pqr_' . str_repeat('a1', 24);
    assertSame($code, ParticipantCodePolicy::acceptedStartCode($code));
    assertFalse(ParticipantCodePolicy::mustRegenerateStartCode($code));
});

test('trims a delivered start code and rejects malformed ones', function (): void {
    $valid = 'pqr_' . str_repeat('0f', 24);
    assertSame($valid, ParticipantCodePolicy::acceptedStartCode(' ' . $valid . " \n"));

    foreach (['pqr_short', 'PQR_' . str_repeat('a', 48), 'pqr_' . str_repeat('A', 48), 'zqr_' . str_repeat('a', 48), 'QR-evt-1-101', null, ''] as $bad) {
        assertNullValue(ParticipantCodePolicy::acceptedStartCode($bad));
        assertTrue(ParticipantCodePolicy::mustRegenerateStartCode($bad));
    }
});

test('matches the platform code format exactly', function (): void {
    for ($i = 0; $i < 20; $i++) {
        $start = 'pqr_' . bin2hex(random_bytes(24));
        $identity = 'zqr_' . bin2hex(random_bytes(24));
        assertTrue(QrCodeService::isSecureToken($start));
        assertFalse(QrCodeService::isIdentityToken($start));
        assertTrue(QrCodeService::isIdentityToken($identity));
        assertFalse(QrCodeService::isSecureToken($identity));
    }
});

test('accepts only well formed identity codes', function (): void {
    $valid = 'zqr_' . bin2hex(random_bytes(24));
    assertSame($valid, ParticipantCodePolicy::acceptedIdentityCode($valid));
    assertSame($valid, ParticipantCodePolicy::acceptedIdentityCode(' ' . $valid));
    foreach (['zqr_short', 'pqr_' . str_repeat('a', 48), 'zqr_' . str_repeat('A', 48), null, ''] as $bad) {
        assertNullValue(ParticipantCodePolicy::acceptedIdentityCode($bad));
    }
});

test('classifies scanned codes by prefix', function (): void {
    assertSame(ParticipantCodePolicy::SCAN_START, ParticipantCodePolicy::classifyScannedCode('pqr_' . str_repeat('a', 48)));
    assertSame(ParticipantCodePolicy::SCAN_IDENTITY, ParticipantCodePolicy::classifyScannedCode('zqr_' . str_repeat('a', 48)));
    assertSame(ParticipantCodePolicy::SCAN_INVALID_IDENTITY, ParticipantCodePolicy::classifyScannedCode('zqr_xyz'));
    foreach (['pqr_short', 'QR-evt-1-101', 'ZQR_' . str_repeat('a', 48)] as $legacy) {
        assertSame(ParticipantCodePolicy::SCAN_LEGACY, ParticipantCodePolicy::classifyScannedCode($legacy));
    }
});

test('resolves a single identity match in an accessible event', function (): void {
    $result = ParticipantCodePolicy::resolveIdentityScan(
        [['id' => 5, 'event_id' => 'evt-1']],
        ['evt-1'],
        [],
        null
    );
    assertSame(ParticipantCodePolicy::RESOLVED, $result['status']);
    assertSame('evt-1', $result['event_id']);
    assertSame(1, count($result['participants']));
});

test('returns all registrations of the same event sorted by id', function (): void {
    $result = ParticipantCodePolicy::resolveIdentityScan(
        [['id' => 12, 'event_id' => 'evt-1'], ['id' => 3, 'event_id' => 'evt-1']],
        ['evt-1'],
        [],
        null
    );
    assertSame(ParticipantCodePolicy::RESOLVED, $result['status']);
    assertSame('evt-1', $result['event_id']);
    assertSame([3, 12], array_map(static fn (array $p): int => (int)$p['id'], $result['participants']));
});

test('ignores registrations in events the scanner cannot access', function (): void {
    $candidates = [['id' => 1, 'event_id' => 'evt-1'], ['id' => 2, 'event_id' => 'evt-3']];
    $result = ParticipantCodePolicy::resolveIdentityScan($candidates, ['evt-1'], [], null);
    assertSame(ParticipantCodePolicy::RESOLVED, $result['status']);
    assertSame('evt-1', $result['event_id']);
});

test('reports not found when no accessible registration exists', function (): void {
    $result = ParticipantCodePolicy::resolveIdentityScan([['id' => 2, 'event_id' => 'evt-3']], ['evt-1'], [], null);
    assertSame(ParticipantCodePolicy::NOT_FOUND, $result['status']);
    assertSame([], $result['participants']);
    $empty = ParticipantCodePolicy::resolveIdentityScan([], ['evt-1'], [], null);
    assertSame(ParticipantCodePolicy::NOT_FOUND, $empty['status']);
});

test('honours the requested event', function (): void {
    $candidates = [['id' => 1, 'event_id' => 'evt-1'], ['id' => 2, 'event_id' => 'evt-2']];
    $result = ParticipantCodePolicy::resolveIdentityScan($candidates, ['evt-1', 'evt-2'], [], 'evt-2');
    assertSame(ParticipantCodePolicy::RESOLVED, $result['status']);
    assertSame('evt-2', $result['event_id']);

    $inaccessible = ParticipantCodePolicy::resolveIdentityScan($candidates, ['evt-1'], [], 'evt-2');
    assertSame(ParticipantCodePolicy::NOT_FOUND, $inaccessible['status']);
});

test('prefers the event whose office is open now', function (): void {
    $candidates = [['id' => 1, 'event_id' => 'evt-1'], ['id' => 2, 'event_id' => 'evt-2']];
    $result = ParticipantCodePolicy::resolveIdentityScan($candidates, ['evt-1', 'evt-2'], ['evt-2'], null);
    assertSame(ParticipantCodePolicy::RESOLVED, $result['status']);
    assertSame('evt-2', $result['event_id']);
});

test('reports ambiguity across events with no open office', function (): void {
    $candidates = [['id' => 2, 'event_id' => 'evt-2'], ['id' => 1, 'event_id' => 'evt-1']];
    $result = ParticipantCodePolicy::resolveIdentityScan($candidates, ['evt-1', 'evt-2'], [], null);
    assertSame(ParticipantCodePolicy::AMBIGUOUS_EVENT, $result['status']);
    assertSame(['evt-1', 'evt-2'], $result['event_ids']);
});

test('keeps all events when the requested event filter is absent and several offices are open', function (): void {
    $candidates = [['id' => 1, 'event_id' => 'evt-1'], ['id' => 2, 'event_id' => 'evt-2']];
    $result = ParticipantCodePolicy::resolveIdentityScan($candidates, ['evt-1', 'evt-2'], ['evt-1', 'evt-2'], null);
    assertSame(ParticipantCodePolicy::AMBIGUOUS_EVENT, $result['status']);
    assertSame(['evt-1', 'evt-2'], $result['event_ids']);
});
