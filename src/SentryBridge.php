<?php

declare(strict_types=1);

final class SentryBridge
{
    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized || !self::isConfigured() || !function_exists('\Sentry\init')) {
            return;
        }
        self::$initialized = true;

        try {
            \Sentry\init([
                'dsn' => trim((string)getenv('SENTRY_DSN')),
                'environment' => trim((string)(getenv('SENTRY_ENVIRONMENT') ?: appEnvironment())),
                'release' => trim((string)(getenv('APP_RELEASE') ?: 'unknown')),
                'send_default_pii' => false,
                'traces_sample_rate' => max(0.0, min(1.0, (float)(getenv('SENTRY_TRACES_SAMPLE_RATE') ?: 0))),
                'before_send' => static function ($event) {
                    $event->setUser(null);
                    $event->setRequest([]);
                    $event->setExtra(ApplicationLogger::redact($event->getExtra()));
                    return $event;
                },
            ]);
            self::configureScope();
        } catch (Throwable $exception) {
            error_log('[sentry.init_failed] ' . $exception->getMessage());
        }
    }

    public static function isConfigured(): bool
    {
        return trim((string)(getenv('SENTRY_DSN') ?: '')) !== '';
    }

    public static function captureException(Throwable $exception, array $context = []): void
    {
        self::initialize();
        if (!self::$initialized || !function_exists('\Sentry\captureException')) {
            return;
        }
        try {
            self::configureScope($context);
            \Sentry\captureException($exception);
        } catch (Throwable) {
        }
    }

    public static function captureMessage(string $message, string $level = 'error', array $context = []): void
    {
        self::initialize();
        if (!self::$initialized || !function_exists('\Sentry\captureMessage')) {
            return;
        }
        try {
            self::configureScope($context);
            \Sentry\captureMessage($message, self::severity($level));
        } catch (Throwable) {
        }
    }

    private static function configureScope(array $context = []): void
    {
        if (!function_exists('\Sentry\configureScope')) {
            return;
        }
        \Sentry\configureScope(static function ($scope) use ($context): void {
            $scope->setTag('request_id', RequestContext::id());
            $scope->setTag('route', RequestContext::path());
            $user = RequestContext::user();
            if (is_array($user)) {
                $scope->setUser(['id' => (string)($user['id'] ?? '')]);
                $scope->setTag('user_role', (string)($user['role'] ?? ''));
            }
            if ($context !== []) {
                $scope->setContext('application', ApplicationLogger::redact($context));
            }
        });
    }

    private static function severity(string $level)
    {
        $map = [
            'debug' => \Sentry\Severity::debug(),
            'info' => \Sentry\Severity::info(),
            'warning' => \Sentry\Severity::warning(),
            'critical' => \Sentry\Severity::fatal(),
        ];
        return $map[$level] ?? \Sentry\Severity::error();
    }
}
