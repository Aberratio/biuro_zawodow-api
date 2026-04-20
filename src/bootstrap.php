<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/QrCodeService.php';

$composerAutoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoloadPath)) {
    require_once $composerAutoloadPath;
}

loadEnv(__DIR__ . '/../.env');

function loadEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '') {
            continue;
        }

        $value = trim($value, "\"'");

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        http_response_code(500);
        echo '{"error":"Nie udało się zakodować odpowiedzi"}';
        return;
    }

    echo $encoded;
}

function htmlResponse(int $statusCode, string $html): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

function readJsonBody(int $maxBytes = 262144): array
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > $maxBytes) {
        jsonResponse(413, ['error' => 'Treść żądania jest zbyt duża']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        return [];
    }

    if (strlen($rawBody) > $maxBytes) {
        jsonResponse(413, ['error' => 'Treść żądania jest zbyt duża']);
        exit;
    }

    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        jsonResponse(400, ['error' => 'Nieprawidłowa treść JSON']);
        exit;
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        jsonResponse(400, ['error' => 'Treść JSON musi być obiektem']);
        exit;
    }

    return $decoded;
}

function parseLocalDateTimeString(string $value): ?DateTimeImmutable
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $formats = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i'];
    foreach ($formats as $format) {
        $dateTime = DateTimeImmutable::createFromFormat($format, $trimmed);
        if ($dateTime !== false && $dateTime->format($format) === $trimmed) {
            return $dateTime;
        }
    }

    return null;
}

function isValidLocalDateTimeString(string $value): bool
{
    return parseLocalDateTimeString($value) !== null;
}

function normalizeLocalDateTimeString(string $value): ?string
{
    $dateTime = parseLocalDateTimeString($value);
    return $dateTime?->format('Y-m-d H:i:s');
}

function setCorsHeaders(): void
{
    $allowedOriginsRaw = getenv('APP_CORS_ORIGIN') ?: '*';
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($allowedOriginsRaw === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $allowedOrigins = array_map('trim', explode(',', $allowedOriginsRaw));
        $allowOrigin = in_array($requestOrigin, $allowedOrigins, true)
            ? $requestOrigin
            : $allowedOrigins[0];
        header('Access-Control-Allow-Origin: ' . $allowOrigin);
    }

    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function appEnvironment(): string
{
    return strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
}

function isLocalEnvironment(): bool
{
    return in_array(appEnvironment(), ['local', 'development', 'dev', 'test'], true);
}

function clientIpAddress(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
}

function validateServerSecurityConfiguration(): void
{
    $appKey = trim((string)(getenv('APP_KEY') ?: ''));
    $allowedOrigins = trim((string)(getenv('APP_CORS_ORIGIN') ?: '*'));
    $placeholderKeys = [
        '',
        'change-me-in-env',
        'change-this-local-secret',
        'local-dev-change-this-secret',
        'secret',
        'changeme',
    ];

    $isWeakAppKey = strlen($appKey) < 32 || in_array(strtolower($appKey), $placeholderKeys, true);
    if (!isLocalEnvironment() && $isWeakAppKey) {
        jsonResponse(500, ['error' => 'Błąd konfiguracji serwera']);
        exit;
    }

    if (!isLocalEnvironment() && $allowedOrigins === '*') {
        jsonResponse(500, ['error' => 'Błąd konfiguracji serwera']);
        exit;
    }
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode(string $value): string|false
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

function authSecret(): string
{
    return getenv('APP_KEY') ?: 'change-me-in-env';
}

function isPasswordHash(string $value): bool
{
    return password_get_info($value)['algo'] !== null;
}

function passwordMatches(string $plainPassword, string $storedPassword): bool
{
    if (isPasswordHash($storedPassword)) {
        return password_verify($plainPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $plainPassword);
}

function isValidEmailAddress(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function normalizeEmailAddress(string $email): string
{
    return strtolower(trim($email));
}

function splitParticipantDisplayName(string $displayName): array
{
    $parts = preg_split('/\s+/', trim($displayName)) ?: [];
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

    if ($parts === []) {
        return ['first_name' => null, 'last_name' => null];
    }

    if (count($parts) === 1) {
        return ['first_name' => $parts[0], 'last_name' => null];
    }

    return [
        'first_name' => $parts[0],
        'last_name' => implode(' ', array_slice($parts, 1)),
    ];
}

function decodeJsonObject(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function validatePasswordRules(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Hasło musi mieć co najmniej 10 znaków';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Hasło musi zawierać co najmniej jedną wielką literę';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Hasło musi zawierać co najmniej jedną małą literę';
    }

    if (!preg_match('/\d/', $password)) {
        return 'Hasło musi zawierać co najmniej jedną cyfrę';
    }

    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return 'Hasło musi zawierać co najmniej jeden znak specjalny';
    }

    return null;
}

function appFrontendUrl(): string
{
    $fallbackUrl = getenv('APP_URL') ?: 'http://localhost:8080';

    return rtrim(getenv('APP_FRONTEND_URL') ?: $fallbackUrl, '/');
}

function appUrl(): string
{
    return rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/');
}

function qrCodeImageUrl(string $token): string
{
    return appUrl() . '/qr-images/' . rawurlencode($token) . '.svg';
}

function passwordResetTokenHash(string $token): string
{
    return hash_hmac('sha256', $token, authSecret());
}

function passwordResetExpiresAt(): string
{
    return gmdate('Y-m-d H:i:s', time() + 24 * 60 * 60);
}

function forgotPasswordSuccessMessage(): string
{
    return 'Jeśli konto istnieje, wysłaliśmy link do resetu hasła.';
}

function issueAuthToken(array $user): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => (string)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'organization_id' => $user['organization_id'],
        'assigned_events' => $user['assigned_events'] ?? [],
        'iat' => time(),
        'exp' => time() + 8 * 60 * 60,
    ];

    $encodedHeader = base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedPayload = base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, authSecret(), true);

    return $encodedHeader . '.' . $encodedPayload . '.' . base64UrlEncode($signature);
}

