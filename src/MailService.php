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
        $imageUrl = qrCodeImageUrl($qrToken);
        $inlineQrSvg = QrCodeService::renderSvg($qrToken, 320, 10);

        try {
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->addStringEmbeddedImage(
                $inlineQrSvg,
                'participant-qr',
                'participant-qr.svg',
                PHPMailer::ENCODING_BASE64,
                'image/svg+xml'
            );
            $mail->isHTML(true);
            $mail->Subject = sprintf('Twój kod QR na wydarzenie %s', $eventName);
            $mail->Body = self::participantQrHtml(
                $recipientName,
                $eventName,
                $eventOfficeWindow,
                $eventLocation,
                $bibNumber,
                $imageUrl
            );
            $mail->AltBody = self::participantQrText(
                $recipientName,
                $eventName,
                $eventOfficeWindow,
                $eventLocation,
                $bibNumber,
                $imageUrl
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
        string $actionLabel,
        string $actionUrl
    ): void {
        $mail = self::createConfiguredMailer();

        try {
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = self::actionHtml($recipientName, $introText, $actionLabel, $actionUrl);
            $mail->AltBody = self::actionText($recipientName, $introText, $actionLabel, $actionUrl);
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

    private static function actionHtml(string $recipientName, string $introText, string $actionLabel, string $actionUrl): string
    {
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Użytkowniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeIntroText = htmlspecialchars($introText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActionLabel = htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:640px;margin:0 auto;padding:24px;">
  <h1 style="font-size:22px;margin:0 0 16px;">{$safeActionLabel}</h1>
  <p style="margin:0 0 12px;">Cześć {$safeName},</p>
  <p style="margin:0 0 12px;">{$safeIntroText}</p>
  <p style="margin:0 0 20px;">Link jest ważny przez 60 minut.</p>
  <p style="margin:0 0 20px;">
    <a href="{$safeUrl}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;">{$safeActionLabel}</a>
  </p>
  <p style="margin:0 0 12px;">Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:</p>
  <p style="margin:0 0 20px;word-break:break-all;"><a href="{$safeUrl}">{$safeUrl}</a></p>
  <p style="margin:0;color:#6b7280;">Jeśli to nie Ty, zignoruj tę wiadomość.</p>
</div>
HTML;
    }

    private static function actionText(string $recipientName, string $introText, string $actionLabel, string $actionUrl): string
    {
        $name = $recipientName !== '' ? $recipientName : 'Użytkowniku';

        return implode("\n\n", [
            "Cześć {$name},",
            $introText,
            'Link jest ważny przez 60 minut.',
            "{$actionLabel}: {$actionUrl}",
            'Jeśli to nie Ty, zignoruj tę wiadomość.',
        ]);
    }

    private static function participantQrHtml(
        string $recipientName,
        string $eventName,
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber,
        string $imageUrl
    ): string {
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Zawodniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventName = htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventOfficeWindow = htmlspecialchars($eventOfficeWindow, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventLocation = htmlspecialchars($eventLocation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBibNumber = htmlspecialchars($bibNumber !== '' ? $bibNumber : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeImageUrl = htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:640px;margin:0 auto;padding:24px;">
  <h1 style="font-size:24px;margin:0 0 16px;">Twój kod QR na wydarzenie</h1>
  <p style="margin:0 0 12px;">Cześć {$safeName},</p>
  <p style="margin:0 0 16px;">Poniżej znajdziesz kod QR potrzebny do szybkiej odprawy w biurze zawodów.</p>
  <div style="border:1px solid #e5e7eb;border-radius:16px;padding:16px;margin:0 0 20px;background:#f9fafb;">
    <p style="margin:0 0 8px;font-weight:700;">{$safeEventName}</p>
    <p style="margin:0 0 4px;">Biuro zawodów: {$safeEventOfficeWindow}</p>
    <p style="margin:0 0 12px;">Miejsce: {$safeEventLocation}</p>
    <p style="margin:0 0 16px;font-size:18px;font-weight:700;">Numer startowy: #{$safeBibNumber}</p>
    <div style="text-align:center;background:#ffffff;padding:16px;border-radius:12px;">
      <img src="cid:participant-qr" alt="Kod QR uczestnika" style="max-width:280px;width:100%;height:auto;display:block;margin:0 auto 8px;" />
      <p style="margin:0;font-size:12px;color:#6b7280;">Jeśli obraz nie jest widoczny, otwórz podgląd online:</p>
      <p style="margin:8px 0 0;word-break:break-all;"><a href="{$safeImageUrl}">{$safeImageUrl}</a></p>
    </div>
  </div>
  <p style="margin:0;color:#6b7280;">Zachowaj tę wiadomość i pokaż kod QR przy wejściu lub w biurze zawodów.</p>
</div>
HTML;
    }

    private static function participantQrText(
        string $recipientName,
        string $eventName,
        string $eventOfficeWindow,
        string $eventLocation,
        string $bibNumber,
        string $imageUrl
    ): string {
        $name = $recipientName !== '' ? $recipientName : 'Zawodniku';

        return implode("\n\n", [
            "Cześć {$name},",
            'Twój kod QR do odprawy jest gotowy.',
            "Wydarzenie: {$eventName}",
            "Biuro zawodów: {$eventOfficeWindow}",
            "Miejsce: {$eventLocation}",
            "Numer startowy: #{$bibNumber}",
            "Podgląd QR: {$imageUrl}",
        ]);
    }
}
