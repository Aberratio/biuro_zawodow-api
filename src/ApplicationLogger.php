<?php

declare(strict_types=1);

final class ApplicationLogger
{
    private const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
    ];

    private const SENSITIVE_KEY_PATTERN = '/password|passphrase|secret|token|authorization|cookie|qr_code|identity_code|dsn|smtp|db_|body|payload|csv/i';

    public static function log(string $level, string $eventCode, string $message, array $context = [], string $source = 'application'): ?string
    {
        $normalizedLevel = strtolower(trim($level));
        if (!isset(self::LEVELS[$normalizedLevel]) || !self::shouldLog($normalizedLevel)) {
            return null;
        }

        $id = 'srv-' . bin2hex(random_bytes(10));
        $user = RequestContext::user();
        $entry = [
            'id' => $id,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $normalizedLevel,
            'event_code' => trim($eventCode) !== '' ? trim($eventCode) : 'application.event',
            'message' => self::sanitizeString($message),
            'environment' => appEnvironment(),
            'release' => trim((string)(getenv('APP_RELEASE') ?: 'unknown')),
            'request_id' => RequestContext::id(),
            'method' => RequestContext::method(),
            'path' => RequestContext::path(),
            'user_id' => is_array($user) ? (string)($user['id'] ?? '') : null,
            'user_role' => is_array($user) ? (string)($user['role'] ?? '') : null,
            'context' => self::redact($context),
        ];

        if (!self::writeEntry($source, $entry)) {
            error_log(sprintf('[logging.write_failed] %s %s', $eventCode, $message));
            return null;
        }

        if (in_array($normalizedLevel, ['error', 'critical'], true)) {
            SentryBridge::captureMessage($entry['message'], $normalizedLevel, $entry);
        }

        return $id;
    }

    public static function logException(Throwable $exception, string $eventCode = 'application.exception', array $context = []): ?string
    {
        $context['exception'] = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        $id = self::log('error', $eventCode, $exception->getMessage(), $context);
        SentryBridge::captureException($exception, ['server_log_id' => $id]);
        return $id;
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = self::redact($childValue, is_string($childKey) ? $childKey : null);
            }
            return $redacted;
        }

        if (is_object($value)) {
            return '[OBJECT ' . $value::class . ']';
        }

        if (is_string($value)) {
            return self::sanitizeString($value);
        }

        return $value;
    }

    public static function readEntries(
        string $source,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $filters = [],
        int $limit = 1000
    ): array {
        if (!in_array($source, ['application', 'php'], true)) {
            return [];
        }

        $entries = [];
        $cursor = $to->setTime(0, 0);
        $firstDay = $from->setTime(0, 0);
        while ($cursor >= $firstDay) {
            foreach (self::candidateFiles($source, $cursor->format('Y-m-d')) as $file) {
                foreach (self::readFileLines($file) as $line) {
                    $entry = json_decode($line, true);
                    if (!is_array($entry) || !self::entryMatches($entry, $from, $to, $filters)) {
                        continue;
                    }
                    $entries[] = $entry;
                    if (count($entries) >= $limit) {
                        break 3;
                    }
                }
            }
            $cursor = $cursor->modify('-1 day');
        }

        usort($entries, static fn(array $left, array $right): int => strcmp((string)($right['timestamp'] ?? ''), (string)($left['timestamp'] ?? '')));
        return $entries;
    }

    public static function findEntry(string $source, string $date, string $id): ?array
    {
        if (!in_array($source, ['application', 'php'], true) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        foreach (self::candidateFiles($source, $date) as $file) {
            foreach (self::readFileLines($file) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry) && hash_equals((string)($entry['id'] ?? ''), $id)) {
                    return $entry;
                }
            }
        }
        return null;
    }

    public static function status(): array
    {
        $directory = self::logDirectory();
        $lastEntry = null;
        foreach (['application', 'php'] as $source) {
            $entries = self::readEntries(
                $source,
                new DateTimeImmutable('-1 day', new DateTimeZone('UTC')),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                [],
                1
            );
            if ($entries !== [] && ($lastEntry === null || strcmp((string)$entries[0]['timestamp'], (string)$lastEntry['timestamp']) > 0)) {
                $lastEntry = $entries[0];
            }
        }

        return [
            'writable' => is_dir($directory) && is_writable($directory),
            'retention_days' => self::retentionDays(),
            'level' => self::configuredLevel(),
            'last_entry_at' => $lastEntry['timestamp'] ?? null,
            'sentry_configured' => SentryBridge::isConfigured(),
        ];
    }

    public static function runMaintenance(): array
    {
        self::ensureLogDirectory();
        $compressed = 0;
        $deleted = 0;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach (glob(self::logDirectory() . DIRECTORY_SEPARATOR . '*.jsonl*') ?: [] as $file) {
            if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.jsonl(\.gz)?$/', basename($file), $matches)) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1], new DateTimeZone('UTC'));
            if ($date === false) {
                continue;
            }
            $ageDays = (int)$date->diff($now)->format('%a');
            if ($ageDays >= self::retentionDays()) {
                if (@unlink($file)) {
                    $deleted++;
                }
                continue;
            }
            if ($ageDays >= 2 && !str_ends_with($file, '.gz') && function_exists('gzopen')) {
                $content = @file_get_contents($file);
                if (is_string($content)) {
                    $gz = @gzopen($file . '.gz', 'wb9');
                    if ($gz !== false) {
                        gzwrite($gz, $content);
                        gzclose($gz);
                        @chmod($file . '.gz', 0600);
                        if (@unlink($file)) {
                            $compressed++;
                        }
                    }
                }
            }
        }

        return ['compressed' => $compressed, 'deleted' => $deleted];
    }

    private static function writeEntry(string $source, array $entry): bool
    {
        if (!in_array($source, ['application', 'php'], true) || !self::ensureLogDirectory()) {
            return false;
        }
        $prefix = $source === 'php' ? 'php-error' : 'application';
        $file = self::logDirectory() . DIRECTORY_SEPARATOR . $prefix . '-' . gmdate('Y-m-d') . '.jsonl';
        $encoded = encodeJsonValue($entry);
        if ($encoded === false) {
            return false;
        }
        $written = @file_put_contents($file, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            return false;
        }
        @chmod($file, 0600);
        return true;
    }

    private static function shouldLog(string $level): bool
    {
        return self::LEVELS[$level] >= self::LEVELS[self::configuredLevel()];
    }

    private static function configuredLevel(): string
    {
        $level = strtolower(trim((string)(getenv('LOG_LEVEL') ?: 'info')));
        return isset(self::LEVELS[$level]) ? $level : 'info';
    }

    private static function retentionDays(): int
    {
        return max(1, min(365, (int)(getenv('LOG_RETENTION_DAYS') ?: 30)));
    }

    private static function logDirectory(): string
    {
        $configured = trim((string)(getenv('LOG_DIR') ?: ''));
        return $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    }

    private static function ensureLogDirectory(): bool
    {
        $directory = self::logDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        @chmod($directory, 0700);
        return is_writable($directory);
    }

    private static function sanitizeString(string $value): string
    {
        $value = normalizeUtf8String($value);
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/([?&](?:token|code|password|secret)=)[^&\s]+/i', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace('/\b(token|password|secret)=\S+/i', '$1=[REDACTED]', $value) ?? $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, 12000) : substr($value, 0, 12000);
    }

    private static function candidateFiles(string $source, string $date): array
    {
        $prefix = $source === 'php' ? 'php-error' : 'application';
        $base = self::logDirectory() . DIRECTORY_SEPARATOR . $prefix . '-' . $date . '.jsonl';
        return array_values(array_filter([$base, $base . '.gz'], 'is_file'));
    }

    private static function readFileLines(string $file): iterable
    {
        if (str_ends_with($file, '.gz') && function_exists('gzopen')) {
            $handle = @gzopen($file, 'rb');
            if ($handle === false) {
                return;
            }
            while (!gzeof($handle)) {
                $line = gzgets($handle);
                if (is_string($line) && trim($line) !== '') {
                    yield $line;
                }
            }
            gzclose($handle);
            return;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                yield $line;
            }
        }
        fclose($handle);
    }

    private static function entryMatches(array $entry, DateTimeImmutable $from, DateTimeImmutable $to, array $filters): bool
    {
        try {
            $timestamp = new DateTimeImmutable((string)($entry['timestamp'] ?? ''));
        } catch (Throwable) {
            return false;
        }
        if ($timestamp < $from || $timestamp > $to) {
            return false;
        }
        foreach (['level', 'request_id', 'event_code'] as $key) {
            $filter = trim((string)($filters[$key] ?? ''));
            if ($filter !== '' && strcasecmp((string)($entry[$key] ?? ''), $filter) !== 0) {
                return false;
            }
        }
        $queryValue = trim((string)($filters['q'] ?? ''));
        $query = function_exists('mb_strtolower') ? mb_strtolower($queryValue) : strtolower($queryValue);
        if ($query !== '') {
            $encodedEntry = (string)(encodeJsonValue($entry) ?: '');
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($encodedEntry) : strtolower($encodedEntry);
            if (!str_contains($haystack, $query)) {
                return false;
            }
        }
        return true;
    }
}
