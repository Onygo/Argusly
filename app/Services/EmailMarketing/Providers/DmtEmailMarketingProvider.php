<?php

namespace App\Services\EmailMarketing\Providers;

use App\Models\EmailMarketingConnection;
use App\Services\EmailMarketing\EmailMarketingProviderClient;
use App\Services\EmailMarketing\EmailMarketingProviderException;
use App\Services\EmailMarketing\EmailMarketingProviderResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DmtEmailMarketingProvider implements EmailMarketingProviderClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(EmailMarketingConnection $connection, array $payload, string $idempotencyKey): EmailMarketingProviderResult
    {
        $url = $this->draftUrl($connection);
        $apiKey = trim((string) $connection->secretValue('api_key', ''));

        if ($url === '') {
            throw new EmailMarketingProviderException('DMT base URL is missing.');
        }

        if ($apiKey === '') {
            throw new EmailMarketingProviderException('DMT API key is missing.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) $connection->configValue('timeout_seconds', 20))
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'X-Argusly-Idempotency-Key' => $idempotencyKey,
                'X-Argusly-Provider' => 'dmt',
                'User-Agent' => 'Argusly/EmailMarketing',
            ])
            ->post($url, $payload);

        $body = $response->json();
        $body = is_array($body) ? $body : ['raw' => $response->body()];

        if ($response->failed()) {
            throw new EmailMarketingProviderException(
                Str::limit((string) data_get($body, 'message', 'DMT draft export failed.'), 1000, ''),
                $response->serverError() || $response->status() === 429,
            );
        }

        return new EmailMarketingProviderResult(
            remoteCampaignId: $this->firstString($body, ['campaign_id', 'id', 'data.id', 'data.campaign_id']),
            remoteTemplateId: $this->firstString($body, ['template_id', 'data.template_id']),
            remoteUrl: $this->firstString($body, ['url', 'edit_url', 'data.url', 'data.edit_url']),
            response: $body,
        );
    }

    private function draftUrl(EmailMarketingConnection $connection): string
    {
        $baseUrl = rtrim(trim((string) $connection->configValue('base_url', '')), '/');
        if ($baseUrl === '') {
            return '';
        }

        $endpoint = trim((string) $connection->configValue('draft_endpoint', '/api/argusly/campaign-snippets'));

        return $baseUrl.'/'.ltrim($endpoint !== '' ? $endpoint : '/api/argusly/campaign-snippets', '/');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $keys
     */
    private function firstString(array $body, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) data_get($body, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
