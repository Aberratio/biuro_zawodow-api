<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function platformRequire(string $class): void
{
    assertTrue(class_exists($class), "Missing class {$class}");
}

function platformRequireSodium(): void
{
    assertTrue(function_exists('sodium_crypto_secretbox'), 'libsodium missing: run with php -d extension=sodium');
}

function platformRawKey(string $seed): string
{
    return hash('sha256', 'platform-test-key:' . $seed, true);
}

function platformValidEnv(array $overrides = []): callable
{
    $values = array_merge([
        'BZ_PLATFORM_API_URL' => 'https://zc.test/api/v1/',
        'BZ_PLATFORM_APP_KEY' => 'bz-app-key-for-tests-0123456789',
        'BZ_PLATFORM_TOKEN_KEY' => base64_encode(platformRawKey('current')),
    ], $overrides);

    return static fn (string $name): string|false => array_key_exists($name, $values) ? $values[$name] : false;
}

function platformConfig(array $overrides = [], bool $sodium = true, bool $insecure = false): PlatformIntegrationConfig
{
    return PlatformIntegrationConfig::fromEnvironment(platformValidEnv($overrides), $sodium, $insecure);
}

function platformToken(): string
{
    return 'tok_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
}

function platformHandlersSource(): string
{
    $source = (string)file_get_contents(__DIR__ . '/../public/index.php');
    $start = strpos($source, "preg_match('#^/events/([^/]+)/platform-connection");
    assertTrue($start !== false, 'Nie znaleziono handlerow platform-connection');
    $end = strpos($source, "preg_match('#^/events/([^/]+)\$#', \$path, \$matches) === 1 && \$method === 'DELETE'", (int)$start);
    assertTrue($end !== false, 'Nie znaleziono konca handlerow platform-connection');

    return substr($source, (int)$start, (int)$end - (int)$start);
}

test('cipher round-trips a token for the same BZ event', function (): void {
    platformRequire('PlatformTokenCipher');
    platformRequireSodium();
    $cipher = new PlatformTokenCipher(platformRawKey('current'));
    $envelope = $cipher->encrypt(platformToken(), 'evt-1');

    assertMatches('/^v1\.[0-9a-f]{12}\.[A-Za-z0-9_-]+$/', $envelope);
    assertSame(platformToken(), $cipher->decrypt($envelope, 'evt-1'));
    assertFalse(str_contains($envelope, platformToken()));
});

test('cipher uses a fresh nonce per encryption', function (): void {
    platformRequire('PlatformTokenCipher');
    platformRequireSodium();
    $cipher = new PlatformTokenCipher(platformRawKey('current'));

    assertTrue($cipher->encrypt(platformToken(), 'evt-1') !== $cipher->encrypt(platformToken(), 'evt-1'));
});

test('cipher returns null for another event, tampering, garbage and unknown key id', function (): void {
    platformRequire('PlatformTokenCipher');
    platformRequireSodium();
    $cipher = new PlatformTokenCipher(platformRawKey('current'));
    $envelope = $cipher->encrypt(platformToken(), 'evt-1');

    assertNullValue($cipher->decrypt($envelope, 'evt-2'));

    [$version, $kid, $payload] = explode('.', $envelope, 3);
    $raw = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $tampered = $version . '.' . $kid . '.' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    assertNullValue($cipher->decrypt($tampered, 'evt-1'));

    assertNullValue($cipher->decrypt('', 'evt-1'));
    assertNullValue($cipher->decrypt('garbage', 'evt-1'));
    assertNullValue($cipher->decrypt('v1.' . $kid . '.', 'evt-1'));
    assertNullValue($cipher->decrypt('v1.' . $kid . '.!!!', 'evt-1'));
    assertNullValue($cipher->decrypt('v2.' . $kid . '.' . $payload, 'evt-1'));
    assertNullValue($cipher->decrypt('v1.000000000000.' . $payload, 'evt-1'));

    $other = new PlatformTokenCipher(platformRawKey('other'));
    assertNullValue($other->decrypt($envelope, 'evt-1'));
});

test('cipher decrypts previous-key rows and flags them for re-encryption', function (): void {
    platformRequire('PlatformTokenCipher');
    platformRequireSodium();
    $old = new PlatformTokenCipher(platformRawKey('old'));
    $oldEnvelope = $old->encrypt(platformToken(), 'evt-1');

    $rotated = new PlatformTokenCipher(platformRawKey('new'), platformRawKey('old'));
    $newEnvelope = $rotated->encrypt(platformToken(), 'evt-1');

    assertSame(platformToken(), $rotated->decrypt($oldEnvelope, 'evt-1'));
    assertSame(platformToken(), $rotated->decrypt($newEnvelope, 'evt-1'));
    assertTrue($rotated->needsReencryption($oldEnvelope));
    assertFalse($rotated->needsReencryption($newEnvelope));
    assertSame(PlatformTokenCipher::keyId(platformRawKey('new')), explode('.', $newEnvelope)[1]);
});

