<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformIntegrationConfig.php';

/** The only way a platform connection reaches an API response. Strict key whitelist. */
final class PlatformConnectionPresenter
{
    /**
     * @return array{availability: array{available: bool, reasons: list<string>}, connection: ?array<string, mixed>}
     */
    public static function present(?array $row, PlatformIntegrationConfig $config, ?bool $tokenReadable): array
    {
        $connection = null;
        if ($row !== null) {
            $connection = [
                'platform_event_id' => (string)($row['platform_event_id'] ?? ''),
                'is_enabled' => (bool)($row['is_enabled'] ?? false),
                'token_set' => ((string)($row['organization_token_ciphertext'] ?? '')) !== '',
                'token_hint' => (string)($row['organization_token_hint'] ?? ''),
                'token_readable' => $tokenReadable,
                'token_updated_at' => self::iso($row['token_updated_at'] ?? null),
                'last_test_at' => self::iso($row['last_test_at'] ?? null),
                'last_test_status' => isset($row['last_test_status']) ? (string)$row['last_test_status'] : null,
                'last_pull_at' => self::iso($row['last_pull_finished_at'] ?? null) ?? self::iso($row['last_pull_started_at'] ?? null),
                'last_pull_status' => isset($row['last_pull_status']) ? (string)$row['last_pull_status'] : null,
                'last_pull_summary' => self::summary($row['last_pull_summary_json'] ?? null),
                'updated_at' => isset($row['updated_at_unix'])
                    ? gmdate('Y-m-d\TH:i:s\Z', (int)$row['updated_at_unix'])
                    : self::iso($row['updated_at'] ?? null),
            ];
        }

        return [
            'availability' => [
                'available' => $config->isAvailable(),
                'reasons' => $config->unavailableReasons(),
            ],
            'connection' => $connection,
        ];
    }

    private const SUMMARY_COUNTS = ['fetched', 'created', 'updated', 'unchanged', 'skipped', 'removed', 'restored', 'possible_duplicates', 'duration_ms'];

    /** Only known counters survive; anything else stored in the summary is dropped. */
    private static function summary(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $summary = [];
        foreach (self::SUMMARY_COUNTS as $key) {
            $summary[$key] = isset($decoded[$key]) && is_int($decoded[$key]) ? $decoded[$key] : 0;
        }
        foreach (['skipped_by_reason', 'warnings'] as $key) {
            $summary[$key] = [];
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                foreach ($decoded[$key] as $name => $count) {
                    if (is_string($name) && preg_match('/^[a-z_]{1,40}$/', $name) === 1 && is_int($count)) {
                        $summary[$key][$name] = $count;
                    }
                }
            }
        }

        return $summary;
    }

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d\TH:i:s\Z');
    }
}
