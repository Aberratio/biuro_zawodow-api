<?php

declare(strict_types=1);

final class RequestContext
{
    private static string $requestId = '';
    private static float $startedAt = 0.0;
    private static ?array $user = null;

    public static function initialize(): void
    {
        if (self::$requestId !== '') {
            return;
        }

        $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming) === 1
            ? $incoming
            : 'req-' . bin2hex(random_bytes(12));
        self::$startedAt = microtime(true);

        if (!headers_sent()) {
            header('X-Request-ID: ' . self::$requestId);
        }
    }

    public static function id(): string
    {
        self::initialize();
        return self::$requestId;
    }

    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
    }

    public static function path(): string
    {
        return (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    }

    public static function durationMs(): int
    {
        self::initialize();
        return max(0, (int)round((microtime(true) - self::$startedAt) * 1000));
    }
}
