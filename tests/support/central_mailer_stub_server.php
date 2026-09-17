<?php

declare(strict_types=1);

/**
 * Router dla `php -S` udajacy central-mailera w testach wysylki paczkowej.
 *
 * Kazde zadanie jest dopisywane jako linia JSON do pliku wskazanego przez STUB_LOG, dzieki
 * czemu test widzi realny podzial na paczki, klucze idempotencji i liste odbiorcow.
 * STUB_FAIL_CHUNKS (lista indeksow po przecinku) wymusza blad 500 dla wybranych paczek —
 * tak odtwarzamy czesciowa awarie wysylki.
 */

$logPath = (string)getenv('STUB_LOG');
// Lista paczek do odrzucenia jest czytana z pliku przy kazdym zadaniu, dzieki czemu jeden
// serwer obsluguje caly plik testowy zamiast startowac od nowa dla kazdego przypadku.
$failPath = (string)getenv('STUB_FAIL_FILE');
$failChunks = array_filter(
    array_map('trim', explode(',', $failPath !== '' && is_file($failPath) ? (string)file_get_contents($failPath) : '')),
    static fn (string $v): bool => $v !== ''
);

$body = file_get_contents('php://input');
$decoded = json_decode((string)$body, true);
$decoded = is_array($decoded) ? $decoded : [];

$recipients = is_array($decoded['recipients'] ?? null) ? $decoded['recipients'] : [];
$chunkIndex = $decoded['metadata']['chunkIndex'] ?? null;

$entry = [
    'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'idempotency_key' => (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
    'context_id' => $decoded['contextId'] ?? null,
    'chunk_index' => $chunkIndex,
    'recipient_count' => count($recipients),
    'recipient_emails' => array_map(static fn (array $r): string => (string)($r['to'] ?? ''), $recipients),
    'participant_ids' => array_map(
        static fn (array $r): int => (int)($r['metadata']['participantId'] ?? 0),
        $recipients
    ),
    'body_bytes' => strlen((string)$body),
];

if ($logPath !== '') {
    file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

if ($chunkIndex !== null && in_array((string)$chunkIndex, $failChunks, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Symulowana awaria paczki ' . $chunkIndex]);

    return true;
}

http_response_code(202);
echo json_encode(['id' => 'batch-' . ($chunkIndex ?? '0'), 'emails' => []]);

return true;
