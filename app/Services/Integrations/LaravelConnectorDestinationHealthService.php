<?php

namespace App\Services\Integrations;

use App\Models\ContentDestination;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LaravelConnectorDestinationHealthService
{
    /**
     * @return array{ok:bool,status_code:int|null,message:string,body:array<string,mixed>}
     */
    public function test(ContentDestination $destination): array
    {
        $healthUrl = $destination->laravelConnectorHealthUrl();
        $apiKey = $destination->laravelConnectorApiKey();
        $siteId = $destination->laravelConnectorSiteId();

        if (! $destination->isLaravelConnector()) {
            return [
                'ok' => false,
                'status_code' => null,
                'message' => 'Destination is not a Laravel connector.',
                'body' => ['error' => 'Destination is not a Laravel connector.'],
            ];
        }

        if (! $destination->laravelConnectorEnabled()) {
            return [
                'ok' => false,
                'status_code' => null,
                'message' => 'Laravel connector destination is disabled.',
                'body' => ['error' => 'Laravel connector destination is disabled.'],
            ];
        }

        if (! $healthUrl || ! $apiKey || ! $siteId) {
            return [
                'ok' => false,
                'status_code' => null,
                'message' => 'Laravel connector destination configuration is incomplete.',
                'body' => ['error' => 'Laravel connector destination configuration is incomplete.'],
            ];
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->acceptJson()
                ->withHeaders([
                    'X-PublishLayer-Key' => $apiKey,
                    'X-PublishLayer-Site' => $siteId,
                    'User-Agent' => 'Argusly/LaravelConnectorHealthCheck',
                ])
                ->get($healthUrl);

            $body = $response->json();
            if (! is_array($body)) {
                $body = [
                    'message' => trim((string) $response->body()),
                ];
            }

            $ok = $response->successful() && data_get($body, 'ok', true) !== false;
            $message = trim((string) data_get($body, 'message', ''));

            if ($message === '') {
                $message = $ok
                    ? 'Laravel connector health check succeeded.'
                    : 'Laravel connector health check failed.';
            }

            return [
                'ok' => $ok,
                'status_code' => $response->status(),
                'message' => Str::limit($message, 500, ''),
                'body' => $body,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status_code' => null,
                'message' => $exception->getMessage(),
                'body' => ['error' => $exception->getMessage()],
            ];
        }
    }
}