test('config reports a reason for each missing or invalid variable', function (): void {
    platformRequire('PlatformIntegrationConfig');
    $cases = [
        ['sodium_missing', [], false],
        ['token_key_missing', ['BZ_PLATFORM_TOKEN_KEY' => ''], true],
        ['token_key_invalid', ['BZ_PLATFORM_TOKEN_KEY' => 'not-base64!!'], true],
        ['token_key_invalid', ['BZ_PLATFORM_TOKEN_KEY' => base64_encode('short')], true],
        ['previous_token_key_invalid', ['BZ_PLATFORM_TOKEN_KEY_PREVIOUS' => 'xx'], true],
        ['api_url_missing', ['BZ_PLATFORM_API_URL' => ''], true],
        ['api_url_invalid', ['BZ_PLATFORM_API_URL' => 'ftp://zc.test/api/v1'], true],
        ['api_url_invalid', ['BZ_PLATFORM_API_URL' => 'https://zc.test/api/v1?x=1'], true],
        ['api_url_invalid', ['BZ_PLATFORM_API_URL' => 'https://user:pw@zc.test/api/v1'], true],
        ['api_url_invalid', ['BZ_PLATFORM_API_URL' => 'https://zc.test/api/v1#frag'], true],
        ['app_key_missing', ['BZ_PLATFORM_APP_KEY' => ''], true],
    ];

    foreach ($cases as [$reason, $overrides, $sodium]) {
        $config = platformConfig($overrides, $sodium);
        assertSame([$reason], $config->unavailableReasons(), 'case ' . $reason . ' ' . json_encode(array_keys($overrides)));
        assertFalse($config->isAvailable());
    }

    $none = PlatformIntegrationConfig::fromEnvironment(static fn (string $n): string|false => false, true, false);
    assertSame(['token_key_missing', 'api_url_missing', 'app_key_missing'], $none->unavailableReasons());
    $noSodium = PlatformIntegrationConfig::fromEnvironment(static fn (string $n): string|false => false, false, false);
    assertSame(['sodium_missing', 'token_key_missing', 'api_url_missing', 'app_key_missing'], $noSodium->unavailableReasons());
});

test('config is available only when every variable is valid', function (): void {
    platformRequire('PlatformIntegrationConfig');
    platformRequireSodium();
    $config = platformConfig(['BZ_PLATFORM_TOKEN_KEY_PREVIOUS' => base64_encode(platformRawKey('old')), 'BZ_PLATFORM_TIMEOUT_SECONDS' => '99']);

    assertTrue($config->isAvailable());
    assertSame([], $config->unavailableReasons());
    assertSame('https://zc.test/api/v1', $config->apiBaseUrl());
    assertSame('bz-app-key-for-tests-0123456789', $config->appKey());
    assertSame(30, $config->timeoutSeconds());
    assertSame(10, platformConfig()->timeoutSeconds());
    assertSame(1, platformConfig(['BZ_PLATFORM_TIMEOUT_SECONDS' => '0'])->timeoutSeconds());
    assertTrue($config->cipher() instanceof PlatformTokenCipher);

    $threw = false;
    try {
        platformConfig(['BZ_PLATFORM_APP_KEY' => ''])->cipher();
    } catch (LogicException $e) {
        $threw = true;
        assertSame('platform_integration_unavailable', $e->getMessage());
    }
    assertTrue($threw);
});

test('config refuses http urls outside local environments', function (): void {
    platformRequire('PlatformIntegrationConfig');
    $overrides = ['BZ_PLATFORM_API_URL' => 'http://127.0.0.1:8199/api/v1'];

    assertSame(['api_url_invalid'], platformConfig($overrides, true, false)->unavailableReasons());
    assertSame([], platformConfig($overrides, true, true)->unavailableReasons());
});

