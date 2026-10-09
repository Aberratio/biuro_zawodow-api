<?php

declare(strict_types=1);

/**
 * Decides whether a webhook may touch an event. The organization token is the only credential the platform
 * sends, so the comparison always runs exactly once, whatever else is wrong (no timing oracle).
 */
final class PlatformWebhookAuthenticator
{
    private const DUMMY_SECRET = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param ?array<string, mixed> $connection event_platform_connections row for the platform event id
     * @param null|callable(string, string): bool $compare injectable for tests
     * @return 'ok'|'unknown_event'|'connection_disabled'|'token_unreadable'|'token_mismatch'
     */
    public static function decide(?array $connection, ?string $decryptedToken, string $presentedToken, ?callable $compare = null): string
    {
        $compare ??= static fn (string $expected, string $given): bool => hash_equals($expected, $given);
        $enabled = $connection !== null && (int)($connection['is_enabled'] ?? 0) === 1;
        $expected = $enabled && $decryptedToken !== null && $decryptedToken !== '' ? $decryptedToken : self::DUMMY_SECRET;

        $matches = $compare($expected, $presentedToken);

        if ($connection === null) {
            return 'unknown_event';
        }
        if (!$enabled) {
            return 'connection_disabled';
        }
        if ($decryptedToken === null || $decryptedToken === '') {
            return 'token_unreadable';
        }

        return $matches ? 'ok' : 'token_mismatch';
    }
}
