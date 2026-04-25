<?php

declare(strict_types=1);

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Matrix\MatrixInterface;
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
        $writer = new SvgWriter();
        return $writer->write(self::createQrCode($payload, $size, $margin))->getString();
    }

    public static function renderSvgDataUri(string $payload, int $size = 320, int $margin = 12): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::renderSvg($payload, $size, $margin));
    }

    public static function renderPng(string $payload, int $size = 320, int $margin = 12): string
    {
        if (!function_exists('gzcompress')) {
            throw new RuntimeException('Generowanie PNG wymaga wlaczonego rozszerzenia PHP zlib.');
        }

        $writer = new SvgWriter();
        $matrix = $writer->write(self::createQrCode($payload, $size, $margin))->getMatrix();

        return self::encodeMatrixAsPng($matrix);
    }

    private static function createQrCode(string $payload, int $size, int $margin): QrCode
    {
        return new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: $margin
        );
    }

    private static function encodeMatrixAsPng(MatrixInterface $matrix): string
    {
        $imageSize = $matrix->getOuterSize();
        $blockCount = $matrix->getBlockCount();
        $blockSize = $matrix->getBlockSize();
        $marginLeft = $matrix->getMarginLeft();
        $marginRightBoundary = $imageSize - $matrix->getMarginRight();
        $scanlines = '';

        for ($y = 0; $y < $imageSize; ++$y) {
            $scanlines .= "\x00";
            $rowIndex = $y >= $marginLeft && $y < $marginRightBoundary
                ? (int)floor(($y - $marginLeft) / $blockSize)
                : -1;

            for ($x = 0; $x < $imageSize; ++$x) {
                $columnIndex = $x >= $marginLeft && $x < $marginRightBoundary
                    ? (int)floor(($x - $marginLeft) / $blockSize)
                    : -1;

                $isDark = $rowIndex >= 0
                    && $rowIndex < $blockCount
                    && $columnIndex >= 0
                    && $columnIndex < $blockCount
                    && $matrix->getBlockValue($rowIndex, $columnIndex) === 1;

                $scanlines .= $isDark ? "\x00\x00\x00" : "\xff\xff\xff";
            }
        }

        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', $imageSize, $imageSize, 8, 2, 0, 0, 0))
            . self::pngChunk('IDAT', gzcompress($scanlines))
            . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
