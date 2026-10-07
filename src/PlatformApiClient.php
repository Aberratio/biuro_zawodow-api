<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformIntegrationConfig.php';

/**
 * HTTP client for the platform (ZC) API. Results never contain the token, response bodies,
 * headers or exception text.
 */
final class PlatformApiClient
{
    /** @var callable(string, string, array<string, string>, int): array{status: int, body: string} */
    private $transport;

    /** @param null|callable(string $method, string $url, array<string,string> $headers, int $timeout): array{status:int, body:string} $transport */
    public function __construct(private readonly PlatformIntegrationConfig $config, ?callable $transport = null)
    {
        $this->transport = $transport ?? [self::class, 'defaultTransport'];
    }

    public function participantsUrl(string $platformEventId): string
    {
        return $this->config->apiBaseUrl() . '/events/' . rawurlencode($platformEventId) . '/bz/participants';
    }

    /** @return array{status: string, participant_count: ?int, http_status: ?int} */
    public function probeParticipants(string $platformEventId, string $organizationToken): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-API-Key' => $this->config->appKey(),
            'X-BZ-Organization-Token' => $organizationToken,
        ];

        try {
            $response = ($this->transport)('GET', $this->participantsUrl($platformEventId), $headers, $this->config->timeoutSeconds());
        } catch (Throwable) {
            return ['status' => 'network_error', 'participant_count' => null, 'http_status' => null];
        }

        $http = (int)($response['status'] ?? 0);
        $count = null;
        $status = match (true) {
            $http === 200 => 'invalid_response',
            $http === 401 => 'app_key_rejected',
            $http === 403 => 'token_rejected',
            $http === 404 => 'platform_event_not_found',
            $http === 429 => 'platform_rate_limited',
            default => 'platform_error',
        };

        if ($http === 200) {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) && array_is_list($decoded['data'])) {
                $status = 'ok';
                $count = count($decoded['data']);
            }
        }

        return ['status' => $status, 'participant_count' => $count, 'http_status' => $http > 0 ? $http : null];
    }

    /**
     * Downloads the whole participant list. Network errors, server errors and rate limiting are retried
     * (1 s, then 3 s pause); authorisation and not-found answers never are. The result never contains
     * headers, the token or exception text.
     *
     * @param null|callable(int): void $sleep
     * @return array{status: string, http_status: ?int, body: ?string, attempts: int}
     */
    public function fetchParticipants(string $platformEventId, string $organizationToken, int $maxAttempts = 1, ?callable $sleep = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-API-Key' => $this->config->appKey(),
            'X-BZ-Organization-Token' => $organizationToken,
        ];
        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };
        $maxAttempts = max(1, $maxAttempts);
        $pauses = [1, 3];
        $result = ['status' => 'network_error', 'http_status' => null, 'body' => null, 'attempts' => 0];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->fetchOnce($platformEventId, $headers);
            $result['attempts'] = $attempt;

            if (!in_array($result['status'], ['network_error', 'platform_error', 'platform_rate_limited'], true) || $attempt === $maxAttempts) {
                break;
            }

            $sleep($pauses[min($attempt - 1, count($pauses) - 1)]);
        }

        return $result;
    }

    /** @return array{status: string, http_status: ?int, body: ?string, attempts: int} */
    private function fetchOnce(string $platformEventId, array $headers): array
    {
        try {
            $response = ($this->transport)('GET', $this->participantsUrl($platformEventId), $headers, $this->config->pullTimeoutSeconds());
        } catch (Throwable) {
            return ['status' => 'network_error', 'http_status' => null, 'body' => null, 'attempts' => 0];
        }

        $http = (int)($response['status'] ?? 0);
        $status = match (true) {
            $http === 200 => 'ok',
            $http === 401 => 'app_key_rejected',
            $http === 403 => 'token_rejected',
            $http === 404 => 'platform_event_not_found',
            $http === 429 => 'platform_rate_limited',
            default => 'platform_error',
        };

        return [
            'status' => $status,
            'http_status' => $http > 0 ? $http : null,
            'body' => $http === 200 ? (string)($response['body'] ?? '') : null,
            'attempts' => 0,
        ];
    }

    /** @return array{status: int, body: string} */
    public static function defaultTransport(string $method, string $url, array $headers, int $timeout): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException('platform_transport_error');
        });
        try {
            $body = file_get_contents($url, false, $context);
            $responseHeaders = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }

        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m) === 1) {
                $status = (int)$m[1];
            }
        }

        if ($body === false || $status === 0) {
            throw new RuntimeException('platform_transport_error');
        }

        return ['status' => $status, 'body' => $body];
    }
}
