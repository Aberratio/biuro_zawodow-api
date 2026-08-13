<?php

declare(strict_types=1);

final class MailService
{
    private const PRIORITY_NORMAL = 'normal';
    private const PRIORITY_TECHNICAL = 'technical';

    public static function sendPasswordResetEmail(string $recipientEmail, string $recipientName, string $resetUrl): void
    {
        self::sendActionEmail(
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            subject: 'Ustaw nowe hasło do Biura Zawodów',
            introText: 'Otrzymaliśmy prośbę o ustawienie nowego hasła do Twojego konta.',
            validityText: 'Link jest ważny przez 60 minut.',
            actionLabel: 'Ustaw nowe hasło',
            actionUrl: $resetUrl,
            metadataType: 'password_reset'
        );
    }

    public static function sendAccountSetupEmail(string $recipientEmail, string $recipientName, string $resetUrl): void
    {
        self::sendActionEmail(
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            subject: 'Aktywuj konto w Biurze Zawodów',
            introText: 'Twoje konto zostało utworzone. Aby rozpocząć pracę, ustaw własne hasło.',
            validityText: 'Link do ustawienia hasła jest ważny przez 7 dni.',
            actionLabel: 'Ustaw hasło',
            actionUrl: $resetUrl,
            metadataType: 'account_setup'
        );
    }

    public static function sendPasswordSetupEmail(string $recipientEmail, string $recipientName, string $resetUrl): void
    {
        self::sendActionEmail(
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            subject: 'Ustaw hasło do Biura Zawodów',
            introText: 'Administrator wysłał link do ustawienia hasła do Twojego konta.',
            validityText: 'Link do ustawienia hasła jest ważny przez 7 dni.',
            actionLabel: 'Ustaw hasło',
            actionUrl: $resetUrl,
            metadataType: 'password_setup'
        );
    }

    public static function sendParticipantQrEmail(
        string $recipientEmail,
        string $recipientName,
        string $eventName,
        array $officeLocations,
        string $eventLocation,
        string $bibNumber,
        string $qrToken,
        array $participantCustomFields = [],
        array $participantImportantFieldAliases = [],
        ?int $participantId = null,
        ?string $eventId = null
    ): void {
        $payload = self::participantQrPayload(
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            eventName: $eventName,
            officeLocations: $officeLocations,
            eventLocation: $eventLocation,
            bibNumber: $bibNumber,
            qrToken: $qrToken,
            participantCustomFields: $participantCustomFields,
            participantImportantFieldAliases: $participantImportantFieldAliases,
            participantId: $participantId,
            eventId: $eventId
        );
        if ($eventId !== null && $eventId !== '') {
            $payload['contextId'] = $eventId;
        }
        self::sendQueuedEmail(
            endpoint: '/emails',
            payload: $payload,
            idempotencyKey: self::idempotencyKey('participant-qr', $recipientEmail . '|' . $eventName . '|' . $qrToken . '|' . bin2hex(random_bytes(8)))
        );
    }

