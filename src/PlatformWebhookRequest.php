<?php

declare(strict_types=1);

/** Parses and validates a critical-change webhook sent by the platform. Pure: no I/O. */
final class PlatformWebhookRequest
{
    public const MAX_BODY_BYTES = 65536;

    /**
     * @param array<string, string> $headers lower-case header name => value
     * @return array{ok: bool, error: ?string, request: ?array<string, mixed>}
     */
    public static function parse(array $headers, string $rawBody): array
    {
        $token = trim((string)($headers['x-bz-organization-token'] ?? ''));
        if ($token === '' || strlen($token) > 512) {
            return self::failure('missing_token');
        }

        $dedupeKey = trim((string)($headers['idempotency-key'] ?? ''));
        if (preg_match('/^[\x21-\x7E]{1,190}$/', $dedupeKey) !== 1) {
            return self::failure('missing_idempotency_key');
        }

        $decoded = json_decode($rawBody, true, 16);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return self::failure('invalid_json');
        }

        $eventId = $decoded['eventId'] ?? null;
        $registrationId = $decoded['registrationId'] ?? null;
        $changeType = $decoded['changeType'] ?? null;
        if (!is_string($eventId) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $eventId) !== 1
            || !is_string($registrationId) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $registrationId) !== 1
            || !is_string($changeType) || preg_match('/^[a-z_]{1,32}$/', $changeType) !== 1) {
            return self::failure('invalid_request');
        }

        $occurredAt = null;
        if (isset($decoded['occurredAt']) && is_string($decoded['occurredAt']) && $decoded['occurredAt'] !== '') {
            try {
                $occurredAt = (new DateTimeImmutable($decoded['occurredAt']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $occurredAt = null;
            }
        }

        // qrCode is deliberately ignored: rows are matched by registration id only.
        return [
            'ok' => true,
            'error' => null,
            'request' => [
                'token' => $token,
                'dedupe_key' => $dedupeKey,
                'event_id' => $eventId,
                'registration_id' => $registrationId,
                'change_type' => $changeType,
                'occurred_at' => $occurredAt,
                'fingerprint' => hash('sha256', implode("\n", [$eventId, $registrationId, $changeType, $occurredAt ?? ''])),
            ],
        ];
    }

    /** @return array{ok: bool, error: ?string, request: ?array<string, mixed>} */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'request' => null];
    }
}
