<?php

declare(strict_types=1);

/**
 * Reads the release marker that the deploy workflow writes next to the code
 * (`.release` in the API root). `VERSION` is set only when a vYYYY.MM.PATCH
 * release tag is deployed, to any environment; a local checkout has no marker
 * at all, so both values may be null.
 */
final class ReleaseInfo
{
    /**
     * @return array{version: ?string, commit: ?string}
     */
    public static function fromFile(string $path): array
    {
        $values = [];
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach ($lines ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[trim($key)] = trim($value);
        }

        $version = preg_replace('/^v/', '', $values['VERSION'] ?? '') ?? '';
        $commit = $values['GIT_SHA'] ?? '';

        return [
            'version' => $version !== '' ? $version : null,
            'commit' => preg_match('/^[0-9a-f]{7,40}$/i', $commit) === 1 ? substr($commit, 0, 7) : null,
        ];
    }
}
