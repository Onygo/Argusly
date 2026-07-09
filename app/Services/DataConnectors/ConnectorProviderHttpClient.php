<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorHealthEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ConnectorProviderHttpClient
{
    public function __construct(
        private readonly ConnectorAccessTokenService $tokens,
        private readonly ConnectorHealthService $health,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function get(ConnectorAccount $account, string $url, array $query = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->request($account, 'get', $url, $query, $headers, $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function post(ConnectorAccount $account, string $url, array $payload = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->request($account, 'post', $url, $payload, $headers, $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function request(
        ConnectorAccount $account,
        string $method,
        string $url,
        array $payload,
        array $headers,
        int $timeout,
    ): Response {
        $response = $this->pending($account, $headers, $timeout)->{$method}($url, $payload);

        if ($response->status() === 401) {
            $response = $this->pending($account, $headers, $timeout, true)->{$method}($url, $payload);
        }

        $this->recordApiCall($account, $response);

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function pending(ConnectorAccount $account, array $headers, int $timeout, bool $forceRefresh = false): PendingRequest
    {
        return Http::withToken($this->tokens->accessToken($account, $forceRefresh))
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout(max(1, $timeout));
    }

    private function recordApiCall(ConnectorAccount $account, Response $response): void
    {
        $rateLimit = array_filter([
            'limit' => $response->header('X-RateLimit-Limit') ?: $response->header('X-RestLi-RateLimit-Limit'),
            'remaining' => $response->header('X-RateLimit-Remaining') ?: $response->header('X-RestLi-RateLimit-Remaining'),
            'reset' => $response->header('X-RateLimit-Reset') ?: $response->header('X-RestLi-RateLimit-Reset'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $account->forceFill([
            'last_api_call_at' => now(),
            'last_error' => $response->successful() ? null : 'Provider API returned HTTP '.$response->status().'.',
            'rate_limit_json' => $rateLimit === [] ? $account->rate_limit_json : $rateLimit,
        ])->save();

        if ($response->status() === 429) {
            $this->health->record(
                account: $account,
                severity: ConnectorHealthEvent::SEVERITY_WARNING,
                eventType: ConnectorHealthEvent::EVENT_RATE_LIMITED,
                message: 'Provider API rate limit reached.',
                context: [
                    'status' => $response->status(),
                    'rate_limit' => $rateLimit,
                ],
            );
        }
    }
}
