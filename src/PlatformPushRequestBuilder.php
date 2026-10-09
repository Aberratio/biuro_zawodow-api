<?php

declare(strict_types=1);

/**
 * Builds the body of PATCH .../bz/participants/{registrationId} from the queued fields and the participant
 * as it is right now (the latest value wins). Pure. The reason never contains personal data.
 */
final class PlatformPushRequestBuilder
{
    public const REASON_CHECK_IN = 'Biuro Zawodów: odprawa';
    public const REASON_CORRECTION = 'Biuro Zawodów: poprawka danych';

    /**
     * @param array<string, mixed> $participant checked_in_at (UTC), email, bib_number, first_name, last_name, custom_fields
     * @param array<string, array<string, string>> $queued field key => {at, value?}
     * @param array<string, string> $aliasByKey platform field key (first_name, field:<key>, ...) => BZ alias
     * @return array{fields: array<string, array{value: mixed, changedAt: string}>, reason: string}
     */
    public static function build(array $participant, array $queued, array $aliasByKey): array
    {
        $fields = [];
        $customFields = is_array($participant['custom_fields'] ?? null) ? $participant['custom_fields'] : [];

        foreach ($queued as $key => $entry) {
            $changedAt = self::iso((string)($entry['at'] ?? ''));
            if ($changedAt === null) {
                continue;
            }

            switch ($key) {
                case 'checked_in_at':
                    $value = self::iso((string)($participant['checked_in_at'] ?? ''));
                    break;
                case 'checked_in_by':
                    $value = self::nullable((string)($entry['value'] ?? ''));
                    break;
                case 'contact_email':
                    $value = self::nullable((string)($participant['email'] ?? ''));
                    break;
                case 'bib_number':
                    $value = self::nullable((string)($participant['bib_number'] ?? ''));
                    break;
                default:
                    $alias = $aliasByKey[$key] ?? $aliasByKey['field:' . $key] ?? null;
                    if ($alias === null) {
                        continue 2;
                    }
                    $value = self::nullable((string)($customFields[$alias] ?? ''));
                    if ($value === null && in_array($key, ['first_name', 'last_name'], true)) {
                        $value = self::nullable((string)($participant[$key] ?? ''));
                    }
            }

            $fields[$key] = ['value' => $value, 'changedAt' => $changedAt];
        }

        $checkIn = array_key_exists('checked_in_at', $fields) || array_key_exists('checked_in_by', $fields);

        return ['fields' => $fields, 'reason' => $checkIn ? self::REASON_CHECK_IN : self::REASON_CORRECTION];
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function iso(string $utc): ?string
    {
        if ($utc === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d\TH:i:sP');
    }
}
