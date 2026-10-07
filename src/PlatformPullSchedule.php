<?php

declare(strict_types=1);

/** How often an event is pulled by the cron script. Pure; local dates like the rest of the office logic. */
final class PlatformPullSchedule
{
    public const EVENT_DAY_SECONDS = 300;
    public const NEAR_SECONDS = 3600;
    public const FAR_SECONDS = 21600;

    /** Cron starts a few seconds late or early; this much slack keeps a 5 minute cadence from drifting to 10. */
    private const JITTER_SECONDS = 20;

    /** @param array<string, mixed> $event event_date, office_open_at, office_close_at, archived_at, deleted_at */
    public static function intervalSeconds(array $event, DateTimeImmutable $nowLocal): ?int
    {
        if (!empty($event['archived_at']) || !empty($event['deleted_at'])) {
            return null;
        }

        $zone = $nowLocal->getTimezone();
        $open = self::parseLocal((string)($event['office_open_at'] ?? ''), $zone);
        $close = self::parseLocal((string)($event['office_close_at'] ?? ''), $zone);
        if ($open !== null && $close !== null
            && $nowLocal >= $open->modify('-2 hours') && $nowLocal <= $close->modify('+2 hours')) {
            return self::EVENT_DAY_SECONDS;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($event['event_date'] ?? ''), 0, 10), $zone);
        if ($date === false) {
            return self::FAR_SECONDS;
        }

        $diff = $nowLocal->setTime(0, 0)->diff($date);
        $days = $diff->invert === 1 ? -$diff->days : $diff->days;

        if ($days === 0) {
            return self::EVENT_DAY_SECONDS;
        }
        if ($days < -1) {
            return null;
        }

        return $days <= 7 ? self::NEAR_SECONDS : self::FAR_SECONDS;
    }

    public static function isDue(?string $lastPullStartedUtc, ?int $intervalSeconds, DateTimeImmutable $nowUtc): bool
    {
        if ($intervalSeconds === null) {
            return false;
        }
        if ($lastPullStartedUtc === null || $lastPullStartedUtc === '') {
            return true;
        }

        $last = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastPullStartedUtc, new DateTimeZone('UTC'));
        if ($last === false) {
            return true;
        }

        return $nowUtc->getTimestamp() - $last->getTimestamp() >= $intervalSeconds - self::JITTER_SECONDS;
    }

    /** Seconds until the next pull is due (0 = now). */
    public static function secondsUntilDue(?string $lastPullStartedUtc, int $intervalSeconds, DateTimeImmutable $nowUtc): int
    {
        $last = $lastPullStartedUtc !== null && $lastPullStartedUtc !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastPullStartedUtc, new DateTimeZone('UTC'))
            : false;
        if ($last === false) {
            return 0;
        }

        return max(0, $last->getTimestamp() + $intervalSeconds - $nowUtc->getTimestamp());
    }

    private static function parseLocal(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $zone);

        return $parsed === false ? null : $parsed;
    }
}
