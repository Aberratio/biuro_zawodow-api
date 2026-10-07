<?php

declare(strict_types=1);

/** Name of the per-event database lock that serialises platform participant writes. */
final class PlatformPullLock
{
    public static function name(string $eventId): string
    {
        return 'bz_platform_pull_' . substr(sha1($eventId), 0, 32);
    }
}
