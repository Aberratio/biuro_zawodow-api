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

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d\TH:i:s\Z');
    }
}
