<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$selectStmt = $pdo->query('SELECT id, qr_code FROM participants ORDER BY id ASC');
$participants = $selectStmt->fetchAll();

$updateStmt = $pdo->prepare('UPDATE participants SET qr_code = :qr_code WHERE id = :id');

$updated = 0;

foreach ($participants as $participant) {
    $existingQrCode = isset($participant['qr_code']) ? trim((string)$participant['qr_code']) : '';
    if (QrCodeService::isSecureToken($existingQrCode)) {
        continue;
    }

    do {
        $token = QrCodeService::generateToken();
        $collisionStmt = $pdo->prepare('SELECT id FROM participants WHERE qr_code = :qr_code LIMIT 1');
        $collisionStmt->execute(['qr_code' => $token]);
        $collision = $collisionStmt->fetch();
    } while ($collision !== false);

    $updateStmt->execute([
        'id' => (int)$participant['id'],
        'qr_code' => $token,
    ]);
    $updated++;
}

echo "Backfilled QR codes for {$updated} participant(s).\n";
