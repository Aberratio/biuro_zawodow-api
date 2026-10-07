<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformTokenCipher.php';

/**
 * Process-level configuration of the BZ <-> platform integration. Never evaluated at bootstrap:
 * a missing or invalid variable only makes the feature inactive (reason codes), never a crash.
 */
final class PlatformIntegrationConfig
{
    /** @param list<string> $reasons */
    private function __construct(
        private readonly array $reasons,
        private readonly string $apiBaseUrl,
        private readonly string $appKey,
        private readonly int $timeoutSeconds,
        private readonly int $pullTimeoutSeconds,
        private readonly ?string $currentKey,
        private readonly ?string $previousKey
    ) {
    }

    /** @param callable(string): (string|false) $env */
    public static function fromEnvironment(callable $env, bool $sodiumAvailable, bool $allowInsecureHttp): self
    {
        $read = static function (string $name) use ($env): string {
            $value = $env($name);

            return $value === false ? '' : trim((string)$value);
        };

        $reasons = [];
        if (!$sodiumAvailable) {
            $reasons[] = 'sodium_missing';
        }

        $currentKey = null;
        $rawCurrent = $read('BZ_PLATFORM_TOKEN_KEY');
        if ($rawCurrent === '') {
            $reasons[] = 'token_key_missing';
        } else {
            $currentKey = self::decodeKey($rawCurrent);
            if ($currentKey === null) {
                $reasons[] = 'token_key_invalid';
            }
        }

        $previousKey = null;
        $rawPrevious = $read('BZ_PLATFORM_TOKEN_KEY_PREVIOUS');
        if ($rawPrevious !== '') {
            $previousKey = self::decodeKey($rawPrevious);
            if ($previousKey === null) {
                $reasons[] = 'previous_token_key_invalid';
            }
        }

        $apiUrl = '';
        $rawUrl = $read('BZ_PLATFORM_API_URL');
        if ($rawUrl === '') {
            $reasons[] = 'api_url_missing';
        } else {
            $apiUrl = self::normalizeUrl($rawUrl, $allowInsecureHttp) ?? '';
            if ($apiUrl === '') {
                $reasons[] = 'api_url_invalid';
            }
        }

        $appKey = $read('BZ_PLATFORM_APP_KEY');
        if ($appKey === '') {
            $reasons[] = 'app_key_missing';
        }

        $timeout = $read('BZ_PLATFORM_TIMEOUT_SECONDS');
        $timeoutSeconds = $timeout !== '' && ctype_digit($timeout) ? max(1, min(30, (int)$timeout)) : 10;

        $pullTimeout = $read('BZ_PLATFORM_PULL_TIMEOUT_SECONDS');
        $pullTimeoutSeconds = $pullTimeout !== '' && ctype_digit($pullTimeout) ? max(5, min(120, (int)$pullTimeout)) : 60;

        return new self($reasons, $apiUrl, $appKey, $timeoutSeconds, $pullTimeoutSeconds, $currentKey, $previousKey);
    }

    public static function fromProcess(): self
    {
        return self::fromEnvironment(
            static fn (string $name): string|false => getenv($name),
            function_exists('sodium_crypto_secretbox'),
            isLocalEnvironment()
        );
    }

    public function isAvailable(): bool
    {
        return $this->reasons === [];
    }

    /** @return list<string> */
    public function unavailableReasons(): array
    {
        return $this->reasons;
    }

    public function apiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function appKey(): string
    {
        return $this->appKey;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** Timeout of a full participant list download (much longer than the connection test). */
    public function pullTimeoutSeconds(): int
    {
        return $this->pullTimeoutSeconds;
    }

    public function cipher(): PlatformTokenCipher
    {
        if (!$this->isAvailable() || $this->currentKey === null) {
            throw new LogicException('platform_integration_unavailable');
        }

        return new PlatformTokenCipher($this->currentKey, $this->previousKey);
    }

    private static function decodeKey(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        return $decoded !== false && strlen($decoded) === 32 ? $decoded : null;
    }

    private static function normalizeUrl(string $url, bool $allowInsecureHttp): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInsecureHttp)) {
            return null;
        }

        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || str_contains($url, '?') || str_contains($url, '#')) {
            return null;
        }

        return rtrim($url, '/');
    }
}
