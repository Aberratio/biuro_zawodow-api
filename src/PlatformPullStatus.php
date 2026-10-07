<?php

declare(strict_types=1);

/** Status codes of a platform pull and their Polish texts. */
final class PlatformPullStatus
{
    private const UNEXPECTED = 'Platforma zwróciła nieoczekiwaną odpowiedź.';

    private const TEXTS = [
        'ok' => 'Pobrano listę uczestników z platformy.',
        'partial' => 'Pobrano listę uczestników z platformy, ale część rekordów pominięto.',
        'connection_disabled' => 'Integracja z platformą jest wyłączona dla tego wydarzenia.',
        'connection_missing' => 'To wydarzenie nie ma zapisanego połączenia z platformą.',
        'token_unreadable' => 'Zapisanego tokenu organizacji nie da się odczytać. Wklej token ponownie i zapisz.',
        'integration_unavailable' => 'Integracja z platformą jest nieaktywna na tym serwerze.',
        'event_inactive' => 'Wydarzenie jest zarchiwizowane lub usunięte.',
        'event_not_found' => 'Nie znaleziono wydarzenia.',
        'in_progress' => 'Pobieranie uczestników z platformy już trwa. Spróbuj za chwilę.',
        'app_key_rejected' => 'Platforma odrzuciła klucz aplikacji Biura Zawodów. Skontaktuj się z administratorem serwera.',
        'token_rejected' => 'Platforma odrzuciła token organizacji albo integracja z Biurem Zawodów nie jest włączona dla tego wydarzenia na platformie.',
        'platform_event_not_found' => 'Platforma nie zna wydarzenia o tym ID.',
        'platform_rate_limited' => 'Platforma chwilowo ogranicza liczbę zapytań. Spróbuj za chwilę.',
        'network_error' => 'Nie udało się połączyć z platformą (brak odpowiedzi lub przekroczony czas).',
        'invalid_response' => self::UNEXPECTED,
        'platform_error' => self::UNEXPECTED,
        'response_too_large' => 'Platforma zwróciła zbyt dużą odpowiedź.',
        'apply_failed' => 'Pobieranie przerwał błąd zapisu w bazie. Część zmian mogła zostać zapisana — spróbuj ponownie.',
    ];

    private const SKIP_REASONS = [
        'qr_collision' => 'kod QR jest już używany przez innego uczestnika (np. w innym wydarzeniu)',
        'invalid_record' => 'niepoprawny rekord z platformy',
        'duplicate_record' => 'powtórzony rekord z platformy',
    ];

    private const NOT_RECORDED = ['in_progress', 'integration_unavailable', 'event_not_found', 'connection_missing'];

    public static function describe(string $status): string
    {
        return self::TEXTS[$status] ?? self::UNEXPECTED;
    }

    public static function skipReason(string $reason): string
    {
        return self::SKIP_REASONS[$reason] ?? $reason;
    }

    /** Statuses that are stored as the last pull result of the connection. */
    public static function isRecorded(string $status): bool
    {
        return !in_array($status, self::NOT_RECORDED, true);
    }

    public static function isSuccess(string $status): bool
    {
        return $status === 'ok' || $status === 'partial';
    }

    /** Text of the activity log row (the column holds 190 characters). */
    public static function activityAction(string $status, string $trigger, ?array $summary): string
    {
        $how = $trigger === 'cron' ? 'automatycznie' : 'ręcznie';

        if (self::isSuccess($status)) {
            $summary ??= [];

            return sprintf(
                'Pobrano uczestników z platformy Zmierzymy Czas (%s): nowi %d, zaktualizowani %d, bez zmian %d, pominięci %d, usunięci na platformie %d',
                $how,
                (int)($summary['created'] ?? 0),
                (int)($summary['updated'] ?? 0),
                (int)($summary['unchanged'] ?? 0),
                (int)($summary['skipped'] ?? 0),
                (int)($summary['removed'] ?? 0)
            );
        }

        $text = sprintf('Nie udało się pobrać uczestników z platformy Zmierzymy Czas (%s): %s', $how, self::describe($status));

        return mb_strlen($text) > 190 ? mb_substr($text, 0, 189) . '…' : $text;
    }
}
