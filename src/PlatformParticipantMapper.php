<?php

declare(strict_types=1);

require_once __DIR__ . '/ParticipantCodePolicy.php';

/**
 * Turns the platform rows into participant records. Pure. Only the keys listed in the record shape
 * survive; nothing else from the payload is kept.
 */
final class PlatformParticipantMapper
{
    private const DEFAULT_LABELS = [
        'first_name' => 'Imię',
        'last_name' => 'Nazwisko',
        'contact_email' => 'E-mail kontaktowy',
    ];

    /**
     * @param list<mixed> $entries
     * @return array{
     *     records: list<array<string, mixed>>,
     *     invalid: list<array{registration_id: ?string, reason: string}>,
     *     field_definitions: list<array{key: string, role: string, label: string}>
     * }
     */
    public static function mapAll(array $entries): array
    {
        $records = [];
        $invalid = [];
        $seen = [];
        $labels = [];
        $customDefinitions = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $invalid[] = ['registration_id' => null, 'reason' => 'invalid_record'];
                continue;
            }

            $registrationId = isset($entry['registrationId']) && is_string($entry['registrationId']) ? trim($entry['registrationId']) : '';
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $registrationId) !== 1) {
                $invalid[] = ['registration_id' => null, 'reason' => 'invalid_record'];
                continue;
            }

            if (isset($seen[$registrationId])) {
                $invalid[] = ['registration_id' => $registrationId, 'reason' => 'duplicate_record'];
                continue;
            }
            $seen[$registrationId] = true;

            $formFields = [];
            $rawFields = $entry['formFields'] ?? [];
            if (is_array($rawFields) && array_is_list($rawFields)) {
                foreach ($rawFields as $field) {
                    if (!is_array($field) || !isset($field['fieldKey']) || !is_string($field['fieldKey'])) {
                        continue;
                    }
                    $fieldKey = trim($field['fieldKey']);
                    if (preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $fieldKey) !== 1 || isset($formFields[$fieldKey])) {
                        continue;
                    }
                    $label = isset($field['label']) && is_string($field['label']) ? mb_substr(trim($field['label']), 0, 190) : '';
                    $formFields[$fieldKey] = ['label' => $label, 'value' => self::normalizeValue($field['value'] ?? null)];

                    if (isset(self::DEFAULT_LABELS[$fieldKey])) {
                        if ($label !== '' && !isset($labels[$fieldKey])) {
                            $labels[$fieldKey] = $label;
                        }
                    } elseif (!isset($customDefinitions['field:' . $fieldKey])) {
                        $customDefinitions['field:' . $fieldKey] = [
                            'key' => 'field:' . $fieldKey,
                            'role' => 'custom',
                            'label' => $label !== '' ? $label : $fieldKey,
                        ];
                    }
                }
            }

            $records[] = self::mapRecord($registrationId, $entry, $formFields);
        }

        $definitions = [
            ['key' => 'first_name', 'role' => 'display_name_part', 'label' => $labels['first_name'] ?? self::DEFAULT_LABELS['first_name']],
            ['key' => 'last_name', 'role' => 'display_name_part', 'label' => $labels['last_name'] ?? self::DEFAULT_LABELS['last_name']],
            ['key' => 'contact_email', 'role' => 'email', 'label' => $labels['contact_email'] ?? self::DEFAULT_LABELS['contact_email']],
            ['key' => 'bib_number', 'role' => 'bib_number', 'label' => 'Numer startowy'],
            ['key' => 'paid', 'role' => 'payment_status', 'label' => 'Opłata'],
            ['key' => 'distance', 'role' => 'important_custom', 'label' => 'Dystans'],
            ['key' => 'category', 'role' => 'custom', 'label' => 'Kategoria'],
            ['key' => 'merchandise', 'role' => 'custom', 'label' => 'Pakiet startowy'],
            ...array_values($customDefinitions),
        ];

        return ['records' => $records, 'invalid' => $invalid, 'field_definitions' => $definitions];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array{label: string, value: string}> $formFields
     * @return array<string, mixed>
     */
    private static function mapRecord(string $registrationId, array $entry, array $formFields): array
    {
        $warnings = [];

        $firstName = self::nullable(mb_substr($formFields['first_name']['value'] ?? '', 0, 120));
        $lastName = self::nullable(mb_substr($formFields['last_name']['value'] ?? '', 0, 120));

        $email = normalizeEmailAddress($formFields['contact_email']['value'] ?? '');
        if ($email === '' || !isValidEmailAddress($email)) {
            $email = '';
            $warnings[] = 'missing_email';
        }
        $email = mb_substr($email, 0, 190);

        $displayName = trim((string)$firstName . ' ' . (string)$lastName);
        if ($displayName === '') {
            $displayName = $email !== '' ? $email : 'Uczestnik ' . $registrationId;
        }

        $qrCode = ParticipantCodePolicy::acceptedStartCode(is_string($entry['qrCode'] ?? null) ? $entry['qrCode'] : null);
        if ($qrCode === null) {
            $warnings[] = 'start_code_generated';
        }
        $identityCode = ParticipantCodePolicy::acceptedIdentityCode(is_string($entry['identityCode'] ?? null) ? $entry['identityCode'] : null);

        $fields = [
            'first_name' => (string)$firstName,
            'last_name' => (string)$lastName,
            'distance' => self::normalizeValue($entry['distanceName'] ?? null),
            'category' => self::normalizeValue($entry['category'] ?? null),
            'merchandise' => self::merchandise($entry['merchandise'] ?? null),
        ];
        foreach ($formFields as $fieldKey => $field) {
            if (!isset(self::DEFAULT_LABELS[$fieldKey])) {
                $fields['field:' . $fieldKey] = $field['value'];
            }
        }

        return [
            'platform_registration_id' => $registrationId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => mb_substr($displayName, 0, 255),
            'email' => $email,
            'bib_number' => self::nullable(mb_substr(self::normalizeValue($entry['bibNumber'] ?? null), 0, 32)),
            'payment_status' => ($entry['paid'] ?? false) === true ? 'paid' : 'unpaid',
            'qr_code' => $qrCode,
            'identity_code' => $identityCode,
            'fields' => $fields,
            'warnings' => $warnings,
        ];
    }

    private static function merchandise(mixed $items): string
    {
        if (!is_array($items)) {
            return '';
        }

        $parts = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = self::normalizeValue($item['item'] ?? null);
            if ($name === '') {
                continue;
            }
            $size = self::normalizeValue($item['size'] ?? null);
            $quantity = isset($item['quantity']) && is_numeric($item['quantity']) ? (int)$item['quantity'] : 1;
            $parts[] = $name . ($size !== '' ? ' (' . $size . ')' : '') . ' ×' . $quantity;
        }

        return mb_substr(implode('; ', $parts), 0, 1000);
    }

    private static function normalizeValue(mixed $value): string
    {
        if (is_string($value)) {
            $text = trim($value);
        } elseif (is_int($value) || is_float($value)) {
            $text = (string)$value;
        } elseif (is_bool($value)) {
            $text = $value ? 'TAK' : 'NIE';
        } elseif (is_array($value) && array_is_list($value)) {
            $scalars = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $scalars[] = trim((string)$item);
                } elseif (is_bool($item)) {
                    $scalars[] = $item ? 'TAK' : 'NIE';
                }
            }
            $text = implode(', ', $scalars);
        } else {
            $text = '';
        }

        return mb_substr($text, 0, 1000);
    }

    private static function nullable(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
