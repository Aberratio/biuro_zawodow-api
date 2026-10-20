<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function withReleaseMarker(string $contents, callable $callback): void
{
    $path = tempnam(sys_get_temp_dir(), 'release');
    file_put_contents($path, $contents);
    try {
        $callback($path);
    } finally {
        @unlink($path);
    }
}

test('reads the version and short commit of a tagged deploy', function (): void {
    withReleaseMarker("GIT_SHA=6d9302e4c1b0a7f3e2d1c0b9a8f7e6d5c4b3a291\nVERSION=v2026.10.0\n", function (string $path): void {
        assertSame(['version' => '2026.10.0', 'commit' => '6d9302e'], ReleaseInfo::fromFile($path));
    });
});

test('an untagged deploy has a commit but no version', function (): void {
    withReleaseMarker("GIT_SHA=6d9302e4c1b0a7f3e2d1c0b9a8f7e6d5c4b3a291\nVERSION=\n", function (string $path): void {
        assertSame(['version' => null, 'commit' => '6d9302e'], ReleaseInfo::fromFile($path));
    });
});

test('a missing marker gives nulls', function (): void {
    assertSame(['version' => null, 'commit' => null], ReleaseInfo::fromFile(sys_get_temp_dir() . '/no-such-release-marker'));
});
