<?php

declare(strict_types=1);

$identityWiringRead = static function (string $relative): string {
    $contents = file_get_contents(__DIR__ . '/../' . $relative);
    assertTrue(is_string($contents), $relative . ' is missing');
    return $contents;
};

test('migration 027 adds a non unique identity code index idempotently', function () use ($identityWiringRead): void {
    $path = __DIR__ . '/../database/migrations/027_add_participant_identity_code.php';
    assertTrue(is_file($path), 'Migration 027 is missing');
    $source = $identityWiringRead('database/migrations/027_add_participant_identity_code.php');
    assertStringContains('INFORMATION_SCHEMA.COLUMNS', $source);
    assertStringContains('INFORMATION_SCHEMA.STATISTICS', $source);
    assertStringContains('ADD COLUMN identity_code VARCHAR(52) NULL', $source);
    assertStringContains('ADD KEY idx_participants_identity_code_event (identity_code, event_id)', $source);
    assertSame(0, preg_match('/UNIQUE[^;]*identity_code/i', $source));
    assertFalse(stripos($source, 'IF NOT EXISTS') !== false);
});

test('fresh schema carries the identity code column', function () use ($identityWiringRead): void {
    $sql = $identityWiringRead('database/init/001_init.sql');
    assertStringContains('identity_code VARCHAR(52) NULL', $sql);
    assertStringContains('KEY idx_participants_identity_code_event (identity_code, event_id)', $sql);
    assertStringContains('UNIQUE KEY uq_participants_qr_code (qr_code)', $sql);
});

test('migration runner can adopt 027', function () use ($identityWiringRead): void {
    $source = $identityWiringRead('scripts/run-migrations.php');
    assertStringContains("'027_add_participant_identity_code.php'", $source);
    assertStringContains("'idx_participants_identity_code_event'", $source);
});

test('scan endpoint branches on the identity prefix', function () use ($identityWiringRead): void {
    $source = $identityWiringRead('public/index.php');
    foreach ([
        'ParticipantCodePolicy::classifyScannedCode($qrCode)',
        '$loadParticipantsByIdentityCode($pdo, $qrCode)',
        'ParticipantCodePolicy::resolveIdentityScan(',
        'Nie znaleziono zapisu tego zawodnika na bieżące wydarzenie.',
        'Ten kod tożsamości pasuje do zapisów w kilku wydarzeniach. Wybierz wydarzenie i zeskanuj kod ponownie.',
        "'match' => 'identity'",
        "'candidates' =>",
        'WHERE identity_code = :identity_code',
    ] as $needle) {
        assertStringContains($needle, $source, 'Missing in index.php: ' . $needle);
    }
});

test('start code repair never touches the identity code', function () use ($identityWiringRead): void {
    $source = $identityWiringRead('public/index.php');
    $start = strpos($source, '$ensureParticipantQrCode = static function');
    $end = strpos($source, '$loadParticipantById = static function');
    assertTrue($start !== false && $end !== false && $end > $start);
    $body = substr($source, $start, $end - $start);
    assertStringContains('ParticipantCodePolicy::mustRegenerateStartCode', $body);
    assertStringContains('UPDATE participants SET qr_code = :qr_code WHERE id = :id', $body);
    assertFalse(str_contains($body, 'identity_code'));
});

test('participant inserts accept a platform identity code', function () use ($identityWiringRead): void {
    $source = $identityWiringRead('public/index.php');
    $start = strpos($source, '$insertParticipantRecord = static function');
    assertTrue($start !== false);
    $body = substr($source, $start, 6000);
    assertStringContains('?string $identityCode = null', $body);
    assertStringContains('ParticipantCodePolicy::acceptedIdentityCode($identityCode)', $body);
    assertStringContains(':identity_code', $body);
});

test('no repeated named placeholders in the identity lookup', function () use ($identityWiringRead): void {
    $source = $identityWiringRead('public/index.php');
    $start = strpos($source, '$loadParticipantsByIdentityCode = static function');
    assertTrue($start !== false);
    $end = strpos($source, '$loadParticipantsByEventId = static function', $start);
    assertTrue($end !== false);
    $lookup = substr($source, $start, $end - $start);
    assertSame(1, preg_match_all('/:identity_code\b/', $lookup));

    $insertStart = strpos($source, '$insertParticipantRecord = static function');
    $insertBody = substr($source, $insertStart, 6000);
    $insertSqlStart = strpos($insertBody, 'INSERT INTO participants');
    assertTrue($insertSqlStart !== false);
    $insertSql = substr($insertBody, $insertSqlStart, 1500);
    assertSame(1, preg_match_all('/:identity_code\b/', $insertSql));
});