function getBearerToken(): ?string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function authenticatedUserFromRequest(?callable $resolver = null): ?array
{
    $token = getBearerToken();
    if ($token === null) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $expectedSignature = base64UrlEncode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, authSecret(), true));
    if (!hash_equals($expectedSignature, $encodedSignature)) {
        return null;
    }

    $payloadJson = base64UrlDecode($encodedPayload);
    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }

    $user = [
        'id' => (string)($payload['sub'] ?? ''),
        'name' => (string)($payload['name'] ?? ''),
        'email' => (string)($payload['email'] ?? ''),
        'role' => (string)($payload['role'] ?? ''),
        'organization_id' => $payload['organization_id'] ?? null,
        'assigned_events' => is_array($payload['assigned_events'] ?? null) ? $payload['assigned_events'] : [],
    ];

    if ($user['id'] === '' || $user['role'] === '') {
        return null;
    }

    if ($resolver !== null) {
        $resolvedUser = $resolver($user);
        return is_array($resolvedUser) ? $resolvedUser : null;
    }

    return $user;
}

function requireAuth(?callable $resolver = null): array
{
    $user = authenticatedUserFromRequest($resolver);
    if ($user === null) {
        jsonResponse(401, ['error' => 'Brak autoryzacji']);
        exit;
    }

    return $user;
}

function requireAnyRole(array $allowedRoles, ?callable $resolver = null): array
{
    $user = requireAuth($resolver);
    if (!in_array($user['role'], $allowedRoles, true)) {
        jsonResponse(403, ['error' => 'Brak uprawnień']);
        exit;
    }

    return $user;
}

