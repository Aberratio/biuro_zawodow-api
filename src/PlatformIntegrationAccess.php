<?php

declare(strict_types=1);

/**
 * Who may use the platform integration, set by a superadmin (app_settings.platform_integration).
 * `off`: nobody; `superadmin`: superadmins only, others see a notice; `on`: every role the endpoints allow.
 * A missing row or table means `superadmin`, so a fresh deploy never exposes the feature.
 */
final class PlatformIntegrationAccess
{
    public const SETTING_KEY = 'platform_integration';
    public const MODES = ['off', 'superadmin', 'on'];
    public const DEFAULT_MODE = 'superadmin';

    private function __construct(public readonly string $mode)
    {
    }

    public static function fromValue(?string $value): self
    {
        $value = $value === null ? '' : trim($value);

        return new self(in_array($value, self::MODES, true) ? $value : self::DEFAULT_MODE);
    }

    public static function isValidMode(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::MODES, true);
    }

    public static function load(PDO $pdo): self
    {
        try {
            $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
            $stmt->execute(['setting_key' => self::SETTING_KEY]);
            $value = $stmt->fetchColumn();
        } catch (PDOException) {
            // Table not migrated yet: keep the safe default.
            $value = false;
        }

        return self::fromValue($value === false ? null : (string)$value);
    }

    public static function save(PDO $pdo, string $mode, string $actorUserId): void
    {
        if (!self::isValidMode($mode)) {
            throw new InvalidArgumentException('Unknown platform integration mode: ' . $mode);
        }

        $stmt = $pdo->prepare('
            INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (:setting_key, :setting_value, :updated_by, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)
        ');
        $stmt->execute([
            'setting_key' => self::SETTING_KEY,
            'setting_value' => $mode,
            'updated_by' => $actorUserId,
        ]);
    }

    public function canUse(string $role): bool
    {
        return match ($this->mode) {
            'on' => true,
            'superadmin' => $role === 'superadmin',
            default => false,
        };
    }

    /** @return array{mode: string, can_use: bool} */
    public function presentFor(string $role): array
    {
        return ['mode' => $this->mode, 'can_use' => $this->canUse($role)];
    }
}
