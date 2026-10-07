<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/*
 * Re-encrypts stored platform organization tokens after a key rotation.
 *
 *   1. Set BZ_PLATFORM_TOKEN_KEY_PREVIOUS=<old key> and BZ_PLATFORM_TOKEN_KEY=<new key>.
 *   2. php scripts/reencrypt-platform-tokens.php           (dry run: counts only)
 *      php scripts/reencrypt-platform-tokens.php --apply   (writes)
 *   3. Remove BZ_PLATFORM_TOKEN_KEY_PREVIOUS.
 *
 * Prints counts and event ids only, never tokens or ciphertext.
 */

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php scripts/reencrypt-platform-tokens.php [--apply]\n";
    echo "Dry run by default. Requires BZ_PLATFORM_TOKEN_KEY and, for rotation, BZ_PLATFORM_TOKEN_KEY_PREVIOUS.\n";
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$config = PlatformIntegrationConfig::fromProcess();
$keyReasons = array_values(array_intersect(
    $config->unavailableReasons(),
    ['sodium_missing', 'token_key_missing', 'token_key_invalid', 'previous_token_key_invalid']
));
if ($keyReasons !== []) {
    fwrite(STDERR, 'Platform token key configuration is not usable: ' . implode(', ', $keyReasons) . "\n");
    exit(1);
}

$pdo = Database::connect();
// cipher() needs a fully available integration; this script only needs the key variables.
$current = base64_decode((string)getenv('BZ_PLATFORM_TOKEN_KEY'), true);
$previousRaw = trim((string)getenv('BZ_PLATFORM_TOKEN_KEY_PREVIOUS'));
$previous = $previousRaw !== '' ? base64_decode($previousRaw, true) : null;
$cipher = new PlatformTokenCipher((string)$current, $previous === false ? null : $previous);

$counts = ['current' => 0, 'to_reencrypt' => 0, 'unreadable' => 0, 'updated' => 0];
$unreadableIds = [];
$rows = $pdo->query('SELECT event_id, organization_token_ciphertext FROM event_platform_connections')->fetchAll();
foreach ($rows as $row) {
    $eventId = (string)$row['event_id'];
    $old = (string)$row['organization_token_ciphertext'];
    $plain = $cipher->decrypt($old, $eventId);
    if ($plain === null) {
        $counts['unreadable']++;
        $unreadableIds[] = $eventId;
        continue;
    }

    if (!$cipher->needsReencryption($old)) {
        $counts['current']++;
        continue;
    }

    $counts['to_reencrypt']++;
    if ($apply) {
        $stmt = $pdo->prepare('
            UPDATE event_platform_connections
            SET organization_token_ciphertext = :new_ciphertext
            WHERE event_id = :event_id AND organization_token_ciphertext = :old_ciphertext
        ');
        $stmt->execute([
            'new_ciphertext' => $cipher->encrypt($plain, $eventId),
            'event_id' => $eventId,
            'old_ciphertext' => $old,
        ]);
        $counts['updated'] += $stmt->rowCount();
    }
    unset($plain);
}

echo sprintf(
    "%s: current=%d to_reencrypt=%d unreadable=%d updated=%d\n",
    $apply ? 'apply' : 'dry-run',
    $counts['current'],
    $counts['to_reencrypt'],
    $counts['unreadable'],
    $counts['updated']
);
if ($unreadableIds !== []) {
    echo 'unreadable event ids: ' . implode(', ', $unreadableIds) . "\n";
}
