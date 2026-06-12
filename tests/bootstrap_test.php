<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

test('normalizes local date time strings accepted by the API', function (): void {
    assertSame('2026-05-25 09:30:00', normalizeLocalDateTimeString('2026-05-25T09:30'));
    assertSame('2026-05-25 09:30:45', normalizeLocalDateTimeString('2026-05-25 09:30:45.123'));
    assertTrue(isValidLocalDateTimeString('2026-05-25T09:30:00+02:00'));
    assertNullValue(normalizeLocalDateTimeString('not-a-date'));
});

test('normalizes and deduplicates configured CORS origins', function (): void {
    putenv('APP_FRONTEND_URL=https://Panel.Example.com/app');
    putenv('APP_URL=https://api.example.com/v1');

    assertSame(
        ['https://app.example.com:8443', 'https://panel.example.com', 'https://api.example.com'],
        configuredCorsOrigins(' https://APP.example.com:8443/path,https://app.example.com:8443/other ')
    );

    assertTrue(isLocalDevelopmentCorsOrigin('http://localhost:5173'));
    assertTrue(isLocalDevelopmentCorsOrigin('http://192.168.1.20:5173'));
    assertFalse(isLocalDevelopmentCorsOrigin('https://example.com'));
});

test('encodes and decodes base64url payloads strictly', function (): void {
    $payload = '{"role":"admin"}';
    $encoded = base64UrlEncode($payload);

    assertSame($payload, base64UrlDecode($encoded));
    assertFalseValue(base64UrlDecode('%%%'));
});

test('validates email, password and participant display names', function (): void {
    assertTrue(isValidEmailAddress('anna@example.com'));
    assertFalse(isValidEmailAddress('broken@example'));
    assertSame('anna@example.com', normalizeEmailAddress(' Anna@Example.COM '));
    assertSame(['first_name' => 'Anna', 'last_name' => 'Maria Test'], splitParticipantDisplayName(' Anna   Maria Test '));
    assertSame(['first_name' => null, 'last_name' => null], splitParticipantDisplayName('   '));
    assertNullValue(validatePasswordRules('StrongPass1!'));
    assertNotNullValue(validatePasswordRules('weak'));
});

test('normalizes invalid UTF-8 before JSON encoding', function (): void {
    $encoded = encodeJsonValue(['name' => "Anna\xC3"]);

    assertTrue(is_string($encoded));
    assertStringContains('"name"', $encoded);
});

test('issues and validates signed auth tokens', function (): void {
    putenv('APP_KEY=0123456789abcdef0123456789abcdef');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . issueAuthToken([
        'id' => 'user-1',
        'name' => 'Anna Admin',
        'email' => 'anna@example.com',
        'role' => 'admin',
        'organization_id' => null,
        'assigned_events' => ['event-1'],
    ]);

    $user = authenticatedUserFromRequest();

    assertSame('user-1', $user['id'] ?? null);
    assertSame('admin', $user['role'] ?? null);
    assertSame(['event-1'], $user['assigned_events'] ?? null);

    $_SERVER['HTTP_AUTHORIZATION'] .= 'tampered';
    assertNullValue(authenticatedUserFromRequest());
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

test('builds app URLs and password reset helpers from environment', function (): void {
    putenv('APP_KEY=0123456789abcdef0123456789abcdef');
    putenv('APP_URL=https://api.example.com/');
    putenv('APP_FRONTEND_URL=https://panel.example.com/');

    assertSame('https://api.example.com', appUrl());
    assertSame('https://panel.example.com', appFrontendUrl());
    assertSame('https://api.example.com/qr-images/pqr_abc.png', qrCodeImageUrl('pqr_abc'));
    assertSame(3600, PASSWORD_RESET_TTL_SECONDS);
    assertSame(604800, ACCOUNT_SETUP_TTL_SECONDS);
    assertSame(hash_hmac('sha256', 'token-1', '0123456789abcdef0123456789abcdef'), passwordResetTokenHash('token-1'));
    assertMatches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', passwordResetExpiresAt(60));
});

test('documents critical API paths in the generated OpenAPI document', function (): void {
    $document = openApiDocument();
    $paths = array_keys($document['paths'] ?? []);

    foreach ([
        '/auth/login',
        '/auth/me',
        '/bootstrap',
        '/superadmin/operations',
        '/superadmin/audit',
        '/superadmin/audit/{id}',
        '/superadmin/server-logs',
        '/superadmin/logging-status',
        '/events/{id}/participant-imports/analyze',
        '/events/{id}/participant-imports/run',
        '/events/{id}/participant-field-mappings',
        '/participants/scan',
        '/participants/{id}/check-in',
        '/participants/{id}/undo-check-in',
    ] as $path) {
        assertTrue(in_array($path, $paths, true), "OpenAPI path missing: {$path}");
    }
});

test('redacts secrets and writes structured application logs', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'biuro-zawodow-logs-' . bin2hex(random_bytes(4));
    putenv('LOG_DIR=' . $directory);
    putenv('LOG_LEVEL=debug');

    $id = ApplicationLogger::log('info', 'test.logger', 'Token token=secret-value', [
        'password' => 'Secret123!',
        'ordinary' => 'visible',
    ]);

    assertTrue(is_string($id));
    $entries = ApplicationLogger::readEntries(
        'application',
        new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')),
        new DateTimeImmutable('+1 minute', new DateTimeZone('UTC')),
        ['event_code' => 'test.logger']
    );
    assertSame(1, count($entries));
    assertSame('[REDACTED]', $entries[0]['context']['password'] ?? null);
    assertSame('visible', $entries[0]['context']['ordinary'] ?? null);
    assertStringContains('[REDACTED]', (string)($entries[0]['message'] ?? ''));
    assertSame([], ApplicationLogger::readEntries(
        '../../etc/passwd',
        new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')),
        new DateTimeImmutable('+1 minute', new DateTimeZone('UTC'))
    ));
    assertNullValue(ApplicationLogger::findEntry('application', '../2026-01-01', (string)$id));

    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
});

test('masks sensitive database values without changing ordinary columns', function (): void {
    $columns = [
        ['name' => 'id'],
        ['name' => 'password'],
        ['name' => 'token_hash'],
        ['name' => 'qr_code'],
        ['name' => 'bucket_key'],
        ['name' => 'email'],
    ];
    $rows = [[
        'id' => 'user-1',
        'password' => 'secret',
        'token_hash' => 'hash',
        'qr_code' => 'pqr_secret',
        'bucket_key' => 'auth_login:anna@example.com',
        'email' => 'anna@example.com',
    ]];

    assertTrue(isSensitiveDatabaseColumn('PASSWORD'));
    assertFalse(isSensitiveDatabaseColumn('participant_audit_key'));
    assertSame([[
        'id' => 'user-1',
        'password' => '[MASKED]',
        'token_hash' => '[MASKED]',
        'qr_code' => '[MASKED]',
        'bucket_key' => '[MASKED]',
        'email' => 'anna@example.com',
    ]], maskSensitiveDatabaseRows($rows, $columns));
});

test('generates and validates participant QR tokens', function (): void {
    $token = QrCodeService::generateToken();

    assertTrue(QrCodeService::isSecureToken($token));
    assertFalse(QrCodeService::isSecureToken('pqr_short'));
    assertFalse(QrCodeService::isSecureToken(null));
});
