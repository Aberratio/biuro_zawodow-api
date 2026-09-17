<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Endpoint /events/{id}/send-qr-emails zyje w public/index.php i nie da sie go wywolac
 * jednostkowo, wiec — zgodnie z konwencja test_event_sandbox_test.php — pilnujemy jego
 * struktury na poziomie zrodla. Chodzi o rzeczy, ktorych zlamanie oznacza duplikaty maili
 * u uczestnikow albo ciche zgubienie calej wysylki.
 */
function sendQrEmailsHandlerSource(): string
{
    $source = (string)file_get_contents(__DIR__ . '/../public/index.php');
    $start = strpos($source, "preg_match('#^/events/([^/]+)/send-qr-emails\$#'");
    assertTrue($start !== false, 'Nie znaleziono handlera send-qr-emails');

    $end = strpos($source, "if (\$path === '/sync/ingest'", (int)$start);
    assertTrue($end !== false, 'Nie znaleziono konca handlera send-qr-emails');

    return substr($source, (int)$start, (int)$end - (int)$start);
}

test('send-qr-emails asks the mailer who already got an email before sending', function (): void {
    $handler = sendQrEmailsHandlerSource();

    $guard = strpos($handler, 'participantIdsAlreadyInMailer');
    $send = strpos($handler, 'MailService::sendParticipantQrEmailBatch(');

    assertTrue($guard !== false, 'Brak odpytania mailera o juz wyslane maile');
    assertTrue($send !== false, 'Brak wywolania wysylki paczkowej');
    assertTrue($guard < $send, 'Sprawdzenie duplikatow musi poprzedzac wysylke');
});

test('send-qr-emails skips participants the mailer already has', function (): void {
    $handler = sendQrEmailsHandlerSource();

    assertStringContains("isset(\$alreadyInMailer[(int)\$participantRow['id']])", $handler);
});

test('send-qr-emails refuses to send when the mailer status cannot be read', function (): void {
    // Bez potwierdzenia z mailera slepa wysylka do 1600 osob to 1600 duplikatow.
    $handler = sendQrEmailsHandlerSource();

    assertStringContains("'code' => 'mailer_status_unavailable'", $handler);
    assertStringContains('jsonResponse(503, [', $handler);
});

test('send-qr-emails marks only the participants the mailer confirmed', function (): void {
    $handler = sendQrEmailsHandlerSource();

    // Oznaczamy po wyniku z mailera, nie hurtem po wszystkich zakolejkowanych wierszach.
    assertStringContains("foreach (\$batchResult['sentParticipantIds'] as \$participantId)", $handler);
    assertStringContains("\$sentCount = count(\$batchResult['sentParticipantIds']);", $handler);
    assertStringContains("foreach (\$batchResult['failures'] as \$failure)", $handler);
});

test('send-qr-emails no longer fails the whole send on one bad chunk', function (): void {
    // Stara wersja lapala jeden wyjatek na cala wysylke i nie oznaczala nikogo jako
    // wyslanego, mimo ze wczesniejsze paczki juz trafily do kolejki.
    $handler = sendQrEmailsHandlerSource();

    assertFalse(str_contains($handler, "\$errorCount = count(\$queuedParticipantRows);"));
});

test('send-qr-emails reconciles local email_status with the mailer', function (): void {
    $handler = sendQrEmailsHandlerSource();

    assertStringContains("'reconciled_count' => \$reconciledCount", $handler);
    assertStringContains('$reconciledCount++', $handler);
});

test('duplicate guard stays off the explicit resend-all path and off test events', function (): void {
    // resend_all to swiadoma decyzja operatora o ponownej wysylce do wszystkich, a
    // wydarzenie testowe nigdy nie dotyka mailera.
    $handler = sendQrEmailsHandlerSource();

    assertStringContains('if (!$isTestEvent($event) && !$resendAll) {', $handler);
});
