<?php

declare(strict_types=1);

/** Validation of the platform connection request body. Messages never echo the submitted token. */
final class PlatformConnectionInput
{
    /**
     * @return array{ok: bool, fields: array<string, string>, platform_event_id: string, organization_token: ?string, is_enabled: bool}
     */
    public static function parse(array $input, bool $hasStoredToken): array
    {
        $fields = [];

        $platformEventId = is_string($input['platform_event_id'] ?? null) ? trim($input['platform_event_id']) : '';
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $platformEventId) !== 1) {
            $fields['platform_event_id'] = 'Podaj ID wydarzenia z platformy (litery, cyfry, - lub _, maks. 64 znaki).';
        }

        $rawToken = $input['organization_token'] ?? null;
        $token = is_string($rawToken) ? trim($rawToken) : '';
        $organizationToken = null;
        if ($rawToken !== null && !is_string($rawToken)) {
            $fields['organization_token'] = 'Token organizacji musi mieć od 32 do 255 znaków i nie może zawierać spacji.';
        } elseif ($token === '') {
            if (!$hasStoredToken) {
                $fields['organization_token'] = 'Podaj token organizacji (wygeneruj nowy albo wklej token zapisany na platformie).';
            }
        } elseif (preg_match('/^[\x21-\x7E]{32,255}$/D', $token) !== 1) {
            $fields['organization_token'] = 'Token organizacji musi mieć od 32 do 255 znaków i nie może zawierać spacji.';
        } else {
            $organizationToken = $token;
        }

        $isEnabled = false;
        if (array_key_exists('is_enabled', $input) && $input['is_enabled'] !== null) {
            if (is_bool($input['is_enabled'])) {
                $isEnabled = $input['is_enabled'];
            } else {
                $fields['is_enabled'] = 'Nieprawidłowa wartość przełącznika integracji.';
            }
        }

        return [
            'ok' => $fields === [],
            'fields' => $fields,
            'platform_event_id' => $platformEventId,
            'organization_token' => $organizationToken,
            'is_enabled' => $isEnabled,
        ];
    }
}
