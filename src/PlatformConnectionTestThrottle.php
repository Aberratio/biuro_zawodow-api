<?php

declare(strict_types=1);

final class PlatformConnectionTestThrottle
{
    /** Seconds the caller still has to wait before another connection test is allowed (0 = allowed now). */
    public static function secondsUntilAllowed(?string $lastTestAtUtc, DateTimeImmutable $nowUtc, int $minIntervalSeconds = 10): int
    {
        if ($lastTestAtUtc === null || $lastTestAtUtc === '') {
            return 0;
        }

        $last = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastTestAtUtc, new DateTimeZone('UTC'));
        if ($last === false) {
            return 0;
        }

        $remaining = $last->getTimestamp() + $minIntervalSeconds - $nowUtc->getTimestamp();

        return max(0, min($minIntervalSeconds, $remaining));
    }
}
