<?php

declare(strict_types=1);

/**
 * Decides which participant field mappings the platform columns use. Pure.
 * Labels become the source column names, so a later file import with the same headers keeps working.
 */
final class PlatformFieldLayout
{
    private const COMPATIBLE = [
        'email' => ['email'],
        'bib_number' => ['bib_number'],
        'payment_status' => ['payment_status'],
        'display_name_part' => ['display_name_part'],
        'custom' => ['custom', 'important_custom'],
        'important_custom' => ['custom', 'important_custom'],
    ];

    /**
     * @param list<array<string, mixed>> $existingMappings source_column_name, alias, field_role, display_order, is_active
     * @param list<array{key: string, role: string, label: string}> $definitions
     * @param array<string, string> $storedLayout key => source_column_name
     * @return array{
     *     alias_by_key: array<string, string>,
     *     source_by_key: array<string, string>,
     *     to_create: list<array<string, mixed>>,
     *     important_aliases: list<string>
     * }
     */
    public static function plan(array $existingMappings, array $definitions, array $storedLayout): array
    {
        $used = [];
        $maxOrder = -1;
        foreach ($existingMappings as $mapping) {
            $used[self::fold((string)$mapping['source_column_name'])] = true;
            $used[self::fold((string)$mapping['alias'])] = true;
            $maxOrder = max($maxOrder, (int)($mapping['display_order'] ?? 0));
        }
        $nextOrder = $maxOrder + 1;

        $aliasByKey = [];
        $sourceByKey = [];
        $toCreate = [];
        $important = [];

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            $role = $definition['role'];
            $chosen = self::choose($existingMappings, $key, $role, $definition['label'], $storedLayout);

            if ($chosen === null) {
                $text = self::uniqueText($definition['label'], $used);
                $used[self::fold($text)] = true;
                $toCreate[] = [
                    'source_column_name' => $text,
                    'alias' => $text,
                    'field_role' => $role,
                    'field_type' => 'text',
                    'validation_rules' => [],
                    'display_order' => $nextOrder++,
                    'is_required' => false,
                    'is_active' => true,
                ];
                $chosen = ['source' => $text, 'alias' => $text];
            }

            $sourceByKey[$key] = $chosen['source'];
            $aliasByKey[$key] = $chosen['alias'];
            if ($role === 'important_custom') {
                $important[$chosen['alias']] = true;
            }
        }

        return [
            'alias_by_key' => $aliasByKey,
            'source_by_key' => $sourceByKey,
            'to_create' => $toCreate,
            'important_aliases' => array_keys($important),
        ];
    }

    /** @return ?array{source: string, alias: string} */
    private static function choose(array $existing, string $key, string $role, string $label, array $storedLayout): ?array
    {
        $stored = $storedLayout[$key] ?? null;
        if (is_string($stored)) {
            foreach ($existing as $mapping) {
                if ((string)$mapping['source_column_name'] === $stored) {
                    return ['source' => (string)$mapping['source_column_name'], 'alias' => (string)$mapping['alias']];
                }
            }
        }

        $compatible = self::COMPATIBLE[$role] ?? [$role];
        $wanted = self::fold($label);
        foreach ($existing as $mapping) {
            if (in_array((string)$mapping['field_role'], $compatible, true)
                && (self::fold((string)$mapping['source_column_name']) === $wanted || self::fold((string)$mapping['alias']) === $wanted)) {
                return ['source' => (string)$mapping['source_column_name'], 'alias' => (string)$mapping['alias']];
            }
        }

        if (in_array($role, ['email', 'bib_number', 'payment_status'], true)) {
            foreach ($existing as $mapping) {
                if ((string)$mapping['field_role'] === $role) {
                    return ['source' => (string)$mapping['source_column_name'], 'alias' => (string)$mapping['alias']];
                }
            }
        }

        return null;
    }

    /** @param array<string, bool> $used */
    private static function uniqueText(string $label, array $used): string
    {
        $base = mb_substr($label, 0, 190);
        if (!isset($used[self::fold($base)])) {
            return $base;
        }

        for ($attempt = 1;; $attempt++) {
            $suffix = $attempt === 1 ? ' (platforma)' : ' (platforma ' . $attempt . ')';
            $candidate = mb_substr($base, 0, 190 - mb_strlen($suffix)) . $suffix;
            if (!isset($used[self::fold($candidate)])) {
                return $candidate;
            }
        }
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
