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
            subject: 'Reset hasła',
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
            subject: 'Twoje konto zostało utworzone',
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
        string $qrToken
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
                $eventName,
                $eventOfficeWindow,
                $eventLocation,
                $bibNumber
            );
            $mail->AltBody = self::participantQrText(
                $recipientName,
                $eventName,
                $eventOfficeWindow,
                $eventLocation,
                $bibNumber
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
<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:640px;margin:0 auto;padding:24px;">
  <h1 style="font-size:22px;margin:0 0 16px;">{$safeActionLabel}</h1>
  <p style="margin:0 0 12px;">Cześć {$safeName},</p>
  <p style="margin:0 0 12px;">{$safeIntroText}</p>
  <p style="margin:0 0 20px;">{$safeValidityText}</p>
  <p style="margin:0 0 20px;">
    <a href="{$safeUrl}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;">{$safeActionLabel}</a>
  </p>
  <p style="margin:0 0 12px;">Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:</p>
  <p style="margin:0 0 20px;word-break:break-all;"><a href="{$safeUrl}">{$safeUrl}</a></p>
  <p style="margin:0;color:#6b7280;">Jeśli to nie Ty, zignoruj tę wiadomość.</p>
</div>
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
        string $eventName,
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber
    ): string {
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Zawodniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventName = htmlspecialchars($eventName !== '' ? $eventName : 'Wydarzenie sportowe', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventOfficeWindow = htmlspecialchars($eventOfficeWindow !== '' ? $eventOfficeWindow : 'Do potwierdzenia', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventLocation = htmlspecialchars($eventLocation !== '' ? $eventLocation : 'Do potwierdzenia', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBibNumber = htmlspecialchars($bibNumber !== '' ? $bibNumber : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>Twój kod QR na wydarzenie</title>
</head>
<body style="margin:0;padding:0;background:#edf4f2;color:#102a43;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px;">Twój kod QR do szybkiej odprawy jest gotowy. Zachowaj tę wiadomość i pokaż ją w biurze zawodów.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background:#edf4f2;">
    <tr>
      <td align="center" style="padding:28px 12px;">
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;border-collapse:separate;border-spacing:0;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(16,42,67,0.14);">
          <tr>
            <td style="background:#102a43;padding:0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="padding:26px 28px 24px;color:#ffffff;">
                    <p style="margin:0 0 10px;font-size:12px;line-height:18px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#7ddfc8;">Pakiet startowy</p>
                    <h1 style="margin:0;font-size:30px;line-height:36px;font-weight:800;color:#ffffff;">Twój kod QR jest gotowy</h1>
                    <p style="margin:14px 0 0;font-size:16px;line-height:24px;color:#d8f3ec;">Cześć {$safeName}, pokaż ten kod przy wejściu albo w biurze zawodów, a odprawa pójdzie sprawnie.</p>
                  </td>
                </tr>
                <tr>
                  <td style="height:6px;line-height:6px;font-size:0;background:#ffb703;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="padding:0 0 18px;">
                    <p style="margin:0 0 8px;font-size:13px;line-height:18px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:#00a676;">Wydarzenie</p>
                    <h2 style="margin:0;font-size:24px;line-height:30px;font-weight:800;color:#102a43;">{$safeEventName}</h2>
                  </td>
                </tr>
              </table>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;border:1px solid #d8e6e1;border-radius:18px;background:#f8fbfa;">
                <tr>
                  <td style="padding:18px 18px 8px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                      <tr>
                        <td valign="top" style="padding:0 0 14px;">
                          <p style="margin:0 0 4px;font-size:12px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#627d98;">Biuro zawodów</p>
                          <p style="margin:0;font-size:16px;line-height:23px;font-weight:700;color:#102a43;">{$safeEventOfficeWindow}</p>
                        </td>
                      </tr>
                      <tr>
                        <td valign="top" style="padding:0 0 14px;">
                          <p style="margin:0 0 4px;font-size:12px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#627d98;">Miejsce</p>
                          <p style="margin:0;font-size:16px;line-height:23px;font-weight:700;color:#102a43;">{$safeEventLocation}</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td style="padding:0 18px 18px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background:#ffffff;border-radius:14px;">
                      <tr>
                        <td align="center" style="padding:18px 12px;border:1px solid #e6f0ed;border-radius:14px;">
                          <p style="margin:0 0 6px;font-size:12px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:#627d98;">Numer startowy</p>
                          <p style="margin:0;font-size:42px;line-height:46px;font-weight:800;color:#102a43;">#{$safeBibNumber}</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td align="center" style="padding:26px 0 10px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:320px;border-collapse:separate;border-spacing:0;background:#102a43;border-radius:24px;">
                      <tr>
                        <td align="center" style="padding:10px;">
                          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#ffffff;border-radius:18px;">
                            <tr>
                              <td align="center" style="padding:18px;">
                                <img src="cid:participant-qr" width="240" alt="Kod QR uczestnika" style="display:block;width:240px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;" />
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:0 18px 20px;">
                    <p style="margin:0;font-size:13px;line-height:20px;color:#627d98;">Ten sam kod QR jest dołączony do wiadomości jako załącznik, gdyby klient pocztowy nie wyświetlił obrazka.</p>
                  </td>
                </tr>
              </table>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#fff8e5;border:1px solid #ffe3a3;border-radius:16px;">
                <tr>
                  <td style="padding:16px 18px;">
                    <p style="margin:0;font-size:15px;line-height:23px;color:#4f3b00;"><strong style="color:#102a43;">Wskazówka:</strong> zapisz tę wiadomość w telefonie przed przyjazdem. Przy odprawie wystarczy pokazać ekran z kodem QR.</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:18px 28px 28px;background:#f8fbfa;border-top:1px solid #e6f0ed;">
              <p style="margin:0;font-size:12px;line-height:18px;color:#627d98;">Wiadomość została wygenerowana automatycznie przez Biuro Zawodów.</p>
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

    private static function participantQrText(
        string $recipientName,
        string $eventName,
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber
    ): string {
        $name = $recipientName !== '' ? $recipientName : 'Zawodniku';
        $resolvedEventName = $eventName !== '' ? $eventName : 'Wydarzenie sportowe';
        $resolvedEventOfficeWindow = $eventOfficeWindow !== '' ? $eventOfficeWindow : 'Do potwierdzenia';
        $resolvedEventLocation = $eventLocation !== '' ? $eventLocation : 'Do potwierdzenia';
        $resolvedBibNumber = $bibNumber !== '' ? $bibNumber : '-';

        return implode("\n\n", [
            "Cześć {$name},",
            'Twój kod QR do odprawy jest gotowy.',
            "Wydarzenie: {$resolvedEventName}",
            "Biuro zawodów: {$resolvedEventOfficeWindow}",
            "Miejsce: {$resolvedEventLocation}",
            "Numer startowy: #{$resolvedBibNumber}",
            'Zachowaj tę wiadomość i pokaż kod QR przy wejściu lub w biurze zawodów.',
            'Kod QR jest dołączony do tej wiadomości jako załącznik.',
        ]);
    }
}
