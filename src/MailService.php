<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class MailService
{
    public static function sendPasswordResetEmail(string $recipientEmail, string $recipientName, string $resetUrl): void
    {
        self::sendActionEmail(
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            subject: 'Ustaw nowe hasło do Biura Zawodów',
            introText: 'Otrzymaliśmy prośbę o ustawienie nowego hasła do Twojego konta.',
            validityText: 'Link jest ważny przez 60 minut.',
            actionLabel: 'Ustaw nowe hasło',
            actionUrl: $resetUrl
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
            actionUrl: $resetUrl
        );
    }

    public static function sendParticipantQrEmail(
        string $recipientEmail,
        string $recipientName,
        string $eventName,
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber,
        string $qrToken,
        array $participantCustomFields = [],
        array $participantImportantFieldAliases = []
    ): void {
        $mail = self::createConfiguredMailer();
        $qrImage = QrCodeService::renderPng($qrToken, 320, 10);
        $qrMimeType = 'image/png';
        $qrFilename = 'kod-qr.png';

        try {
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->addStringEmbeddedImage(
                $qrImage,
                'participant-qr',
                $qrFilename,
                PHPMailer::ENCODING_BASE64,
                $qrMimeType
            );
            $mail->addStringAttachment(
                $qrImage,
                $qrFilename,
                PHPMailer::ENCODING_BASE64,
                $qrMimeType
            );
            $mail->isHTML(true);
            $mail->Subject = sprintf('Twój pakiet startowy i kod QR: %s', $eventName);
            $mail->Body = self::participantQrHtml(
                $recipientName,
                $recipientEmail,
                $eventName,
                $eventOfficeWindow,
                $eventLocation,
                $bibNumber,
                $participantCustomFields,
                $participantImportantFieldAliases
            );
            $mail->AltBody = self::participantQrText(
                $recipientName,
                $recipientEmail,
                $eventName,
                $eventOfficeWindow,
                $eventLocation,
                $bibNumber,
                $participantCustomFields,
                $participantImportantFieldAliases
            );
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw self::buildSendException($mail, $exception);
        }
    }

    private static function sendActionEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $introText,
        string $validityText,
        string $actionLabel,
        string $actionUrl
    ): void {
        $mail = self::createConfiguredMailer();

        try {
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = self::actionHtml($recipientName, $introText, $validityText, $actionLabel, $actionUrl);
            $mail->AltBody = self::actionText($recipientName, $introText, $validityText, $actionLabel, $actionUrl);
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw self::buildSendException($mail, $exception);
        }
    }

    private static function createConfiguredMailer(): PHPMailer
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer nie jest zainstalowany. Uruchom composer install w katalogu biuro_zawodow-api.');
        }

        $host = trim((string)(getenv('MAIL_HOST') ?: ''));
        $port = (int)(getenv('MAIL_PORT') ?: 0);
        $username = trim((string)(getenv('MAIL_USERNAME') ?: ''));
        $password = (string)(getenv('MAIL_PASSWORD') ?: '');
        $fromAddress = trim((string)(getenv('MAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string)(getenv('MAIL_FROM_NAME') ?: 'Biuro Zawodów'));

        if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromAddress === '') {
            throw new RuntimeException('SMTP nie jest skonfigurowane. Uzupełnij MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD oraz MAIL_FROM_ADDRESS.');
        }

        $encryption = strtolower(trim((string)(getenv('MAIL_ENCRYPTION') ?: 'tls')));
        $mail = new PHPMailer(true);
        $debugBuffer = '';

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->CharSet = 'UTF-8';

        if ((getenv('APP_DEBUG') ?: 'false') === 'true') {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $message, int $level) use (&$debugBuffer): void {
                $debugBuffer .= "[{$level}] {$message}\n";
            };
        }

        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($fromAddress, $fromName);
        if ($debugBuffer !== '') {
            $mail->setCustomHeader('X-Debug-Buffer', base64_encode($debugBuffer));
        }

        return $mail;
    }

    private static function buildSendException(PHPMailer $mail, PHPMailerException $exception): RuntimeException
    {
        $message = 'Nie udało się wysłać wiadomości e-mail: ' . $exception->getMessage();
        if ((getenv('APP_DEBUG') ?: 'false') === 'true') {
            foreach ($mail->getCustomHeaders() as $header) {
                if (($header[0] ?? '') !== 'X-Debug-Buffer') {
                    continue;
                }

                $decoded = base64_decode((string)($header[1] ?? ''), true);
                if ($decoded !== false && $decoded !== '') {
                    $message .= "\nSMTP debug:\n" . trim($decoded);
                }
                break;
            }
        }

        return new RuntimeException($message, 0, $exception);
    }

    private static function actionHtml(
        string $recipientName,
        string $introText,
        string $validityText,
        string $actionLabel,
        string $actionUrl
    ): string {
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Użytkowniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeIntroText = htmlspecialchars($introText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeValidityText = htmlspecialchars($validityText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActionLabel = htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark light">
  <meta name="supported-color-schemes" content="dark light">
  <title>{$safeActionLabel}</title>
</head>
<body style="margin:0;padding:0;background:#07090d;color:#f8f5ef;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px;">{$safeIntroText} {$safeValidityText}</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background:#07090d;">
    <tr>
      <td align="center" style="padding:18px 10px;">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;border-spacing:0;background:#111419;border:1px solid #302d28;border-radius:22px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,0.45);">
          <tr>
            <td style="height:5px;line-height:5px;font-size:0;background:#e5b761;">&nbsp;</td>
          </tr>
          <tr>
            <td style="padding:22px 22px 18px;background:#111419;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="padding:0 0 10px;">
                    <p style="margin:0;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:#e5b761;">Biuro Zawodów</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:0;">
                    <h1 style="margin:0;font-size:25px;line-height:31px;font-weight:800;color:#f8f5ef;">{$safeActionLabel}</h1>
                    <p style="margin:8px 0 0;font-size:14px;line-height:21px;color:#b8b0a6;">Cześć {$safeName},</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 22px 22px;background:#111419;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#171a20;border:1px solid #3a3228;border-radius:18px;">
                <tr>
                  <td style="padding:18px 16px 8px;">
                    <p style="margin:0;font-size:15px;line-height:23px;color:#f8f5ef;">{$safeIntroText}</p>
                    <p style="margin:10px 0 0;font-size:13px;line-height:20px;color:#b8b0a6;">{$safeValidityText}</p>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:12px 16px 18px;">
                    <a href="{$safeUrl}" style="display:inline-block;background:#e5b761;color:#19140d;text-decoration:none;font-size:15px;line-height:20px;font-weight:800;padding:13px 22px;border-radius:14px;border:1px solid #f0cc80;">{$safeActionLabel}</a>
                  </td>
                </tr>
              </table>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#0b0d12;border:1px solid #2e2a25;border-radius:16px;margin-top:14px;">
                <tr>
                  <td style="padding:14px 16px;">
                    <p style="margin:0 0 6px;font-size:12px;line-height:18px;color:#a79d91;">Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:</p>
                    <p style="margin:0;font-size:13px;line-height:19px;word-break:break-all;"><a href="{$safeUrl}" style="color:#e5b761;text-decoration:underline;">{$safeUrl}</a></p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:14px 22px 20px;background:#0b0d12;border-top:1px solid #2e2a25;">
              <p style="margin:0;font-size:11px;line-height:16px;color:#7e756b;">Jeśli to nie Ty, zignoruj tę wiadomość. Link wygaśnie automatycznie.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private static function actionText(
        string $recipientName,
        string $introText,
        string $validityText,
        string $actionLabel,
        string $actionUrl
    ): string {
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
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber,
        array $participantCustomFields,
        array $participantImportantFieldAliases
    ): string {
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Zawodniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail = htmlspecialchars($recipientEmail !== '' ? $recipientEmail : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventName = htmlspecialchars($eventName !== '' ? $eventName : 'Wydarzenie sportowe', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventOfficeWindow = htmlspecialchars($eventOfficeWindow !== '' ? $eventOfficeWindow : 'Do potwierdzenia', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventLocation = htmlspecialchars($eventLocation !== '' ? $eventLocation : 'Do potwierdzenia', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBibNumber = htmlspecialchars($bibNumber !== '' ? $bibNumber : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $importantFieldRows = self::participantImportantFieldRows($participantCustomFields, $participantImportantFieldAliases);

        return <<<HTML
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark light">
  <meta name="supported-color-schemes" content="dark light">
  <title>Twój kod QR na wydarzenie</title>
</head>
<body style="margin:0;padding:0;background:#07090d;color:#f8f5ef;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px;">Kod QR na {$safeEventName}. Numer startowy #{$safeBibNumber}. Biuro zawodów: {$safeEventOfficeWindow}.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background:#07090d;">
    <tr>
      <td align="center" style="padding:18px 10px;">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;border-spacing:0;background:#111419;border:1px solid #302d28;border-radius:22px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,0.45);">
          <tr>
            <td style="height:5px;line-height:5px;font-size:0;background:#e5b761;">&nbsp;</td>
          </tr>
          <tr>
            <td style="padding:22px 22px 14px;background:#111419;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="padding:0 0 10px;">
                    <p style="margin:0;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:#e5b761;">Biuro Zawodów</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:0;">
                    <h1 style="margin:0;font-size:25px;line-height:31px;font-weight:800;color:#f8f5ef;">{$safeEventName}</h1>
                    <p style="margin:8px 0 0;font-size:14px;line-height:21px;color:#b8b0a6;">Cześć {$safeName}, to jest Twój kod QR do odprawy.</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 22px 22px;background:#111419;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#171a20;border:1px solid #3a3228;border-radius:18px;">
                <tr>
                  <td align="center" style="padding:16px 16px 12px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:316px;border-collapse:separate;border-spacing:0;background:#e5b761;border-radius:18px;">
                      <tr>
                        <td align="center" style="padding:8px;">
                          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#ffffff;border-radius:13px;">
                            <tr>
                              <td align="center" style="padding:14px;">
                                <img src="cid:participant-qr" width="252" alt="Kod QR uczestnika" style="display:block;width:252px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;" />
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:0 16px 18px;">
                    <p style="margin:0 0 4px;font-size:12px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:1.3px;color:#a79d91;">Numer startowy</p>
                    <p style="margin:0;font-size:38px;line-height:42px;font-weight:800;color:#f8f5ef;">#{$safeBibNumber}</p>
                  </td>
                </tr>
              </table>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#0b0d12;border:1px solid #2e2a25;border-radius:16px;margin-top:14px;">
                <tr>
                  <td style="padding:14px 16px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                      <tr>
                        <td style="padding:0 0 10px;border-bottom:1px solid #26231f;">
                          <p style="margin:0 0 3px;font-size:11px;line-height:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#a79d91;">Uczestnik</p>
                          <p style="margin:0;font-size:15px;line-height:21px;font-weight:700;color:#f8f5ef;">{$safeName}</p>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:10px 0;border-bottom:1px solid #26231f;">
                          <p style="margin:0 0 3px;font-size:11px;line-height:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#a79d91;">E-mail</p>
                          <p style="margin:0;font-size:15px;line-height:21px;color:#f8f5ef;word-break:break-word;">{$safeEmail}</p>
                        </td>
                      </tr>
                      {$importantFieldRows}
                      <tr>
                        <td style="padding:10px 0;border-bottom:1px solid #26231f;">
                          <p style="margin:0 0 3px;font-size:11px;line-height:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#a79d91;">Biuro zawodów</p>
                          <p style="margin:0;font-size:15px;line-height:21px;color:#f8f5ef;">{$safeEventOfficeWindow}</p>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:10px 0 0;">
                          <p style="margin:0 0 3px;font-size:11px;line-height:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#a79d91;">Lokalizacja</p>
                          <p style="margin:0;font-size:15px;line-height:21px;color:#f8f5ef;">{$safeEventLocation}</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
              <p style="margin:12px 0 0;font-size:12px;line-height:18px;color:#a79d91;text-align:center;">Kod QR jest też w załączniku, gdyby klient pocztowy nie wyświetlił obrazka.</p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:14px 22px 20px;background:#0b0d12;border-top:1px solid #2e2a25;">
              <p style="margin:0;font-size:11px;line-height:16px;color:#7e756b;">Wiadomość automatyczna. Pokaż kod QR przy wejściu lub w biurze zawodów.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private static function participantImportantFieldRows(array $customFields, array $importantFieldAliases): string
    {
        $rows = [];
        foreach (self::participantImportantFields($customFields, $importantFieldAliases) as $label => $value) {
            $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows[] = <<<HTML
                      <tr>
                        <td style="padding:10px 0;border-bottom:1px solid #26231f;">
                          <p style="margin:0 0 3px;font-size:11px;line-height:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#a79d91;">{$safeLabel}</p>
                          <p style="margin:0;font-size:15px;line-height:21px;color:#f8f5ef;word-break:break-word;">{$safeValue}</p>
                        </td>
                      </tr>
HTML;
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
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber,
        array $participantCustomFields,
        array $participantImportantFieldAliases
    ): string {
        $name = $recipientName !== '' ? $recipientName : 'Zawodniku';
        $email = $recipientEmail !== '' ? $recipientEmail : '-';
        $resolvedEventName = $eventName !== '' ? $eventName : 'Wydarzenie sportowe';
        $resolvedEventOfficeWindow = $eventOfficeWindow !== '' ? $eventOfficeWindow : 'Do potwierdzenia';
        $resolvedEventLocation = $eventLocation !== '' ? $eventLocation : 'Do potwierdzenia';
        $resolvedBibNumber = $bibNumber !== '' ? $bibNumber : '-';
        $importantFieldLines = self::participantImportantFieldTextLines($participantCustomFields, $participantImportantFieldAliases);

        return implode("\n\n", [
            "Cześć {$name},",
            'Twój kod QR do odprawy jest gotowy.',
            "Wydarzenie: {$resolvedEventName}",
            "Uczestnik: {$name}",
            "E-mail: {$email}",
            ...$importantFieldLines,
            "Biuro zawodów: {$resolvedEventOfficeWindow}",
            "Miejsce: {$resolvedEventLocation}",
            "Numer startowy: #{$resolvedBibNumber}",
            'Zachowaj tę wiadomość i pokaż kod QR przy wejściu lub w biurze zawodów.',
            'Kod QR jest dołączony do tej wiadomości jako załącznik.',
        ]);
    }
}