test('input requires platform event id and token on first save', function (): void {
    platformRequire('PlatformConnectionInput');
    $result = PlatformConnectionInput::parse([], false);

    assertFalse($result['ok']);
    assertTrue(isset($result['fields']['platform_event_id']));
    assertTrue(isset($result['fields']['organization_token']));
    assertStringContains('Podaj ID wydarzenia z platformy', $result['fields']['platform_event_id']);
    assertStringContains('Podaj token organizacji', $result['fields']['organization_token']);

    foreach (['p 1', 'p/1', str_repeat('a', 65), 'żółw'] as $badId) {
        $r = PlatformConnectionInput::parse(['platform_event_id' => $badId, 'organization_token' => str_repeat('a', 40)], false);
        assertFalse($r['ok'], $badId);
        assertTrue(isset($r['fields']['platform_event_id']));
    }

    $ok = PlatformConnectionInput::parse([
        'platform_event_id' => ' p1 ',
        'organization_token' => str_repeat('a', 43),
        'is_enabled' => true,
        'unknown' => 'ignored',
    ], false);
    assertTrue($ok['ok']);
    assertSame('p1', $ok['platform_event_id']);
    assertSame(str_repeat('a', 43), $ok['organization_token']);
    assertTrue($ok['is_enabled']);
});

test('input keeps the stored token when the token field is empty', function (): void {
    platformRequire('PlatformConnectionInput');
    foreach ([[], ['organization_token' => ''], ['organization_token' => '   '], ['organization_token' => null]] as $extra) {
        $r = PlatformConnectionInput::parse(array_merge(['platform_event_id' => 'p1'], $extra), true);
        assertTrue($r['ok']);
        assertNullValue($r['organization_token']);
        assertFalse($r['is_enabled']);
    }

    $bad = PlatformConnectionInput::parse(['platform_event_id' => 'p1', 'is_enabled' => 'yes'], true);
    assertFalse($bad['ok']);
    assertStringContains('przełącznika', $bad['fields']['is_enabled']);
});

test('input rejects short tokens and tokens with whitespace without echoing them', function (): void {
    platformRequire('PlatformConnectionInput');
    $bad = ['short-secret-value', str_repeat('a', 31), str_repeat('a', 20) . ' ' . str_repeat('b', 20), str_repeat('c', 256)];
    foreach ($bad as $token) {
        $r = PlatformConnectionInput::parse(['platform_event_id' => 'p1', 'organization_token' => $token], true);
        assertFalse($r['ok'], 'token length ' . strlen($token));
        assertStringContains('od 32 do 255 znaków', $r['fields']['organization_token']);
        assertFalse(str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), $token));
    }

    // Surrounding whitespace is trimmed, not rejected.
    $padded = PlatformConnectionInput::parse(['platform_event_id' => 'p1', 'organization_token' => ' ' . str_repeat('a', 40) . "\n"], true);
    assertTrue($padded['ok']);
    assertSame(str_repeat('a', 40), $padded['organization_token']);
});

test('presenter exposes only whitelisted connection keys', function (): void {
    platformRequire('PlatformConnectionPresenter');
    platformRequire('PlatformIntegrationConfig');
    $row = [
        'event_id' => 'evt-1',
        'platform_event_id' => 'p1',
        'is_enabled' => 1,
        'organization_token_ciphertext' => 'v1.aaaaaaaaaaaa.CIPHERTEXT-MARKER',
        'organization_token_hint' => 'wxyz',
        'token_updated_at' => '2026-10-05 10:00:00',
        'last_test_at' => '2026-10-05 10:05:00',
        'last_test_status' => 'ok',
        'created_by_user_id' => 'u-2',
        'updated_by_user_id' => 'u-2',
        'created_at' => '2026-10-05 10:00:00',
        'updated_at' => '2026-10-05 10:05:00',
        'plaintext' => 'PLAINTEXT-MARKER',
    ];
    $view = PlatformConnectionPresenter::present($row, platformConfig(), true);

    assertSame(['availability', 'connection'], array_keys($view));
    assertSame([
        'platform_event_id', 'is_enabled', 'token_set', 'token_hint', 'token_readable',
        'token_updated_at', 'last_test_at', 'last_test_status', 'updated_at',
    ], array_keys($view['connection']));
    assertTrue($view['connection']['is_enabled'] === true);
    assertTrue($view['connection']['token_readable'] === true);
    assertSame('2026-10-05T10:00:00Z', $view['connection']['token_updated_at']);
    $json = json_encode($view);
    assertFalse(str_contains($json, 'CIPHERTEXT-MARKER'));
    assertFalse(str_contains($json, 'PLAINTEXT-MARKER'));
    assertFalse(str_contains($json, 'ciphertext'));

    $none = PlatformConnectionPresenter::present(null, platformConfig(), null);
    assertNullValue($none['connection']);
    assertTrue($none['availability']['available']);
});