    public static function sendParticipantQrEmailBatch(array $messages, ?string $eventId = null): void
    {
        $recipients = [];
        foreach ($messages as $message) {
            $payload = self::participantQrPayload(
                recipientEmail: (string)($message['recipientEmail'] ?? ''),
                recipientName: (string)($message['recipientName'] ?? ''),
                eventName: (string)($message['eventName'] ?? ''),
                officeLocations: is_array($message['officeLocations'] ?? null) ? $message['officeLocations'] : [],
                eventLocation: (string)($message['eventLocation'] ?? ''),
                bibNumber: (string)($message['bibNumber'] ?? ''),
                qrToken: (string)($message['qrToken'] ?? ''),
                participantCustomFields: is_array($message['participantCustomFields'] ?? null) ? $message['participantCustomFields'] : [],
                participantImportantFieldAliases: is_array($message['participantImportantFieldAliases'] ?? null) ? $message['participantImportantFieldAliases'] : [],
                participantId: isset($message['participantId']) ? (int)$message['participantId'] : null,
                eventId: $eventId
            );
            unset($payload['priority']);
            $recipients[] = $payload;
        }

        if ($recipients === []) {
            return;
        }

        $batchSize = max(1, (int)(getenv('CENTRAL_MAILER_BATCH_SIZE') ?: 500));
        foreach (array_chunk($recipients, $batchSize) as $index => $recipientChunk) {
            $payload = [
                'subject' => 'Kody QR uczestnikow',
                'html' => '<p>Kod QR uczestnika jest gotowy.</p>',
                'text' => 'Kod QR uczestnika jest gotowy.',
                'priority' => self::PRIORITY_NORMAL,
                'metadata' => ['type' => 'participant_qr_batch', 'chunkIndex' => $index],
                'recipients' => $recipientChunk,
            ];
            if ($eventId !== null && $eventId !== '') {
                $payload['contextId'] = $eventId;
            }
            self::sendQueuedEmail(
                endpoint: '/emails/batch',
                payload: $payload,
                idempotencyKey: self::idempotencyKey('participant-qr-batch', json_encode($recipientChunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . '|' . $index . '|' . bin2hex(random_bytes(8)))
            );
        }
    }

    private static function sendActionEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $introText,
        string $validityText,
        string $actionLabel,
        string $actionUrl,
        string $metadataType
    ): void {
        self::sendQueuedEmail(
            endpoint: '/emails',
            payload: [
                'to' => $recipientEmail,
                'subject' => $subject,
                'html' => self::actionHtml($recipientName, $introText, $validityText, $actionLabel, $actionUrl),
                'text' => self::actionText($recipientName, $introText, $validityText, $actionLabel, $actionUrl),
                'priority' => self::PRIORITY_TECHNICAL,
                'metadata' => [
                    'type' => $metadataType,
                    'recipientName' => $recipientName,
                ],
            ],
            idempotencyKey: self::idempotencyKey($metadataType, $recipientEmail . '|' . $actionUrl)
        );
    }

    private static function participantQrPayload(
        string $recipientEmail,
        string $recipientName,
        string $eventName,
        array $officeLocations,
        string $eventLocation,
        string $bibNumber,
        string $qrToken,
        array $participantCustomFields,
        array $participantImportantFieldAliases,
        ?int $participantId = null,
        ?string $eventId = null
    ): array {
        $qrImage = QrCodeService::renderPng($qrToken, 320, 10);
        $qrBase64 = base64_encode($qrImage);
        $qrContentId = $participantId !== null && $participantId > 0
            ? 'participant-qr-' . $participantId
            : 'participant-qr-' . substr(hash('sha256', $qrToken), 0, 24);

        return [
            'to' => $recipientEmail,
            'subject' => sprintf('Twoj pakiet startowy i kod QR: %s', $eventName),
            'html' => self::participantQrHtml(
                $recipientName,
                $recipientEmail,
                $eventName,
                $officeLocations,
                $eventLocation,
                $bibNumber,
                'cid:' . $qrContentId,
                $participantCustomFields,
                $participantImportantFieldAliases
            ),
            'text' => self::participantQrText(
                $recipientName,
                $recipientEmail,
                $eventName,
                $officeLocations,
                $eventLocation,
                $bibNumber,
                $participantCustomFields,
                $participantImportantFieldAliases
            ),
            'priority' => self::PRIORITY_NORMAL,
            'metadata' => [
                'type' => 'participant_qr',
                'recipientName' => $recipientName,
                'eventName' => $eventName,
                'bibNumber' => $bibNumber,
                // eventId + participantId pozwalaja skorelowac status z mailera z uczestnikiem.
                'eventId' => $eventId,
                'participantId' => $participantId,
            ],
            // Jeden zalacznik: osadzony w tresci przez cid i jednoczesnie widoczny na liscie
            // zalacznikow. Osobna kopia "do pobrania" dawala dwa identyczne kody QR w Gmailu.
            'attachments' => [
                [
                    'filename' => 'kod-qr.png',
                    'contentBase64' => $qrBase64,
                    'contentId' => $qrContentId,
                    'inline' => true,
                ],
            ],
        ];
    }

    /**
     * Zwraca zdekodowana odpowiedz central-mailera (m.in. id maila / batcha),
     * dzieki czemu wysylki mozna pozniej odpytac o status dostarczenia.
     */
    private static function sendQueuedEmail(string $endpoint, array $payload, ?string $idempotencyKey = null): array
    {
        $payload['metadata'] = array_merge([
            'source' => 'biuro_zawodow',
            'requestId' => class_exists('RequestContext') ? RequestContext::id() : null,
        ], is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []);

        return self::request('POST', $endpoint, $payload, $idempotencyKey);
    }

