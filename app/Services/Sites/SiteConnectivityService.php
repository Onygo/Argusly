<?php

namespace App\Services\Sites;

use App\Models\ClientSite;
use App\Models\SiteToken;
use App\Support\SiteUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class SiteConnectivityService
{
    public function testWordPressConnection(ClientSite $site): array
    {
        $site->refresh();

        $base = rtrim((string) ($site->base_url ?: $site->site_url), '/');
        $candidates = array_values(array_unique(array_merge([
            $base . '/wp-json/publishlayer/v1/ping',
            $base . '/wp-json/publishlayer/v1/health',
            $base . '/wp-json/publishlayer/v1/heartbeat',
            $base . '/?rest_route=/publishlayer/v1/ping',
            $base . '/?rest_route=/publishlayer/v1/health',
            $base . '/?rest_route=/publishlayer/v1/heartbeat',
        ], $this->discoverPublishLayerRoutes($base))));

        try {
            $lastStatus = null;
            $lastBody = null;

            foreach ($candidates as $url) {
                foreach (['get', 'post'] as $method) {
                    $client = Http::timeout(10)->acceptJson();

                    if ($this->shouldAllowInsecureTls($url)) {
                        $client = $client->withoutVerifying();
                    }

                    $response = $method === 'post'
                        ? $client->post($url, ['site_url' => $base])
                        : $client->get($url);

                    $lastStatus = $response->status();
                    $lastBody = $response->json() ?: ['message' => trim((string) $response->body())];

                    if ($response->successful()) {
                        $site->status = 'connected';
                        $site->last_healthcheck_at = now();
                        $site->last_seen_at = now();
                        $site->last_error = null;
                        $site->save();

                        return [
                            'ok' => true,
                            'status_code' => $response->status(),
                            'body' => $lastBody,
                        ];
                    }

                    if ($response->status() !== 404) {
                        break 2;
                    }
                }
            }

            $site->status = 'error';
            $site->last_healthcheck_at = now();
            if ($lastStatus === 404) {
                if ($this->hasRecentInboundActivity($site)) {
                    $site->status = 'connected';
                    $site->last_error = null;
                    $site->last_seen_at = $site->last_seen_at ?: now();
                    $site->save();

                    return [
                        'ok' => true,
                        'status_code' => 200,
                        'body' => [
                            'message' => 'Inbound connector activity detected recently. Outbound WP ping route not available on this plugin version.',
                        ],
                    ];
                }

                $site->last_error = 'WP plugin endpoint not found. Checked known and discovered Argusly routes with GET and POST.';
            } else {
                $site->last_error = 'Ping failed with status ' . (string) $lastStatus;
            }
            $site->save();

            $errorMessage = $site->last_error;
            if (is_array($lastBody)) {
                $msg = trim((string) ($lastBody['error'] ?? $lastBody['message'] ?? ''));
                if ($msg !== '') {
                    $errorMessage = $msg;
                }
            }

            return [
                'ok' => false,
                'status_code' => $lastStatus,
                'body' => array_merge(
                    is_array($lastBody) ? $lastBody : [],
                    ['error' => $errorMessage]
                ),
            ];
        } catch (\Throwable $exception) {
            $site->status = 'error';
            $site->last_healthcheck_at = now();
            $site->last_error = $exception->getMessage();
            $site->save();

            return [
                'ok' => false,
                'status_code' => null,
                'body' => ['error' => $exception->getMessage()],
            ];
        }
    }

    public function testLaravelConnector(ClientSite $site): array
    {
        $site->refresh();

        try {
            $site->last_healthcheck_at = now();

            $activityResult = $this->checkLaravelConnectorActivity($site);
            if ($activityResult !== null) {
                if ($activityResult['ok']) {
                    $payload = $this->buildLaravelActivityPayload(
                        is_array($activityResult['body']) ? $activityResult['body'] : []
                    );

                    $site->status = 'connected';
                    $site->last_error = null;

                    $lastSeen = (string) ($payload['last_seen_at'] ?? '');
                    if ($lastSeen !== '') {
                        $site->last_seen_at = Carbon::parse($lastSeen);
                    } else {
                        $site->last_seen_at = $site->last_seen_at ?: now();
                    }

                    $site->save();

                    return [
                        'ok' => true,
                        'status_code' => $activityResult['status_code'] ?? 200,
                        'body' => $payload,
                    ];
                }

                $site->status = 'error';
                $site->last_error = $this->extractConnectorErrorMessage(
                    is_array($activityResult['body']) ? $activityResult['body'] : null,
                    $activityResult['status_code'] ?? null
                );
                $site->save();

                return [
                    'ok' => false,
                    'status_code' => $activityResult['status_code'] ?? null,
                    'body' => array_merge(
                        is_array($activityResult['body']) ? $activityResult['body'] : [],
                        ['error' => (string) $site->last_error]
                    ),
                ];
            }

            if ($this->hasRecentInboundActivity($site)) {
                $site->status = 'connected';
                $site->last_error = null;
                $site->last_seen_at = $site->last_seen_at ?: now();
                $site->save();

                return [
                    'ok' => true,
                    'status_code' => 200,
                    'body' => [
                        'active' => true,
                        'last_seen_at' => $site->last_seen_at?->toIso8601String(),
                        'source' => 'webhook',
                        'recent_events_count_24h' => 0,
                        'failed_events_count_24h' => 0,
                        'message' => 'Recent Laravel connector API activity detected.',
                    ],
                ];
            }

            $site->status = 'error';
            $site->last_error = 'No recent Laravel connector activity detected for this site token.';
            $site->save();

            return [
                'ok' => false,
                'status_code' => 422,
                'body' => [
                    'error' => (string) $site->last_error,
                ],
            ];
        } catch (\Throwable $exception) {
            $site->status = 'error';
            $site->last_healthcheck_at = now();
            $site->last_error = $exception->getMessage();
            $site->save();

            return [
                'ok' => false,
                'status_code' => null,
                'body' => ['error' => $exception->getMessage()],
            ];
        }
    }

    /**
     * @return array{ok: bool, status_code: int|null, body: array<string,mixed>}|null
     */
    private function checkLaravelConnectorActivity(ClientSite $site): ?array
    {
        $siteKeys = $this->resolveConnectorSiteKeys($site);
        if ($siteKeys === []) {
            return null;
        }

        $base = rtrim((string) ($site->base_url ?: $site->site_url), '/');
        if ($base === '') {
            return null;
        }

        $candidates = [
            $base . '/publishlayer/connector/activity',
            $base . '/publishlayer/activity',
        ];

        $lastError = null;
        $lastFailure = null;

        foreach ($siteKeys as $siteKey) {
            $payload = $this->buildLaravelConnectorActivityPayload($site, $siteKey);

            try {
                $result = $this->dispatchLaravelConnectorActivityRequest($candidates, $payload);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();

                continue;
            }

            if ($result === null) {
                continue;
            }

            if ($result['ok']) {
                return $result;
            }

            $status = (int) ($result['status_code'] ?? 0);
            $body = is_array($result['body'] ?? null) ? $result['body'] : [];

            if ($status === 422 && $this->isInvalidConnectorIdentifierError($body)) {
                $lastFailure = $result;
                continue;
            }

            return $result;
        }

        if ($lastFailure !== null) {
            return $lastFailure;
        }

        if ($lastError !== null && $lastError !== '') {
            return [
                'ok' => false,
                'status_code' => null,
                'body' => ['error' => $lastError],
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveConnectorSiteKeys(ClientSite $site): array
    {
        $tokens = SiteToken::query()
            ->where('client_site_id', $site->id)
            ->where('revoked', false)
            ->whereNotNull('token_encrypted')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['token_encrypted']);

        $siteKeys = [];

        foreach ($tokens as $token) {
            if (! is_string($token->token_encrypted) || trim($token->token_encrypted) === '') {
                continue;
            }

            try {
                $value = trim((string) Crypt::decryptString($token->token_encrypted));
                if ($value !== '' && ! in_array($value, $siteKeys, true)) {
                    $siteKeys[] = $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $siteKeys;
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string,mixed>  $payload
     * @return array{ok: bool, status_code: int|null, body: array<string,mixed>}|null
     */
    private function dispatchLaravelConnectorActivityRequest(array $urls, array $payload): ?array
    {
        foreach ($urls as $url) {
            foreach (['post', 'get'] as $method) {
                $client = Http::timeout(10)->acceptJson();
                if ($this->shouldAllowInsecureTls($url)) {
                    $client = $client->withoutVerifying();
                }

                $response = $method === 'post'
                    ? $client->post($url, $payload)
                    : $client->get($url, $payload);

                $status = $response->status();
                $body = $response->json();
                if (! is_array($body)) {
                    $body = ['message' => trim((string) $response->body())];
                }

                if ($response->successful()) {
                    return [
                        'ok' => true,
                        'status_code' => $status,
                        'body' => $body,
                    ];
                }

                if ($status !== 404) {
                    return [
                        'ok' => false,
                        'status_code' => $status,
                        'body' => $body,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLaravelConnectorActivityPayload(ClientSite $site, string $siteKey): array
    {
        return array_filter([
            'site_key' => $siteKey,
            'site_token' => $siteKey, // backward compatibility
            'site_id' => (string) $site->id,
            'site_uuid' => (string) $site->id,
            'client_site_id' => (string) $site->id,
            'workspace_id' => (string) ($site->workspace_id ?? ''),
            'workspace_uuid' => (string) ($site->workspace_id ?? ''),
        ], static fn ($value): bool => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function isInvalidConnectorIdentifierError(array $body): bool
    {
        $errors = $body['errors'] ?? null;
        if (! is_array($errors)) {
            return false;
        }

        foreach (['site_key', 'site_token', 'site_id', 'site_uuid', 'client_site_id'] as $field) {
            $messages = $errors[$field] ?? null;
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && str_contains(strtolower($message), 'invalid')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function buildLaravelActivityPayload(array $body): array
    {
        $lastWebhook = $this->normalizeIsoTimestamp(data_get($body, 'last_webhook_received_at'));
        $lastProcessed = $this->normalizeIsoTimestamp(data_get($body, 'last_processed_at'));
        $lastHeartbeat = $this->normalizeIsoTimestamp(data_get($body, 'last_heartbeat_at'));

        $timestamps = array_values(array_filter([$lastWebhook, $lastProcessed, $lastHeartbeat]));
        usort($timestamps, static fn (string $a, string $b): int => strcmp($b, $a));
        $lastSeen = $timestamps[0] ?? null;

        $source = 'unknown';
        if ($lastHeartbeat !== null) {
            $source = 'heartbeat';
        } elseif ($lastWebhook !== null || $lastProcessed !== null) {
            $source = 'webhook';
        }

        $recentEventsCount24h = max(0, (int) data_get($body, 'recent_events_count_24h', 0));
        $failedEventsCount24h = max(0, (int) data_get($body, 'failed_events_count_24h', 0));

        return [
            'active' => $lastSeen !== null || $recentEventsCount24h > 0,
            'last_seen_at' => $lastSeen,
            'source' => $source,
            'last_webhook_received_at' => $lastWebhook,
            'last_processed_at' => $lastProcessed,
            'last_heartbeat_at' => $lastHeartbeat,
            'recent_events_count_24h' => $recentEventsCount24h,
            'failed_events_count_24h' => $failedEventsCount24h,
        ];
    }

    private function normalizeIsoTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>|null  $body
     */
    private function extractConnectorErrorMessage(?array $body, ?int $statusCode): string
    {
        $message = trim((string) ($body['error'] ?? $body['message'] ?? ''));
        $errors = $body['errors'] ?? null;
        $firstValidationError = '';

        if (is_array($errors)) {
            foreach ($errors as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                    $firstValidationError = trim($messages[0]);
                    break;
                }
            }
        }

        if ($message !== '' && $firstValidationError !== '' && ! str_contains($message, $firstValidationError)) {
            return $message . ' ' . $firstValidationError;
        }

        if ($message !== '') {
            return $message;
        }

        if ($firstValidationError !== '') {
            return $firstValidationError;
        }

        if ($statusCode === 422) {
            return 'Laravel connector activity endpoint rejected the activity check payload.';
        }

        return 'Laravel connector activity check failed.';
    }

    private function shouldAllowInsecureTls(string $url): bool
    {
        if (! (bool) config('publishlayer.wp_connector.allow_insecure_local_tls', false)) {
            return false;
        }

        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        $host = SiteUrl::hostFromUrl($url);
        if ($host === '') {
            return false;
        }

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    /**
     * Discover WP REST routes that look like Argusly routes.
     *
     * @return list<string>
     */
    private function discoverPublishLayerRoutes(string $base): array
    {
        $indexCandidates = [
            $base . '/wp-json',
            $base . '/wp-json/',
            $base . '/?rest_route=/',
        ];

        foreach ($indexCandidates as $indexUrl) {
            try {
                $client = Http::timeout(10)->acceptJson();
                if ($this->shouldAllowInsecureTls($indexUrl)) {
                    $client = $client->withoutVerifying();
                }

                $response = $client->get($indexUrl);
                if (! $response->successful()) {
                    continue;
                }

                $data = $response->json();
                if (! is_array($data)) {
                    continue;
                }

                $routes = is_array($data['routes'] ?? null) ? $data['routes'] : [];
                $found = [];

                foreach (array_keys($routes) as $routePath) {
                    if (! is_string($routePath)) {
                        continue;
                    }

                    $normalized = strtolower($routePath);
                    if (! str_contains($normalized, 'publishlayer')) {
                        continue;
                    }

                    $trimmed = '/' . ltrim($routePath, '/');
                    $found[] = $base . '/wp-json' . $trimmed;
                    $found[] = $base . '/?rest_route=' . $trimmed;
                }

                return array_values(array_unique($found));
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    private function hasRecentInboundActivity(ClientSite $site): bool
    {
        $windowMinutes = (int) config('publishlayer.wp_connector.recent_activity_window_minutes', 15);
        if ($windowMinutes <= 0) {
            $windowMinutes = 15;
        }

        $threshold = now()->subMinutes($windowMinutes);

        if ($site->last_seen_at && $site->last_seen_at->gte($threshold)) {
            return true;
        }

        return SiteToken::query()
            ->where('client_site_id', $site->id)
            ->whereNotNull('last_used_at')
            ->where('last_used_at', '>=', $threshold)
            ->exists();
    }
}
