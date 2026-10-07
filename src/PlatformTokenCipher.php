<?php

declare(strict_types=1);

/**
 * Encrypts the per-event organization token of the platform connection (libsodium secretbox).
 *
 * Envelope: v1.<kid>.<base64url(nonce || box)>. The plaintext is bound to the BZ event id so a
 * ciphertext copied onto another row cannot be read.
 */
final class PlatformTokenCipher
{
    private const VERSION = 'v1';

    /** @var array<string, string> key id => raw key */
    private array $keys = [];
    private string $currentKeyId;

    public function __construct(string $currentRawKey, ?string $previousRawKey = null)
    {
        foreach ([$currentRawKey, $previousRawKey] as $key) {
            if ($key !== null && strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new InvalidArgumentException('invalid_key_length');
            }
        }

        $this->currentKeyId = self::keyId($currentRawKey);
        $this->keys[$this->currentKeyId] = $currentRawKey;
        if ($previousRawKey !== null) {
            $this->keys[self::keyId($previousRawKey)] = $previousRawKey;
        }
    }

    public static function keyId(string $rawKey): string
    {
        return substr(hash('sha256', 'bz-platform-token-kid:' . $rawKey), 0, 12);
    }

    public function encrypt(string $token, string $bzEventId): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = $bzEventId . "\0" . $token;
        $box = sodium_crypto_secretbox($plain, $nonce, $this->keys[$this->currentKeyId]);
        sodium_memzero($plain);

        return self::VERSION . '.' . $this->currentKeyId . '.' . self::base64UrlEncode($nonce . $box);
    }

    public function decrypt(string $envelope, string $bzEventId): ?string
    {
        $parts = explode('.', $envelope, 3);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION || !isset($this->keys[$parts[1]])) {
            return null;
        }

        $raw = self::base64UrlDecode($parts[2]);
        if ($raw === null || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $this->keys[$parts[1]]);
        if ($plain === false) {
            return null;
        }

        $prefix = $bzEventId . "\0";
        if (!str_starts_with($plain, $prefix)) {
            sodium_memzero($plain);
            return null;
        }

        return substr($plain, strlen($prefix));
    }

    public function needsReencryption(string $envelope): bool
    {
        $parts = explode('.', $envelope, 3);

        return count($parts) !== 3 || $parts[1] !== $this->currentKeyId;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