    public static function getContextEmailStatuses(string $contextId, int $limit = 1000, int $offset = 0): array
    {
        $query = http_build_query(['limit' => max(1, $limit), 'offset' => max(0, $offset)]);

        return self::request('GET', '/emails/context/' . rawurlencode($contextId) . '?' . $query);
    }

    private static function request(string $method, string $endpoint, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $baseUrl = rtrim(trim((string)(getenv('CENTRAL_MAILER_URL') ?: '')), '/');
        $apiKey = trim((string)(getenv('CENTRAL_MAILER_API_KEY') ?: ''));
        $timeoutSeconds = max(1, (int)(getenv('CENTRAL_MAILER_TIMEOUT_SECONDS') ?: 10));

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Central-mailer nie jest skonfigurowany. Uzupelnij CENTRAL_MAILER_URL oraz CENTRAL_MAILER_API_KEY.');
        }

        $isEnqueue = $method === 'POST';
        $json = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json', 'X-API-Key: ' . $apiKey];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $httpOptions = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ];
        if ($json !== null) {
            $httpOptions['content'] = $json;
        }
        $context = stream_context_create(['http' => $httpOptions]);

        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException($message, $severity);
        });
        try {
            $responseBody = file_get_contents($baseUrl . $endpoint, false, $context);
            $responseHeaders = $http_response_header ?? [];
        } catch (Throwable $exception) {
            $runtimeException = new CentralMailerRequestException(
                ($isEnqueue
                    ? 'Nie udalo sie przekazac wiadomosci do central-mailer: '
                    : 'Nie udalo sie polaczyc z central-mailer: ') . $exception->getMessage(),
                503,
                0,
                $exception
            );
            self::logException($runtimeException, $isEnqueue ? 'central_mailer.enqueue_failed' : 'central_mailer.request_failed');
            throw $runtimeException;
        } finally {
            restore_error_handler();
        }

        $statusCode = self::extractHttpStatusCode($responseHeaders);
        if ($responseBody === false || $statusCode < 200 || $statusCode >= 300) {
            $error = self::extractErrorMessage(is_string($responseBody) ? $responseBody : '');
            $message = sprintf(
                $isEnqueue ? 'Central-mailer odrzucil wiadomosc e-mail (HTTP %d)%s.' : 'Central-mailer zwrocil blad (HTTP %d)%s.',
                $statusCode,
                $error !== '' ? ': ' . $error : ''
            );
            $runtimeException = new CentralMailerRequestException($message, self::apiStatusForCentralMailerStatus($statusCode));
            self::logException($runtimeException, $isEnqueue ? 'central_mailer.enqueue_rejected' : 'central_mailer.request_rejected');
            throw $runtimeException;
        }

        try {
            $decoded = json_decode((string)$responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function actionHtml(
        string $recipientName,
        string $introText,
        string $validityText,
        string $actionLabel,
        string $actionUrl
    ): string {
        $safeName = self::escape($recipientName !== '' ? $recipientName : 'Uzytkowniku');
        $safeIntroText = self::escape($introText);
        $safeValidityText = self::escape($validityText);
        $safeActionLabel = self::escape($actionLabel);
        $safeUrl = self::escape($actionUrl);

        return <<<HTML
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#07090d;color:#f8f5ef;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07090d;padding:18px 10px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#111419;border:1px solid #302d28;border-radius:18px;overflow:hidden;">
        <tr><td style="height:5px;background:#e5b761;"></td></tr>
        <tr><td style="padding:24px;">
          <h1 style="margin:0 0 12px;font-size:25px;line-height:31px;color:#f8f5ef;">{$safeActionLabel}</h1>
          <p style="margin:0 0 12px;font-size:15px;line-height:23px;color:#f8f5ef;">Cześć {$safeName},</p>
          <p style="margin:0 0 10px;font-size:15px;line-height:23px;color:#f8f5ef;">{$safeIntroText}</p>
          <p style="margin:0 0 18px;font-size:13px;line-height:20px;color:#b8b0a6;">{$safeValidityText}</p>
          <p style="margin:0 0 18px;text-align:center;"><a href="{$safeUrl}" style="display:inline-block;background:#e5b761;color:#19140d;text-decoration:none;font-size:15px;font-weight:800;padding:13px 22px;border-radius:12px;">{$safeActionLabel}</a></p>
          <p style="margin:0;font-size:12px;line-height:18px;color:#a79d91;">Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:<br><a href="{$safeUrl}" style="color:#e5b761;">{$safeUrl}</a></p>
        </td></tr>
        <tr><td style="padding:14px 24px;background:#0b0d12;border-top:1px solid #2e2a25;text-align:center;"><p style="margin:0;font-size:11px;color:#7e756b;">Jeśli to nie Ty, zignoruj tę wiadomość.</p></td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private static function actionText(string $recipientName, string $introText, string $validityText, string $actionLabel, string $actionUrl): string
    {
        $name = $recipientName !== '' ? $recipientName : 'Użytkowniku';

        return implode("\n\n", [
            "Cześć {$name},",
            $introText,
            $validityText,
            "{$actionLabel}: {$actionUrl}",
            'Jeśli to nie Ty, zignoruj tę wiadomość.',
        ]);
    }

    private static function participantQrHtml(
        string $recipientName,
        string $recipientEmail,
        string $eventName,
        array $officeLocations,
        string $eventLocation,
        string $bibNumber,
        string $qrImageUrl,
        array $participantCustomFields,
        array $participantImportantFieldAliases
    ): string {
        $safeName = self::escape($recipientName !== '' ? $recipientName : 'Zawodniku');
        $safeEmail = self::escape($recipientEmail !== '' ? $recipientEmail : '-');
        $safeEventName = self::escape($eventName !== '' ? $eventName : 'Wydarzenie sportowe');
        $safeBibNumber = self::escape($bibNumber !== '' ? $bibNumber : '-');
        $safeQrImageUrl = self::escape($qrImageUrl);
        $participantRow = self::detailHtmlRow('Uczestnik', $safeName);
        $emailRow = self::detailHtmlRow('E-mail', $safeEmail);
        $importantFieldRows = self::participantImportantFieldRows($participantCustomFields, $participantImportantFieldAliases);
        $eventLocationRow = self::eventLocationHtmlRow($eventLocation);
        $officeLocationsCard = self::officeLocationsHtmlCard($officeLocations);

        return <<<HTML
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#07090d;color:#f8f5ef;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07090d;padding:18px 10px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#111419;border:1px solid #302d28;border-radius:18px;overflow:hidden;table-layout:fixed;">
        <tr><td style="height:5px;background:#e5b761;"></td></tr>
        <tr><td style="padding:24px;">
          <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#e5b761;">Biuro Zawodów</p>
          <h1 style="margin:0 0 8px;font-size:25px;line-height:31px;color:#f8f5ef;overflow-wrap:anywhere;word-break:break-word;">{$safeEventName}</h1>
          <p style="margin:0 0 16px;font-size:14px;line-height:21px;color:#b8b0a6;">Cześć {$safeName}, to jest Twój kod QR do odprawy.</p>
          <div style="text-align:center;background:#171a20;border:1px solid #3a3228;border-radius:16px;padding:16px;margin-bottom:14px;">
            <img src="{$safeQrImageUrl}" width="220" alt="Kod QR uczestnika" style="display:block;width:220px;max-width:85%;height:auto;margin:0 auto 12px;background:#ffffff;padding:10px;border-radius:10px;">
            <p style="margin:0 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;color:#a79d91;">Numer startowy</p>
            <p style="margin:0;font-size:38px;line-height:42px;font-weight:800;color:#f8f5ef;overflow-wrap:anywhere;word-break:break-word;">#{$safeBibNumber}</p>
          </div>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#0b0d12;border:1px solid #2e2a25;border-radius:14px;table-layout:fixed;">
            {$participantRow}
            {$emailRow}
            {$importantFieldRows}
            {$eventLocationRow}
          </table>
          {$officeLocationsCard}
          <p style="margin:14px 0 0;font-size:12px;line-height:18px;color:#a79d91;text-align:center;">Kod QR znajdziesz też w załączniku tej wiadomości.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Wspolny wiersz tabeli szczegolow: przygaszona etykieta nad wartoscia, zeby etykiety i
     * wartosci nie zlewaly sie w jeden ciag pogrubionego tekstu.
     *
     * @param string $safeLabel juz zescape'owana etykieta
     * @param string $valueHtml juz zescape'owana wartosc (moze zawierac wlasne znaczniki)
     */
    private static function detailHtmlRow(string $safeLabel, string $valueHtml, bool $isLast = false): string
    {
        $border = $isLast ? '' : 'border-bottom:1px solid #26231f;';

        return '<tr><td style="padding:14px 16px;' . $border . 'overflow-wrap:anywhere;word-break:break-word;">'
            . '<span style="display:block;font-size:11px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:#a79d91;">' . $safeLabel . '</span>'
            . '<span style="display:block;margin-top:4px;font-size:15px;line-height:21px;color:#f8f5ef;">' . $valueHtml . '</span>'
            . '</td></tr>';
    }

    /**
     * Miejsce zawodow jako ostatni wiersz tabeli szczegolow, z linkiem do wyszukiwania w Mapach
     * Google - sam tekst adresu nie mowil uczestnikowi, jak tam dotrzec.
     */
    private static function eventLocationHtmlRow(string $eventLocation): string
    {
        $location = trim($eventLocation);
        if ($location === '') {
            return self::detailHtmlRow('Miejsce zawodów', 'Do potwierdzenia', true);
        }

        $valueHtml = self::escape($location)
            . '<br><a href="' . self::escape(self::googleMapsSearchUrl($location)) . '" style="display:inline-block;margin-top:8px;font-size:13px;color:#e5b761;text-decoration:underline;">Pokaż na mapie</a>';

        return self::detailHtmlRow('Miejsce zawodów', $valueHtml, true);
    }

    /**
     * Biuro zawodow ma wlasna kartę zamiast wierszy w tabeli szczegolow - nazwa lokalizacji
     * wygladala tam jak etykieta pola, a godziny jak jej wartosc.
     */
    private static function officeLocationsHtmlCard(array $officeLocations): string
    {
        $header = '<p style="margin:0 0 10px;font-size:11px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:#e5b761;">Biuro zawodów &middot; odbiór pakietu</p>';

        if ($officeLocations === []) {
            $body = '<p style="margin:0;font-size:15px;color:#f8f5ef;">Do potwierdzenia</p>';

            return self::officeLocationsCardWrapper($header . $body);
        }

        $blocks = [];
        foreach ($officeLocations as $index => $location) {
            $name = trim((string)($location['name'] ?? ''));
            $safeName = self::escape($name !== '' ? $name : 'Biuro zawodów');
            $googleMapsUrl = trim((string)($location['google_maps_url'] ?? ''));
            if ($googleMapsUrl === '' && $name !== '') {
                $googleMapsUrl = self::googleMapsSearchUrl($name);
            }
            $ranges = is_array($location['ranges'] ?? null) ? $location['ranges'] : [];

            $block = $index > 0
                ? '<div style="height:1px;background:#26231f;margin:16px 0;"></div>'
                : '';
            $block .= '<p style="margin:0;font-size:17px;line-height:23px;font-weight:700;color:#f8f5ef;overflow-wrap:anywhere;word-break:break-word;">' . $safeName . '</p>';

            if ($ranges !== []) {
                $block .= '<p style="margin:12px 0 6px;font-size:11px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:#a79d91;">Godziny otwarcia</p>';
                foreach ($ranges as $range) {
                    $block .= '<p style="margin:0 0 4px;font-size:14px;line-height:20px;color:#e7e1d8;overflow-wrap:anywhere;word-break:break-word;">'
                        . self::escape((string)$range) . '</p>';
                }
            }

            if ($googleMapsUrl !== '') {
                $block .= '<a href="' . self::escape($googleMapsUrl) . '" style="display:inline-block;margin-top:12px;padding:9px 16px;background:#e5b761;color:#111419;font-size:13px;font-weight:700;text-decoration:none;border-radius:8px;">Pokaż na mapie</a>';
            }

            $blocks[] = $block;
        }

        return self::officeLocationsCardWrapper($header . implode('', $blocks));
    }

    private static function officeLocationsCardWrapper(string $innerHtml): string
    {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;margin-top:14px;background:#0b0d12;border:1px solid #2e2a25;border-radius:14px;table-layout:fixed;">'
            . '<tr><td style="padding:16px;">' . $innerHtml . '</td></tr></table>';
    }

    private static function googleMapsSearchUrl(string $query): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    private static function participantImportantFieldRows(array $customFields, array $importantFieldAliases): string
    {
        $rows = [];
        foreach (self::participantImportantFields($customFields, $importantFieldAliases) as $label => $value) {
            $rows[] = self::detailHtmlRow(self::escape($label), self::escape($value));
        }

        return implode("\n", $rows);
    }

    private static function participantImportantFieldTextLines(array $customFields, array $importantFieldAliases): array
    {
        $lines = [];
        foreach (self::participantImportantFields($customFields, $importantFieldAliases) as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        return $lines;
    }

    private static function participantImportantFields(array $customFields, array $importantFieldAliases): array
    {
        $fields = [];
        $seenAliases = [];

        foreach ($importantFieldAliases as $alias) {
            $label = trim((string)$alias);
            if ($label === '' || isset($seenAliases[$label])) {
                continue;
            }

            $value = trim((string)($customFields[$label] ?? ''));
            if ($value === '') {
                continue;
            }

            $fields[$label] = $value;
            $seenAliases[$label] = true;
        }

        return $fields;
    }

    private static function participantQrText(
        string $recipientName,
        string $recipientEmail,
        string $eventName,
        array $officeLocations,
        string $eventLocation,
        string $bibNumber,
        array $participantCustomFields,
        array $participantImportantFieldAliases
    ): string {
        $name = $recipientName !== '' ? $recipientName : 'Zawodniku';
        $email = $recipientEmail !== '' ? $recipientEmail : '-';
        $resolvedEventName = $eventName !== '' ? $eventName : 'Wydarzenie sportowe';
        $resolvedEventLocation = $eventLocation !== '' ? $eventLocation : 'Do potwierdzenia';
        $resolvedBibNumber = $bibNumber !== '' ? $bibNumber : '-';
        $importantFieldLines = self::participantImportantFieldTextLines($participantCustomFields, $participantImportantFieldAliases);
        $officeLocationsLines = self::officeLocationsTextLines($officeLocations);

        return implode("\n\n", [
            "Cześć {$name},",
            'Twój kod QR do odprawy jest gotowy.',
            "Wydarzenie: {$resolvedEventName}",
            "Uczestnik: {$name}",
            "E-mail: {$email}",
            ...$importantFieldLines,
            "Numer startowy: #{$resolvedBibNumber}",
            "Miejsce zawodów: {$resolvedEventLocation}",
            implode("\n", $officeLocationsLines),
            'Zachowaj tę wiadomość i pokaż kod QR przy wejściu lub w biurze zawodów.',
            'Kod QR jest dołączony do tej wiadomości jako załącznik.',
        ]);
    }

    private static function officeLocationsTextLines(array $officeLocations): array
    {
        if ($officeLocations === []) {
            return ['BIURO ZAWODÓW - ODBIÓR PAKIETU', 'Do potwierdzenia'];
        }

        $lines = ['BIURO ZAWODÓW - ODBIÓR PAKIETU'];
        foreach ($officeLocations as $location) {
            $name = trim((string)($location['name'] ?? ''));
            $resolvedName = $name !== '' ? $name : 'Biuro zawodów';
            $googleMapsUrl = trim((string)($location['google_maps_url'] ?? ''));
            if ($googleMapsUrl === '' && $name !== '') {
                $googleMapsUrl = self::googleMapsSearchUrl($name);
            }
            $ranges = is_array($location['ranges'] ?? null) ? $location['ranges'] : [];

            $lines[] = $resolvedName;
            if ($ranges !== []) {
                $lines[] = 'Godziny otwarcia:';
                foreach ($ranges as $range) {
                    $lines[] = '  ' . (string)$range;
                }
            }
            if ($googleMapsUrl !== '') {
                $lines[] = 'Mapa: ' . $googleMapsUrl;
            }
        }

        return $lines;
    }

    private static function extractHttpStatusCode(array $headers): int
    {
        $statusLine = (string)($headers[0] ?? '');
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    private static function extractErrorMessage(string $responseBody): string
    {
        if ($responseBody === '') {
            return '';
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return mb_substr($responseBody, 0, 500);
        }

        if (is_array($decoded)) {
            foreach (['error', 'message'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }
        }

        return '';
    }

    private static function apiStatusForCentralMailerStatus(int $statusCode): int
    {
        return match (true) {
            $statusCode === 400 => 422,
            $statusCode === 413 => 413,
            $statusCode === 429 => 429,
            $statusCode >= 500 => 502,
            default => 502,
        };
    }

    private static function idempotencyKey(string $type, string $value): string
    {
        return sprintf('biuro-zawodow:%s:%s', $type, hash('sha256', $value));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function logException(Throwable $exception, string $context): void
    {
        if (class_exists('ApplicationLogger')) {
            ApplicationLogger::logException($exception, $context);
        }
    }
}

final class CentralMailerRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $apiStatusCode,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function apiStatusCode(): int
    {
        return $this->apiStatusCode;
    }
}
