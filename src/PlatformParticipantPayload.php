<?php

declare(strict_types=1);

/** Envelope check of the platform participant list. Pure: no database, no logging. */
final class PlatformParticipantPayload
{
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;

    /** @return array{ok: bool, status: string, entries: list<mixed>} */
    public static function parse(string $body): array
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ['ok' => false, 'status' => 'response_too_large', 'entries' => []];
        }

        $decoded = json_decode($body, true, 64);
        if (!is_array($decoded)
            || ($decoded['success'] ?? null) !== true
            || !isset($decoded['data'])
            || !is_array($decoded['data'])
            || !array_is_list($decoded['data'])
        ) {
            return ['ok' => false, 'status' => 'invalid_response', 'entries' => []];
        }

        return ['ok' => true, 'status' => 'ok', 'entries' => $decoded['data']];
    }
}
