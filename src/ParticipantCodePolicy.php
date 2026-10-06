<?php

declare(strict_types=1);

/**
 * Pure policy for the two participant codes (no database, no IO).
 *
 * Start code (qr_code, "pqr_" + 48 hex): issued either by this app or by the platform. The platform
 * uses the very same format, so a well formed code is kept as delivered and anything else is replaced
 * by a locally generated one.
 *
 * Identity code (identity_code, "zqr_" + 48 hex): belongs to the competitor, is issued only by the
 * platform and is never generated, regenerated or rewritten by this app. It is deliberately not unique:
 * one competitor has one registration row per distance and per event.
 */
final class ParticipantCodePolicy
{
    public const SCAN_START = 'start';
    public const SCAN_IDENTITY = 'identity';
    public const SCAN_INVALID_IDENTITY = 'invalid_identity';
    public const SCAN_LEGACY = 'legacy';

    public const RESOLVED = 'resolved';
    public const NOT_FOUND = 'not_found';
    public const AMBIGUOUS_EVENT = 'ambiguous_event';

    /** Start code accepted as delivered, or null when a local one must be generated. */
    public static function acceptedStartCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim($code);
        return QrCodeService::isSecureToken($trimmed) ? $trimmed : null;
    }

    public static function mustRegenerateStartCode(?string $storedCode): bool
    {
        return self::acceptedStartCode($storedCode) === null;
    }

    /** Identity code accepted as delivered, or null. It is never generated locally. */
    public static function acceptedIdentityCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim($code);
        return QrCodeService::isIdentityToken($trimmed) ? $trimmed : null;
    }

    /** Decides which lookup a scanned code needs. Case sensitive on purpose (codes are lower-case hex). */
    public static function classifyScannedCode(string $code): string
    {
        if (QrCodeService::isIdentityToken($code)) {
            return self::SCAN_IDENTITY;
        }

        if (str_starts_with($code, QrCodeService::IDENTITY_PREFIX)) {
            return self::SCAN_INVALID_IDENTITY;
        }

        if (QrCodeService::isSecureToken($code)) {
            return self::SCAN_START;
        }

        return self::SCAN_LEGACY;
    }

    /**
     * Picks the registrations of one competitor for the scanning office's current event.
     *
     * @param list<array{id:int|string, event_id:string}> $candidates registrations carrying the scanned identity code
     * @param list<string> $accessibleEventIds events the user may scan in
     * @param list<string> $openOfficeEventIds subset of events whose office is open now
     * @return array{status:string, event_id:?string, participants:list<array>, event_ids:list<string>}
     */
    public static function resolveIdentityScan(
        array $candidates,
        array $accessibleEventIds,
        array $openOfficeEventIds,
        ?string $requestedEventId
    ): array {
        $accessible = array_flip($accessibleEventIds);
        $remaining = array_values(array_filter(
            $candidates,
            static function (array $candidate) use ($accessible): bool {
                $eventId = (string)($candidate['event_id'] ?? '');
                return $eventId !== '' && isset($accessible[$eventId]);
            }
        ));

        $requested = $requestedEventId !== null ? trim($requestedEventId) : '';
        if ($requested !== '') {
            $remaining = array_values(array_filter(
                $remaining,
                static fn (array $candidate): bool => (string)$candidate['event_id'] === $requested
            ));
        }

        $distinctEvents = static function (array $rows): array {
            $ids = array_values(array_unique(array_map(
                static fn (array $row): string => (string)$row['event_id'],
                $rows
            )));
            sort($ids, SORT_STRING);
            return $ids;
        };

        if ($requested === '' && count($distinctEvents($remaining)) > 1) {
            $open = array_flip($openOfficeEventIds);
            $openOnly = array_values(array_filter(
                $remaining,
                static fn (array $candidate): bool => isset($open[(string)$candidate['event_id']])
            ));
            if ($openOnly !== []) {
                $remaining = $openOnly;
            }
        }

        $eventIds = $distinctEvents($remaining);
        if ($eventIds === []) {
            return ['status' => self::NOT_FOUND, 'event_id' => null, 'participants' => [], 'event_ids' => []];
        }

        if (count($eventIds) > 1) {
            return ['status' => self::AMBIGUOUS_EVENT, 'event_id' => null, 'participants' => [], 'event_ids' => $eventIds];
        }

        usort($remaining, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

        return [
            'status' => self::RESOLVED,
            'event_id' => $eventIds[0],
            'participants' => $remaining,
            'event_ids' => $eventIds,
        ];
    }
}