test('presenter reports unavailable integration with reasons', function (): void {
    platformRequire('PlatformConnectionPresenter');
    platformRequire('PlatformIntegrationConfig');
    $config = PlatformIntegrationConfig::fromEnvironment(static fn (string $n): string|false => false, true, false);
    $view = PlatformConnectionPresenter::present(null, $config, null);

    assertFalse($view['availability']['available']);
    assertSame(['token_key_missing', 'api_url_missing', 'app_key_missing'], $view['availability']['reasons']);

    $row = ['event_id' => 'evt-1', 'platform_event_id' => 'p1', 'is_enabled' => 0, 'organization_token_ciphertext' => 'x',
        'organization_token_hint' => 'abcd', 'token_updated_at' => '2026-10-05 10:00:00', 'last_test_at' => null,
        'last_test_status' => null, 'updated_at' => '2026-10-05 10:00:00'];
    $withRow = PlatformConnectionPresenter::present($row, $config, null);
    assertNullValue($withRow['connection']['token_readable']);
    assertNullValue($withRow['connection']['last_test_at']);
    assertTrue($withRow['connection']['token_set']);
});

function platformFakeTransport(array &$captured, array|Throwable $response): callable
{
    return static function (string $method, string $url, array $headers, int $timeout) use (&$captured, $response): array {
        $captured[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'timeout' => $timeout];
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    };
}

test('api client sends app key and organization token as headers only', function (): void {
    platformRequire('PlatformApiClient');
    platformRequire('PlatformIntegrationConfig');
    $captured = [];
    $client = new PlatformApiClient(platformConfig(), platformFakeTransport($captured, ['status' => 200, 'body' => '{"success":true,"data":[]}']));
    $client->probeParticipants('p1', platformToken());

    assertSame(1, count($captured));
    assertSame('GET', $captured[0]['method']);
    assertSame('https://zc.test/api/v1/events/p1/bz/participants', $captured[0]['url']);
    assertFalse(str_contains($captured[0]['url'], platformToken()));
    assertSame([
        'Accept' => 'application/json',
        'X-API-Key' => 'bz-app-key-for-tests-0123456789',
        'X-BZ-Organization-Token' => platformToken(),
    ], $captured[0]['headers']);
    assertSame(10, $captured[0]['timeout']);
});

test('api client encodes the platform event id in the path', function (): void {
    platformRequire('PlatformApiClient');
    platformRequire('PlatformIntegrationConfig');
    $client = new PlatformApiClient(platformConfig());
    assertSame('https://zc.test/api/v1/events/a%2Fb%3Fc/bz/participants', $client->participantsUrl('a/b?c'));
    assertSame('https://zc.test/api/v1/events/p_1-x/bz/participants', $client->participantsUrl('p_1-x'));
});

test('api client maps platform responses to test statuses', function (): void {
    platformRequire('PlatformApiClient');
    platformRequire('PlatformIntegrationConfig');
    $cases = [
        ['ok', 2, ['status' => 200, 'body' => '{"success":true,"data":[{"registrationId":"r1"},{"registrationId":"r2"}]}']],
        ['invalid_response', null, ['status' => 200, 'body' => 'not json']],
        ['invalid_response', null, ['status' => 200, 'body' => '{"success":true}']],
        ['invalid_response', null, ['status' => 200, 'body' => '{"data":"x"}']],
        ['app_key_rejected', null, ['status' => 401, 'body' => '{}']],
        ['token_rejected', null, ['status' => 403, 'body' => '{}']],
        ['platform_event_not_found', null, ['status' => 404, 'body' => '{}']],
        ['platform_rate_limited', null, ['status' => 429, 'body' => '{}']],
        ['platform_error', null, ['status' => 500, 'body' => 'oops']],
        ['platform_error', null, ['status' => 302, 'body' => '']],
        ['network_error', null, new RuntimeException('connect timeout to zc.test with ' . platformToken())],
    ];

    foreach ($cases as [$status, $count, $response]) {
        $captured = [];
        $client = new PlatformApiClient(platformConfig(), platformFakeTransport($captured, $response));
        $result = $client->probeParticipants('p1', platformToken());
        assertSame($status, $result['status']);
        assertSame($count, $result['participant_count']);
        assertSame(['status', 'participant_count', 'http_status'], array_keys($result));
    }
});

test('api client results never contain the token or response body', function (): void {
    platformRequire('PlatformApiClient');
    platformRequire('PlatformIntegrationConfig');
    $responses = [
        ['status' => 200, 'body' => '{"data":[],"echo":"' . platformToken() . '"}'],
        ['status' => 403, 'body' => 'BODY-MARKER ' . platformToken()],
        new RuntimeException('failure carrying ' . platformToken()),
    ];
    foreach ($responses as $response) {
        $captured = [];
        $client = new PlatformApiClient(platformConfig(), platformFakeTransport($captured, $response));
        $result = $client->probeParticipants('p1', platformToken());
        $json = json_encode($result);
        assertFalse(str_contains($json, platformToken()));
        assertFalse(str_contains($json, 'BODY-MARKER'));
        assertFalse(str_contains($json, 'bz-app-key-for-tests'));
    }
});

