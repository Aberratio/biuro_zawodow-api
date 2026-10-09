<?php

declare(strict_types=1);

/** What to do with a queue row after the platform answered (or did not). Pure. */
final class PlatformPushResultClassifier
{
    public const MAX_ATTEMPTS = 24;

    /** Minutes to wait after attempt N (1-based); the last value repeats. */
    private const BACKOFF_MINUTES = [1, 2, 5, 10, 30, 60];
    private const RATE_LIMIT_MINIMUM_SECONDS = 300;

    /**
     * @param string $status PlatformApiClient::patchParticipant status
     * @param int $attempts attempts made so far, including this one
     * @return array{outcome: 'sent'|'failed'|'abandoned', retry_in_seconds: ?int, stop_run: bool}
     */
    public static function classify(string $status, ?int $httpStatus, int $attempts): array
    {
        if ($status === 'ok') {
            return ['outcome' => 'sent', 'retry_in_seconds' => null, 'stop_run' => false];
        }
        if (in_array($status, ['platform_registration_not_found', 'platform_validation_rejected'], true)) {
            return ['outcome' => 'abandoned', 'retry_in_seconds' => null, 'stop_run' => false];
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            return ['outcome' => 'abandoned', 'retry_in_seconds' => null, 'stop_run' => in_array($status, ['app_key_rejected', 'platform_rate_limited'], true)];
        }

        $seconds = self::BACKOFF_MINUTES[min(max($attempts, 1), count(self::BACKOFF_MINUTES)) - 1] * 60;
        if ($status === 'platform_rate_limited') {
            $seconds = max($seconds, self::RATE_LIMIT_MINIMUM_SECONDS);
        }

        return [
            'outcome' => 'failed',
            'retry_in_seconds' => $seconds,
            'stop_run' => in_array($status, ['app_key_rejected', 'platform_rate_limited'], true),
        ];
    }
}
