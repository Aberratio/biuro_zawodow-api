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