test('test throttle enforces ten seconds between tests', function (): void {
    platformRequire('PlatformConnectionTestThrottle');
    $now = new DateTimeImmutable('2026-10-05 10:00:10', new DateTimeZone('UTC'));

    assertSame(0, PlatformConnectionTestThrottle::secondsUntilAllowed(null, $now));
    assertSame(0, PlatformConnectionTestThrottle::secondsUntilAllowed('2026-10-05 09:00:00', $now));
    assertSame(0, PlatformConnectionTestThrottle::secondsUntilAllowed('2026-10-05 10:00:00', $now));
    assertSame(1, PlatformConnectionTestThrottle::secondsUntilAllowed('2026-10-05 10:00:01', $now));
    assertSame(10, PlatformConnectionTestThrottle::secondsUntilAllowed('2026-10-05 10:00:10', $now));
    assertSame(7, PlatformConnectionTestThrottle::secondsUntilAllowed('2026-10-05 10:00:07', $now));
    assertSame(0, PlatformConnectionTestThrottle::secondsUntilAllowed('garbage', $now));
    // A last test in the future (clock skew) never blocks for more than the interval.
    assertSame(10, PlatformConnectionTestThrottle::secondsUntilAllowed('2026-10-05 11:00:00', $now));
});

test('masks platform token ciphertext in the database viewer', function (): void {
    assertTrue(isSensitiveDatabaseColumn('organization_token_ciphertext'));
    assertTrue(isSensitiveDatabaseColumn('Organization_Token_Ciphertext'));
});

test('platform connection handlers check role, event and organization before anything else', function (): void {
    $source = platformHandlersSource();
    $handlers = preg_split("/(?=if \(preg_match\('#\^\/events\/\(\[\^\/\]\+\)\/platform-connection)/", $source, -1, PREG_SPLIT_NO_EMPTY);
    assertSame(4, count($handlers), 'Oczekiwano 4 handlerow, jest ' . count($handlers));

    foreach ($handlers as $handler) {
        $role = strpos($handler, "requireAnyRole(['superadmin', 'admin', 'editor']");
        $event = strpos($handler, '$loadEventById');
        $org = strpos($handler, '$canManageEventParticipants');
        $config = strpos($handler, 'PlatformIntegrationConfig::fromProcess');
        $repo = strpos($handler, 'PlatformConnectionRepository');
        assertTrue($role !== false && $event !== false && $org !== false, 'brak kontroli dostepu');
        assertTrue($role < $event && $event < $org, 'zla kolejnosc kontroli dostepu');
        foreach ([$config, $repo] as $later) {
            if ($later !== false) {
                assertTrue($org < $later, 'logika przed kontrola dostepu');
            }
        }
    }
});

test('platform connection handlers respond only through the presenter', function (): void {
    $source = platformHandlersSource();

    assertStringContains('PlatformConnectionPresenter::present(', $source);
    assertSame(0, preg_match('/jsonResponse\([^;]*organization_token_ciphertext/s', $source));
    assertSame(0, preg_match('/jsonResponse\([^;]*\$token\b/s', $source));
});

test('migration 028 is portable and idempotent by construction', function (): void {
    $path = __DIR__ . '/../database/migrations/028_add_event_platform_connections.php';
    assertTrue(is_file($path), 'Missing migration 028');
    $sql = (string)file_get_contents($path);

    assertStringContains('CREATE TABLE IF NOT EXISTS event_platform_connections', $sql);
    assertStringContains('uq_event_platform_connections_platform_event_id', $sql);
    assertStringContains('ON DELETE CASCADE', $sql);
    assertSame(0, preg_match('/ADD\s+(COLUMN|CONSTRAINT|INDEX|KEY)[^;]*IF NOT EXISTS/i', $sql));
    assertFalse(str_contains($sql, 'VALUES('));
});

test('CORS allows PUT because the platform connection is saved with PUT', function (): void {
    $source = (string)file_get_contents(__DIR__ . '/../src/bootstrap.php');
    assertSame(1, preg_match('/Access-Control-Allow-Methods: [A-Z, ]*\bPUT\b/', $source));
    $index = (string)file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(str_contains($index, "\$method === 'PUT'"), 'PUT route expected');
});
