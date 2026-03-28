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
            subject: 'Reset hasla',
            introText: 'Otrzymalismy prosbe o ustawienie nowego hasla do Twojego konta.',
            actionLabel: 'Ustaw nowe haslo',
            actionUrl: $resetUrl
        );
    }

    public static function sendAccountSetupEmail(string $recipientEmail, string $recipientName, string $resetUrl): void
    {
        self::sendActionEmail(
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            subject: 'Twoje konto zostalo utworzone',
            introText: 'Twoje konto zostalo utworzone. Aby rozpoczac prace, ustaw wlasne haslo.',
            actionLabel: 'Ustaw haslo',
            actionUrl: $resetUrl
        );
    }

    public static function sendParticipantQrEmail(
        string $recipientEmail,
        string $recipientName,
        string $eventName,
        string $eventDate,
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
            $mail->Subject = sprintf('Twoj kod QR na wydarzenie %s', $eventName);
            $mail->Body = self::participantQrHtml(
                $recipientName,
                $eventName,
                $eventDate,
                $eventLocation,
                $bibNumber,
                $imageUrl
            );
            $mail->AltBody = self::participantQrText(
                $recipientName,
                $eventName,
                $eventDate,
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
            throw new RuntimeException('PHPMailer is not installed. Run composer install in biuro_zawodow-api.');
        }

        $host = trim((string)(getenv('MAIL_HOST') ?: ''));
        $port = (int)(getenv('MAIL_PORT') ?: 0);
        $username = trim((string)(getenv('MAIL_USERNAME') ?: ''));
        $password = (string)(getenv('MAIL_PASSWORD') ?: '');
        $fromAddress = trim((string)(getenv('MAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string)(getenv('MAIL_FROM_NAME') ?: 'Biuro Zawodow'));

        if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromAddress === '') {
            throw new RuntimeException('SMTP is not configured. Fill MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS.');
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
        $message = 'Failed to send email: ' . $exception->getMessage();
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
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Uzytkowniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeIntroText = htmlspecialchars($introText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActionLabel = htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:640px;margin:0 auto;padding:24px;">
  <h1 style="font-size:22px;margin:0 0 16px;">{$safeActionLabel}</h1>
  <p style="margin:0 0 12px;">Czesc {$safeName},</p>
  <p style="margin:0 0 12px;">{$safeIntroText}</p>
  <p style="margin:0 0 20px;">Link jest wazny przez 60 minut.</p>
  <p style="margin:0 0 20px;">
    <a href="{$safeUrl}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;">{$safeActionLabel}</a>
  </p>
  <p style="margin:0 0 12px;">Jesli przycisk nie dziala, skopiuj ten adres do przegladarki:</p>
  <p style="margin:0 0 20px;word-break:break-all;"><a href="{$safeUrl}">{$safeUrl}</a></p>
  <p style="margin:0;color:#6b7280;">Jesli to nie Ty, zignoruj te wiadomosc.</p>
</div>
HTML;
    }

    private static function actionText(string $recipientName, string $introText, string $actionLabel, string $actionUrl): string
    {
        $name = $recipientName !== '' ? $recipientName : 'Uzytkowniku';

        return implode("\n\n", [
            "Czesc {$name},",
            $introText,
            'Link jest wazny przez 60 minut.',
            "{$actionLabel}: {$actionUrl}",
            'Jesli to nie Ty, zignoruj te wiadomosc.',
        ]);
    }

    private static function participantQrHtml(
        string $recipientName,
        string $eventName,
        string $eventDate,
        string $eventLocation,
        string $bibNumber,
        string $imageUrl
    ): string {
        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Zawodniku', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventName = htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventDate = htmlspecialchars($eventDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEventLocation = htmlspecialchars($eventLocation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBibNumber = htmlspecialchars($bibNumber !== '' ? $bibNumber : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeImageUrl = htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:640px;margin:0 auto;padding:24px;">
  <h1 style="font-size:24px;margin:0 0 16px;">Twoj kod QR na wydarzenie</h1>
  <p style="margin:0 0 12px;">Czesc {$safeName},</p>
  <p style="margin:0 0 16px;">Ponizej znajdziesz kod QR potrzebny do szybkiej odprawy w biurze zawodow.</p>
  <div style="border:1px solid #e5e7eb;border-radius:16px;padding:16px;margin:0 0 20px;background:#f9fafb;">
    <p style="margin:0 0 8px;font-weight:700;">{$safeEventName}</p>
    <p style="margin:0 0 4px;">Data: {$safeEventDate}</p>
    <p style="margin:0 0 12px;">Miejsce: {$safeEventLocation}</p>
    <p style="margin:0 0 16px;font-size:18px;font-weight:700;">Numer startowy: #{$safeBibNumber}</p>
    <div style="text-align:center;background:#ffffff;padding:16px;border-radius:12px;">
      <img src="cid:participant-qr" alt="Kod QR uczestnika" style="max-width:280px;width:100%;height:auto;display:block;margin:0 auto 8px;" />
      <p style="margin:0;font-size:12px;color:#6b7280;">Jesli obraz nie jest widoczny, otworz podglad online:</p>
      <p style="margin:8px 0 0;word-break:break-all;"><a href="{$safeImageUrl}">{$safeImageUrl}</a></p>
    </div>
  </div>
  <p style="margin:0;color:#6b7280;">Zachowaj te wiadomosc i pokaz kod QR przy wejsciu lub w biurze zawodow.</p>
</div>
HTML;
    }

    private static function participantQrText(
        string $recipientName,
        string $eventName,
        string $eventDate,
        string $eventLocation,
        string $bibNumber,
        string $imageUrl
    ): string {
        $name = $recipientName !== '' ? $recipientName : 'Zawodniku';

        return implode("\n\n", [
            "Czesc {$name},",
            'Twoj kod QR do odprawy jest gotowy.',
            "Wydarzenie: {$eventName}",
            "Data: {$eventDate}",
            "Miejsce: {$eventLocation}",
            "Numer startowy: #{$bibNumber}",
            "Podglad QR: {$imageUrl}",
        ]);
    }
}