function openApiDocument(): array
{
    $baseUrl = getenv('APP_URL') ?: 'http://localhost:8080';

    return [
        'openapi' => '3.0.3',
        'info' => [
            'title' => 'biuro_zawodow API',
            'version' => '1.1.0',
            'description' => 'API do zarządzania biurem zawodów, wydarzeniami, użytkownikami, uczestnikami i operacjami importu / eksportu.',
        ],
        'servers' => [
            [
                'url' => $baseUrl,
            ],
        ],
        'tags' => [
            ['name' => 'System'],
            ['name' => 'Auth'],
            ['name' => 'Bootstrap'],
            ['name' => 'Organizations'],
            ['name' => 'Events'],
            ['name' => 'Users'],
            ['name' => 'Participants'],
        ],
        'paths' => [
            '/auth/login' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Authenticate user and receive bearer token',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/LoginRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Authenticated successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/LoginResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                        '429' => [
                            '$ref' => '#/components/responses/TooManyRequests',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'email and password are required',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                    ],
                ],
            ],
            '/auth/forgot-password' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Request password reset email',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ForgotPasswordRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Neutral response for existing and non-existing accounts',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/MessageResponse',
                                    ],
                                ],
                            ],
                        ],
                        '429' => [
                            '$ref' => '#/components/responses/TooManyRequests',
                        ],
                    ],
                ],
            ],
            '/auth/reset-password' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Reset password with one-time token',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ResetPasswordRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Password reset completed',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/MessageResponse',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'Validation error or invalid token',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '429' => [
                            '$ref' => '#/components/responses/TooManyRequests',
                        ],
                    ],
                ],
            ],
            '/auth/me' => [
                'get' => [
                    'tags' => ['Auth'],
                    'summary' => 'Get current authenticated user',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Current authenticated user',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/CurrentUserResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/auth/change-password' => [
                'post' => [
                    'tags' => ['Auth'],
                    'summary' => 'Change password for current user',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ChangePasswordRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Password changed',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/MessageResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/health' => [
                'get' => [
                    'tags' => ['System'],
                    'summary' => 'Health check',
                    'responses' => [
                        '200' => [
                            'description' => 'API and database are reachable',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/HealthResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                    ],
                ],
            ],
            '/qr-images/{token}.svg' => [
                'get' => [
                    'tags' => ['Participants'],
                    'summary' => 'Render participant QR code as SVG',
                    'parameters' => [
                        [
                            'name' => 'token',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'QR code image',
                            'content' => [
                                'image/svg+xml' => [
                                    'schema' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                    ],
                ],
            ],
            '/bootstrap' => [
                'get' => [
                    'tags' => ['Bootstrap'],
                    'summary' => 'Load bootstrap data for the frontend',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Organizations, events, users, participants and activity log',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/BootstrapResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events' => [
                'post' => [
                    'tags' => ['Events'],
                    'summary' => 'Create event with organization limit enforcement',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/CreateEventRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Event created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/EventResponse',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'Validation error or organization limit reached',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}' => [
                'get' => [
                    'tags' => ['Events'],
                    'summary' => 'Get event by ID',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Event found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/EventResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'patch' => [
                    'tags' => ['Events'],
                    'summary' => 'Update event',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/UpdateEventRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Event updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/EventResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'delete' => [
                    'tags' => ['Events'],
                    'summary' => 'Delete event with all its participants',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Event deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/SuccessResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/export.csv' => [
                'get' => [
                    'tags' => ['Events'],
                    'summary' => 'Export event participants to CSV',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'CSV export',
                            'content' => [
                                'text/csv' => [
                                    'schema' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/logs/export.csv' => [
                'get' => [
                    'tags' => ['Events'],
                    'summary' => 'Export event activity logs to CSV',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'CSV export',
                            'content' => [
                                'text/csv' => [
                                    'schema' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/participant-imports/analyze' => [
                'post' => [
                    'tags' => ['Events'],
                    'summary' => 'Analyze participant CSV before import',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ParticipantImportAnalyzeRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'CSV analysis result',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantImportAnalyzeResponse',
                                    ],
                                ],
                            ],
                        ],
                        '400' => [
                            '$ref' => '#/components/responses/BadRequest',
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '413' => [
                            '$ref' => '#/components/responses/PayloadTooLarge',
                        ],
                        '422' => [
                            'description' => 'Invalid CSV payload',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/participant-imports/confirm' => [
                'post' => [
                    'tags' => ['Events'],
                    'summary' => 'Save participant CSV field mapping for event',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ParticipantImportConfirmRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Mapping saved',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantImportConfirmResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '409' => [
                            '$ref' => '#/components/responses/Conflict',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/participant-imports/run' => [
                'post' => [
                    'tags' => ['Events'],
                    'summary' => 'Import participants from CSV using saved mapping',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ParticipantImportAnalyzeRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Import summary',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantImportRunResponse',
                                    ],
                                ],
                            ],
                        ],
                        '400' => [
                            '$ref' => '#/components/responses/BadRequest',
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '413' => [
                            '$ref' => '#/components/responses/PayloadTooLarge',
                        ],
                        '422' => [
                            'description' => 'Import validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/participant-field-mappings' => [
                'get' => [
                    'tags' => ['Events'],
                    'summary' => 'Get saved participant field mapping for event',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Current mapping',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantFieldMappingsResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/participants/manual' => [
                'post' => [
                    'tags' => ['Events'],
                    'summary' => 'Create participant manually using saved event mapping',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ManualParticipantRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Participant created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/events/{id}/send-qr-emails' => [
                'post' => [
                    'tags' => ['Events'],
                    'summary' => 'Send QR emails for event participants',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/EventQrEmailRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Email sending summary',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/EventQrEmailResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/organizations/{id}/event-limit' => [
                'post' => [
                    'tags' => ['Organizations'],
                    'summary' => 'Update organization event limit',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/UpdateOrganizationEventLimitRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Organization limit updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/OrganizationResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Organization not found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/organizations/{id}' => [
                'get' => [
                    'tags' => ['Organizations'],
                    'summary' => 'Get organization by ID',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Organization found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/OrganizationResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'patch' => [
                    'tags' => ['Organizations'],
                    'summary' => 'Update organization',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/UpdateOrganizationRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Organization updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/OrganizationResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '409' => [
                            '$ref' => '#/components/responses/Conflict',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'delete' => [
                    'tags' => ['Organizations'],
                    'summary' => 'Delete organization when it has no events or assigned users',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Organization deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/SuccessResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Organization cannot be deleted yet',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/organizations' => [
                'post' => [
                    'tags' => ['Organizations'],
                    'summary' => 'Create organization',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/CreateOrganizationRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Organization created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/OrganizationResponse',
                                    ],
                                ],
                            ],
                        ],
                        '409' => [
                            '$ref' => '#/components/responses/Conflict',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/users' => [
                'post' => [
                    'tags' => ['Users'],
                    'summary' => 'Create user',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/CreateUserRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'User created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/UserResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'Brak uprawnień',
                                    ],
                                ],
                            ],
                        ],
                        '409' => [
                            'description' => 'Email already exists',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'User with this email already exists',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'name, email and role are required',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/users/{id}/role' => [
                'patch' => [
                    'tags' => ['Users'],
                    'summary' => 'Change user role',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ChangeUserRoleRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Role updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/UserResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'User not found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/users/{id}/event-assignments' => [
                'patch' => [
                    'tags' => ['Users'],
                    'summary' => 'Assign scanner to selected events',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/AssignScannerEventsRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Assignments updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/UserResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/users/{id}' => [
                'delete' => [
                    'tags' => ['Users'],
                    'summary' => 'Archive user',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'User archived',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/SuccessResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'User not found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'User cannot be archived',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/users/{id}/password-reset' => [
                'post' => [
                    'tags' => ['Users'],
                    'summary' => 'Send password reset email for user',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Password reset email sent',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/SuccessResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Brak uprawnień',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'User not found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '422' => [
                            'description' => 'Password reset cannot be sent',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants' => [
                'get' => [
                    'tags' => ['Participants'],
                    'summary' => 'List participants',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participants sorted by newest first',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantsResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'post' => [
                    'tags' => ['Participants'],
                    'summary' => 'Create participant',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/CreateParticipantRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Participant created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'event_id, display_name and email are required',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants/scan' => [
                'post' => [
                    'tags' => ['Participants'],
                    'summary' => 'Scan participant QR code',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ParticipantScanRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participant scan result',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantScanResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants/{id}/qr-preview' => [
                'get' => [
                    'tags' => ['Participants'],
                    'summary' => 'Get QR preview data for participant',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'QR preview payload',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantQrPreviewResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Participant is not assigned to an accessible event',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants/{id}/send-qr-email' => [
                'post' => [
                    'tags' => ['Participants'],
                    'summary' => 'Send QR email to a single participant',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'QR email sent',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Mail send failed or participant is invalid',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants/{id}' => [
                'get' => [
                    'tags' => ['Participants'],
                    'summary' => 'Get participant by ID',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participant found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Participant is not assigned to an accessible event',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'patch' => [
                    'tags' => ['Participants'],
                    'summary' => 'Update participant status or participant data',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/UpdateParticipantRequest',
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participant updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
                'delete' => [
                    'tags' => ['Participants'],
                    'summary' => 'Delete participant',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participant deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/SuccessResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Participant is not assigned to an accessible event',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants/{id}/check-in' => [
                'post' => [
                    'tags' => ['Participants'],
                    'summary' => 'Check in participant',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participant checked in',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Participant is not assigned to an accessible event',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
            '/participants/{id}/undo-check-in' => [
                'post' => [
                    'tags' => ['Participants'],
                    'summary' => 'Undo participant check-in',
                    'security' => [
                        ['bearerAuth' => []],
                    ],
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'description' => 'Participant numeric ID',
                            'schema' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Participant check-in reverted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ParticipantResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            '$ref' => '#/components/responses/Brak uprawnień',
                        ],
                        '404' => [
                            '$ref' => '#/components/responses/NotFound',
                        ],
                        '422' => [
                            'description' => 'Participant is not assigned to an accessible event',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Brak autoryzacji',
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
            ],
            'responses' => [
                'DatabaseError' => [
                    'description' => 'Database error',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/DatabaseErrorResponse',
                            ],
                        ],
                    ],
                ],
                'Brak autoryzacji' => [
                    'description' => 'Brak autoryzacji',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                            'example' => [
                                'error' => 'Brak autoryzacji',
                            ],
                        ],
                    ],
                ],
                'Brak uprawnień' => [
                    'description' => 'Brak uprawnień',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                            'example' => [
                                'error' => 'Brak uprawnień',
                            ],
                        ],
                    ],
                ],
                'NotFound' => [
                    'description' => 'Resource not found',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                        ],
                    ],
                ],
                'BadRequest' => [
                    'description' => 'Invalid JSON payload or malformed request',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                        ],
                    ],
                ],
                'PayloadTooLarge' => [
                    'description' => 'Treść żądania jest zbyt duża',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                        ],
                    ],
                ],
                'TooManyRequests' => [
                    'description' => 'Rate limit exceeded',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                        ],
                    ],
                ],
                'Conflict' => [
                    'description' => 'Conflict',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                        ],
                    ],
                ],
            ],
            'schemas' => [
                'LoginRequest' => [
                    'type' => 'object',
                    'required' => ['email', 'password'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'super@biurozawodow.pl'],
                        'password' => ['type' => 'string', 'format' => 'password', 'example' => 'demo123'],
                    ],
                ],
                'LoginResponse' => [
                    'type' => 'object',
                    'required' => ['token_type', 'access_token', 'expires_in', 'user'],
                    'properties' => [
                        'token_type' => ['type' => 'string', 'example' => 'Bearer'],
                        'access_token' => ['type' => 'string'],
                        'expires_in' => ['type' => 'integer', 'example' => 28800],
                        'user' => ['$ref' => '#/components/schemas/User'],
                    ],
                ],
                'ForgotPasswordRequest' => [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'super@biurozawodow.pl'],
                    ],
                ],
                'ResetPasswordRequest' => [
                    'type' => 'object',
                    'required' => ['token', 'password', 'password_confirmation'],
                    'properties' => [
                        'token' => ['type' => 'string'],
                        'password' => ['type' => 'string', 'format' => 'password'],
                        'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                    ],
                ],
                'ChangePasswordRequest' => [
                    'type' => 'object',
                    'required' => ['current_password', 'new_password', 'new_password_confirmation'],
                    'properties' => [
                        'current_password' => ['type' => 'string', 'format' => 'password'],
                        'new_password' => ['type' => 'string', 'format' => 'password'],
                        'new_password_confirmation' => ['type' => 'string', 'format' => 'password'],
                    ],
                ],
                'MessageResponse' => [
                    'type' => 'object',
                    'required' => ['message'],
                    'properties' => [
                        'message' => ['type' => 'string'],
                    ],
                ],
                'CurrentUserResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => ['$ref' => '#/components/schemas/User'],
                    ],
                ],
                'HealthResponse' => [
                    'type' => 'object',
                    'required' => ['status', 'service', 'timestamp'],
                    'properties' => [
                        'status' => ['type' => 'string', 'example' => 'ok'],
                        'service' => ['type' => 'string', 'example' => 'biuro-zawodow-api'],
                        'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ],
                'BootstrapResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['organizations', 'events', 'users', 'participants', 'activityLog'],
                            'properties' => [
                                'organizations' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Organization'],
                                ],
                                'events' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Event'],
                                ],
                                'users' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/User'],
                                ],
                                'participants' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Participant'],
                                ],
                                'activityLog' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/ActivityLogEntry'],
                                ],
                            ],
                        ],
                    ],
                ],
                'ParticipantsResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/Participant'],
                        ],
                    ],
                ],
                'ParticipantResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            '$ref' => '#/components/schemas/Participant',
                        ],
                    ],
                ],
                'DatabaseErrorResponse' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => ['type' => 'string', 'example' => 'Database error'],
                        'details' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'ErrorResponse' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => ['type' => 'string'],
                        'details' => ['type' => 'string', 'nullable' => true],
                        'retry_after' => ['type' => 'integer', 'nullable' => true],
                        'missing_fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'nullable' => true,
                        ],
                        'missing_columns' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'nullable' => true,
                        ],
                        'data' => [
                            'type' => 'object',
                            'nullable' => true,
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'CreateParticipantRequest' => [
                    'type' => 'object',
                    'required' => ['event_id', 'display_name', 'email'],
                    'properties' => [
                        'event_id' => ['type' => 'string', 'example' => 'evt-1'],
                        'first_name' => ['type' => 'string', 'nullable' => true, 'example' => 'Maria'],
                        'last_name' => ['type' => 'string', 'nullable' => true, 'example' => 'Wisniewska'],
                        'display_name' => ['type' => 'string', 'example' => 'Maria Wiśniewska'],
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'maria.wisniewska@example.com'],
                        'organization' => ['type' => 'string', 'nullable' => true, 'example' => 'AGH'],
                        'bib_number' => ['type' => 'string', 'nullable' => true, 'example' => '101'],
                        'qr_code' => ['type' => 'string', 'nullable' => true, 'example' => 'QR-evt-1-101'],
                    ],
                ],
                'Organization' => [
                    'type' => 'object',
                    'required' => ['id', 'name', 'event_limit'],
                    'properties' => [
                        'id' => ['type' => 'string', 'example' => 'org-1'],
                        'name' => ['type' => 'string', 'example' => 'SportEvents Pro'],
                        'logo' => ['type' => 'string', 'nullable' => true],
                        'event_limit' => ['type' => 'integer', 'example' => 4],
                    ],
                ],
                'OrganizationResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            '$ref' => '#/components/schemas/Organization',
                        ],
                    ],
                ],
                'Event' => [
                    'type' => 'object',
                    'required' => ['id', 'name', 'location', 'organization_id', 'office_open_at', 'office_close_at'],
                    'properties' => [
                        'id' => ['type' => 'string', 'example' => 'evt-1'],
                        'name' => ['type' => 'string', 'example' => 'Bieg Piastowski 10km'],
                        'location' => ['type' => 'string', 'example' => 'Gniezno, Park Miejski'],
                        'organization_id' => ['type' => 'string', 'example' => 'org-1'],
                        'office_open_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-04-12T07:00:00'],
                        'office_close_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-04-12T15:00:00'],
                    ],
                ],
                'EventResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            '$ref' => '#/components/schemas/Event',
                        ],
                    ],
                ],
                'CreateEventRequest' => [
                    'type' => 'object',
                    'required' => ['name', 'location', 'organization_id', 'office_open_at', 'office_close_at'],
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Bieg Jesienny 5km'],
                        'location' => ['type' => 'string', 'example' => 'Krakow, Blonia'],
                        'organization_id' => ['type' => 'string', 'example' => 'org-1'],
                        'office_open_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-20T08:00:00'],
                        'office_close_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-20T16:00:00'],
                    ],
                ],
                'UpdateOrganizationEventLimitRequest' => [
                    'type' => 'object',
                    'required' => ['event_limit'],
                    'properties' => [
                        'event_limit' => ['type' => 'integer', 'minimum' => 0, 'example' => 6],
                    ],
                ],
                'CreateOrganizationRequest' => [
                    'type' => 'object',
                    'required' => ['name', 'event_limit'],
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'City Runners'],
                        'event_limit' => ['type' => 'integer', 'minimum' => 0, 'example' => 3],
                    ],
                ],
                'ChangeUserRoleRequest' => [
                    'type' => 'object',
                    'required' => ['role'],
                    'properties' => [
                        'role' => ['type' => 'string', 'enum' => ['editor', 'scanner', 'scanner_plus']],
                    ],
                ],
                'User' => [
                    'type' => 'object',
                    'required' => ['id', 'name', 'email', 'role', 'assigned_events'],
                    'properties' => [
                        'id' => ['type' => 'string', 'example' => 'u-0'],
                        'name' => ['type' => 'string', 'example' => 'Super Admin'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'role' => ['type' => 'string', 'enum' => ['superadmin', 'admin', 'editor', 'scanner', 'scanner_plus']],
                        'organization_id' => ['type' => 'string', 'nullable' => true],
                        'assigned_events' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'UserResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            '$ref' => '#/components/schemas/User',
                        ],
                    ],
                ],
                'CreateUserRequest' => [
                    'type' => 'object',
                    'required' => ['name', 'email', 'role'],
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Admin New Org'],
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'admin.new@example.com'],
                        'role' => ['type' => 'string', 'enum' => ['admin', 'editor', 'scanner', 'scanner_plus']],
                        'organization_id' => ['type' => 'string', 'nullable' => true, 'example' => 'org-1'],
                        'assigned_events' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'Participant' => [
                    'type' => 'object',
                    'required' => ['id', 'display_name', 'email', 'status', 'email_status', 'created_at', 'updated_at'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'format' => 'int64', 'example' => 1],
                        'event_id' => ['type' => 'string', 'nullable' => true, 'example' => 'evt-1'],
                        'first_name' => ['type' => 'string', 'nullable' => true, 'example' => 'Anna'],
                        'last_name' => ['type' => 'string', 'nullable' => true, 'example' => 'Kowalska'],
                        'display_name' => ['type' => 'string', 'example' => 'Anna Kowalska'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'organization' => ['type' => 'string', 'nullable' => true],
                        'bib_number' => ['type' => 'string', 'nullable' => true],
                        'qr_code' => ['type' => 'string', 'nullable' => true],
                        'custom_fields' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        'status' => ['type' => 'string', 'enum' => ['not_checked_in', 'checked_in', 'checked_in_not_starting']],
                        'email_status' => ['type' => 'string', 'enum' => ['not_sent', 'sent']],
                        'checked_in_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ],
                'ActivityLogEntry' => [
                    'type' => 'object',
                    'required' => ['id', 'timestamp', 'action'],
                    'properties' => [
                        'id' => ['type' => 'string', 'example' => 'log-1'],
                        'event_id' => ['type' => 'string', 'nullable' => true, 'example' => 'evt-1'],
                        'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                        'action' => ['type' => 'string', 'example' => 'Check-in'],
                        'participant_name' => ['type' => 'string', 'nullable' => true],
                        'user_name' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'ParticipantFieldMapping' => [
                    'type' => 'object',
                    'required' => ['source_column_name', 'alias', 'field_role', 'display_order', 'is_required', 'is_active'],
                    'properties' => [
                        'source_column_name' => ['type' => 'string', 'example' => 'Imię'],
                        'alias' => ['type' => 'string', 'example' => 'Imię'],
                        'field_role' => ['type' => 'string', 'enum' => ['email', 'display_name_part', 'bib_number', 'custom']],
                        'display_order' => ['type' => 'integer', 'example' => 1],
                        'is_required' => ['type' => 'boolean'],
                        'is_active' => ['type' => 'boolean'],
                    ],
                ],
                'UpdateEventRequest' => [
                    'type' => 'object',
                    'required' => ['name', 'location', 'office_open_at', 'office_close_at'],
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Bieg Jesienny 5km'],
                        'location' => ['type' => 'string', 'example' => 'Krakow, Blonia'],
                        'office_open_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-20T08:00:00'],
                        'office_close_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-20T16:00:00'],
                    ],
                ],
                'UpdateOrganizationRequest' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'SportEvents Pro Plus'],
                        'event_limit' => ['type' => 'integer', 'minimum' => 0, 'example' => 6],
                    ],
                ],
                'AssignScannerEventsRequest' => [
                    'type' => 'object',
                    'required' => ['assigned_events'],
                    'properties' => [
                        'assigned_events' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'example' => 'evt-1'],
                        ],
                    ],
                ],
                'UpdateParticipantRequest' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['not_checked_in', 'checked_in', 'checked_in_not_starting']],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'bib_number' => ['type' => 'string', 'nullable' => true],
                        'bib_number_conflict_resolution' => ['type' => 'string', 'enum' => ['keep_duplicates', 'delete_conflicts']],
                        'field_values' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                ],
                'ParticipantQrPreviewResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['participant', 'event', 'qr_code_svg_data_uri', 'qr_code_image_url'],
                            'properties' => [
                                'participant' => ['$ref' => '#/components/schemas/Participant'],
                                'event' => ['$ref' => '#/components/schemas/Event'],
                                'qr_code_svg_data_uri' => ['type' => 'string'],
                                'qr_code_image_url' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'ParticipantScanRequest' => [
                    'type' => 'object',
                    'required' => ['qr_code'],
                    'properties' => [
                        'qr_code' => ['type' => 'string', 'example' => 'QR-evt-1-101'],
                    ],
                ],
                'ParticipantScanParticipant' => [
                    'type' => 'object',
                    'required' => ['id', 'event_id', 'display_name', 'email', 'bib_number', 'status', 'email_status', 'qr_code'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'format' => 'int64'],
                        'event_id' => ['type' => 'string'],
                        'display_name' => ['type' => 'string'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'bib_number' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['not_checked_in', 'checked_in', 'checked_in_not_starting']],
                        'email_status' => ['type' => 'string', 'enum' => ['not_sent', 'sent']],
                        'checked_in_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        'qr_code' => ['type' => 'string'],
                    ],
                ],
                'ParticipantScanResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['participant', 'event', 'access'],
                            'properties' => [
                                'participant' => ['$ref' => '#/components/schemas/ParticipantScanParticipant'],
                                'event' => ['$ref' => '#/components/schemas/Event'],
                                'access' => [
                                    'type' => 'object',
                                    'required' => ['allowed'],
                                    'properties' => [
                                        'allowed' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'EventQrEmailRequest' => [
                    'type' => 'object',
                    'properties' => [
                        'resend_all' => ['type' => 'boolean', 'default' => false],
                    ],
                ],
                'EventQrEmailResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['sent_count', 'error_count', 'errors'],
                            'properties' => [
                                'sent_count' => ['type' => 'integer'],
                                'error_count' => ['type' => 'integer'],
                                'errors' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['participant_id', 'participant_name', 'error'],
                                        'properties' => [
                                            'participant_id' => ['type' => 'integer', 'format' => 'int64'],
                                            'participant_name' => ['type' => 'string'],
                                            'error' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'ParticipantImportAnalyzeRequest' => [
                    'type' => 'object',
                    'required' => ['csv_content'],
                    'properties' => [
                        'csv_content' => ['type' => 'string'],
                    ],
                ],
                'ParticipantImportAnalyzeResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['headers', 'sample_rows', 'email_candidates', 'has_mapping', 'mappings', 'missing_required_columns', 'row_count'],
                            'properties' => [
                                'headers' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'sample_rows' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => ['type' => 'string'],
                                    ],
                                ],
                                'email_candidates' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['column', 'matched_count'],
                                        'properties' => [
                                            'column' => ['type' => 'string'],
                                            'matched_count' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                                'has_mapping' => ['type' => 'boolean'],
                                'mappings' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/ParticipantFieldMapping'],
                                ],
                                'missing_required_columns' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'row_count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
                'ParticipantImportConfirmFieldInput' => [
                    'type' => 'object',
                    'required' => ['source_column_name', 'alias', 'field_role', 'is_active'],
                    'properties' => [
                        'source_column_name' => ['type' => 'string'],
                        'alias' => ['type' => 'string'],
                        'field_role' => ['type' => 'string', 'enum' => ['display_name_part', 'bib_number', 'custom']],
                        'is_active' => ['type' => 'boolean'],
                    ],
                ],
                'ParticipantImportConfirmRequest' => [
                    'type' => 'object',
                    'required' => ['csv_columns', 'email_column', 'fields'],
                    'properties' => [
                        'csv_columns' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'email_column' => ['type' => 'string'],
                        'fields' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/ParticipantImportConfirmFieldInput'],
                        ],
                    ],
                ],
                'ParticipantImportConfirmResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/ParticipantFieldMapping'],
                        ],
                    ],
                ],
                'ParticipantImportRunResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['created_count', 'duplicate_count', 'invalid_count', 'invalid_rows', 'participants'],
                            'properties' => [
                                'created_count' => ['type' => 'integer'],
                                'duplicate_count' => ['type' => 'integer'],
                                'invalid_count' => ['type' => 'integer'],
                                'invalid_rows' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                ],
                                'participants' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Participant'],
                                ],
                            ],
                        ],
                    ],
                ],
                'ParticipantFieldMappingsResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['has_mapping', 'mappings'],
                            'properties' => [
                                'has_mapping' => ['type' => 'boolean'],
                                'mappings' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/ParticipantFieldMapping'],
                                ],
                            ],
                        ],
                    ],
                ],
                'ManualParticipantRequest' => [
                    'type' => 'object',
                    'required' => ['email', 'field_values'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'field_values' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                ],
                'SuccessResponse' => [
                    'type' => 'object',
                    'required' => ['success'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => true],
                    ],
                ],
            ],
        ],
    ];
}

function swaggerUiPage(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>biuro_zawodow API Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html { box-sizing: border-box; overflow-y: scroll; }
        *, *::before, *::after { box-sizing: inherit; }
        body { margin: 0; background: #f5f7fb; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: '/openapi.json',
                dom_id: '#swagger-ui',
            });
        });
    </script>
</body>
</html>
HTML;
}
