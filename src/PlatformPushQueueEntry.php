<?php

declare(strict_types=1);

/**
 * The pending fields of one participant in the push queue:
 * {"checked_in_at": {"at": "Y-m-d H:i:s"}, "checked_in_by": {"at": "...", "value": "..."}, "bib_number": {"at": "..."}}.
 * "at" is the UTC moment of the office change and becomes the platform's changedAt. Pure.
 */
final class PlatformPushQueueEntry
{
    /**
     * Newer changes of the same field replace older ones (the SQL upsert does the same with JSON_MERGE_PATCH).
     *
     * @param array<string, array<string, string>> $existing
     * @param array<string, array<string, string>> $incoming
     * @return array<string, array<string, string>>
     */
    public static function merge(array $existing, array $incoming): array
    {
        return array_replace($existing, $incoming);
    }

    /**
     * Removes what a successful request delivered. A field that changed again while the request was
     * in flight (different stamp or value) stays queued.
     *
     * @param array<string, array<string, string>> $stored fields as they are in the queue now
     * @param array<string, array<string, string>> $sent fields as they were when the request was built
     * @return array<string, array<string, string>>
     */
    public static function settle(array $stored, array $sent): array
    {
        $remaining = [];
        foreach ($stored as $key => $entry) {
            if (!array_key_exists($key, $sent) || self::normalize($sent[$key]) !== self::normalize($entry)) {
                $remaining[$key] = $entry;
            }
        }

        return $remaining;
    }

    /** @param array<string, string> $entry */
    private static function normalize(array $entry): array
    {
        ksort($entry);

        return $entry;
    }

    /** @return array<string, array<string, string>> */
    public static function decode(?string $json): array
    {
        $decoded = $json !== null && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key) && is_array($entry) && isset($entry['at']) && is_string($entry['at'])) {
                $clean = ['at' => $entry['at']];
                if (array_key_exists('value', $entry)) {
                    $clean['value'] = (string)$entry['value'];
                }
                $fields[$key] = $clean;
            }
        }

        return $fields;
    }
}
