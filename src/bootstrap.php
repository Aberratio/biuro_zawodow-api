<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

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
        echo '{"error":"Response encoding failed"}';
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

function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
}

function isValidDateString(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
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
        return 'Password must be at least 10 characters long';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter';
    }

    if (!preg_match('/\d/', $password)) {
        return 'Password must contain at least one digit';
    }

    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return 'Password must contain at least one special character';
    }

    return null;
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
        'organization_ids' => $user['organization_ids'] ?? [],
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
        'organization_ids' => is_array($payload['organization_ids'] ?? null) ? $payload['organization_ids'] : [],
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
        jsonResponse(401, ['error' => 'Unauthorized']);
        exit;
    }

    return $user;
}

function requireAnyRole(array $allowedRoles, ?callable $resolver = null): array
{
    $user = requireAuth($resolver);
    if (!in_array($user['role'], $allowedRoles, true)) {
        jsonResponse(403, ['error' => 'Forbidden']);
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
            'version' => '1.0.0',
            'description' => 'Minimal PHP API for local event office management and participant operations.',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                            'description' => 'Forbidden',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                            'description' => 'Forbidden',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Event not found',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                            'description' => 'Forbidden',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                            'description' => 'Forbidden',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'Forbidden',
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
                                        'error' => 'name, email, password and role are required',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Unauthorized',
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
                            'description' => 'Forbidden',
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
                    ],
                ],
            ],
            '/users/{id}' => [
                'delete' => [
                    'tags' => ['Users'],
                    'summary' => 'Delete user',
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
                            'description' => 'User deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/SuccessResponse',
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Forbidden',
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
                            '$ref' => '#/components/responses/Unauthorized',
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
                        '422' => [
                            'description' => 'Validation error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'first_name, last_name and email are required',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Unauthorized',
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
                        '404' => [
                            'description' => 'Participant not found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ErrorResponse',
                                    ],
                                    'example' => [
                                        'error' => 'Participant not found',
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            '$ref' => '#/components/responses/DatabaseError',
                        ],
                        '401' => [
                            '$ref' => '#/components/responses/Unauthorized',
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
                'Unauthorized' => [
                    'description' => 'Unauthorized',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                            'example' => [
                                'error' => 'Unauthorized',
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
                    ],
                ],
                'CreateParticipantRequest' => [
                    'type' => 'object',
                    'required' => ['first_name', 'last_name', 'email'],
                    'properties' => [
                        'event_id' => ['type' => 'string', 'nullable' => true, 'example' => 'evt-1'],
                        'first_name' => ['type' => 'string', 'example' => 'Maria'],
                        'last_name' => ['type' => 'string', 'example' => 'Wisniewska'],
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'maria.wisniewska@example.com'],
                        'organization' => ['type' => 'string', 'nullable' => true, 'example' => 'AGH'],
                        'bib_number' => ['type' => 'string', 'nullable' => true, 'example' => '101'],
                        'qr_code' => ['type' => 'string', 'nullable' => true, 'example' => 'QR-evt-1-101'],
                        'status' => ['type' => 'string', 'enum' => ['pending', 'checked_in'], 'default' => 'pending'],
                        'package_status' => ['type' => 'string', 'enum' => ['not_collected', 'collected'], 'default' => 'not_collected'],
                        'email_status' => ['type' => 'string', 'enum' => ['not_sent', 'sent'], 'default' => 'not_sent'],
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
                        'admin_user_id' => ['type' => 'string', 'nullable' => true, 'example' => 'u-1'],
                        'admin_user_name' => ['type' => 'string', 'nullable' => true, 'example' => 'Admin SportEvents'],
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
                    'required' => ['id', 'name', 'date', 'location', 'organization_id'],
                    'properties' => [
                        'id' => ['type' => 'string', 'example' => 'evt-1'],
                        'name' => ['type' => 'string', 'example' => 'Bieg Piastowski 10km'],
                        'date' => ['type' => 'string', 'format' => 'date'],
                        'location' => ['type' => 'string', 'example' => 'Gniezno, Park Miejski'],
                        'organization_id' => ['type' => 'string', 'example' => 'org-1'],
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
                    'required' => ['name', 'date', 'location', 'organization_id'],
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Bieg Jesienny 5km'],
                        'date' => ['type' => 'string', 'format' => 'date', 'example' => '2026-09-20'],
                        'location' => ['type' => 'string', 'example' => 'Krakow, Blonia'],
                        'organization_id' => ['type' => 'string', 'example' => 'org-1'],
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
                        'admin_user_id' => ['type' => 'string', 'nullable' => true, 'example' => 'u-1'],
                    ],
                ],
                'ChangeUserRoleRequest' => [
                    'type' => 'object',
                    'required' => ['role'],
                    'properties' => [
                        'role' => ['type' => 'string', 'enum' => ['admin', 'editor', 'scanner']],
                    ],
                ],
                'User' => [
                    'type' => 'object',
                    'required' => ['id', 'name', 'email', 'role', 'assigned_events'],
                    'properties' => [
                        'id' => ['type' => 'string', 'example' => 'u-0'],
                        'name' => ['type' => 'string', 'example' => 'Super Admin'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'role' => ['type' => 'string', 'enum' => ['superadmin', 'admin', 'editor', 'scanner']],
                        'organization_id' => ['type' => 'string', 'nullable' => true],
                        'organization_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
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
                    'required' => ['name', 'email', 'password', 'role'],
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'Admin New Org'],
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'admin.new@example.com'],
                        'password' => ['type' => 'string', 'format' => 'password', 'example' => 'demo123'],
                        'role' => ['type' => 'string', 'enum' => ['admin', 'editor', 'scanner']],
                        'organization_id' => ['type' => 'string', 'nullable' => true, 'example' => 'org-1'],
                        'organization_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'assigned_events' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'Participant' => [
                    'type' => 'object',
                    'required' => ['id', 'first_name', 'last_name', 'email', 'status', 'package_status', 'email_status', 'created_at', 'updated_at'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'format' => 'int64', 'example' => 1],
                        'event_id' => ['type' => 'string', 'nullable' => true, 'example' => 'evt-1'],
                        'first_name' => ['type' => 'string', 'example' => 'Anna'],
                        'last_name' => ['type' => 'string', 'example' => 'Kowalska'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'organization' => ['type' => 'string', 'nullable' => true],
                        'bib_number' => ['type' => 'string', 'nullable' => true],
                        'qr_code' => ['type' => 'string', 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['pending', 'checked_in']],
                        'package_status' => ['type' => 'string', 'enum' => ['not_collected', 'collected']],
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
                        'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                        'action' => ['type' => 'string', 'example' => 'Check-in'],
                        'participant_name' => ['type' => 'string', 'nullable' => true],
                        'user_name' => ['type' => 'string', 'nullable' => true],
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
