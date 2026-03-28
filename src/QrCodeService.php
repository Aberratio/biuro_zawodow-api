<?php

declare(strict_types=1);

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

final class QrCodeService
{
    public static function generateToken(): string
    {
        return 'pqr_' . bin2hex(random_bytes(24));
    }

    public static function isSecureToken(?string $token): bool
    {
        if (!is_string($token)) {
            return false;
        }

        return preg_match('/^pqr_[a-f0-9]{48}$/', $token) === 1;
    }

    public static function renderSvg(string $payload, int $size = 320, int $margin = 12): string
    {
        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: $margin
        );

        $writer = new SvgWriter();
        return $writer->write($qrCode)->getString();
    }

    public static function renderSvgDataUri(string $payload, int $size = 320, int $margin = 12): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::renderSvg($payload, $size, $margin));
    }
}
