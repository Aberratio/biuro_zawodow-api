<?php

declare(strict_types=1);

/**
 * Which office edits travel back to the platform. Pure.
 * Payment, display name, distance and merchandise are never pushed.
 */
final class PlatformPushFieldMap
{
    /** Platform field keys that BZ stores in the mapped custom field of the participant. */
    private const MAPPED_KEYS = ['first_name', 'last_name', 'category'];

    /**
     * @param list<string> $changedFields names produced by the participant change diff (columns and custom field aliases)
     * @param array<string, string> $aliasByKey platform field key (first_name, category, field:<key>, ...) => BZ alias
     * @return list<string> platform field keys, in first-seen order, without duplicates
     */
    public static function platformKeys(array $changedFields, array $aliasByKey): array
    {
        $keys = [];
        foreach ($changedFields as $field) {
            $key = self::keyFor((string)$field, $aliasByKey);
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @param array<string, string> $aliasByKey */
    private static function keyFor(string $field, array $aliasByKey): ?string
    {
        if ($field === 'email') {
            return 'contact_email';
        }
        if ($field === 'bib_number') {
            return 'bib_number';
        }
        if (in_array($field, ['display_name', 'payment_status'], true)) {
            return null;
        }

        foreach ($aliasByKey as $key => $alias) {
            if ($alias !== $field) {
                continue;
            }
            if (in_array($key, self::MAPPED_KEYS, true)) {
                return $key;
            }
            if (str_starts_with($key, 'field:') && strlen($key) > 6) {
                return substr($key, 6);
            }
        }

        return null;
    }
}
