<?php

namespace App\Services\LlmTracking;

use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQuerySet;
use Illuminate\Support\Str;

class ArguslyTrackingDefaults
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function ensureForSite(ClientSite $site): array
    {
        if (! $this->shouldSeed($site)) {
            return [];
        }

        $created = [];

        foreach ($this->defaultsForSite($site) as $default) {
            $exists = LlmTrackingQuery::query()
                ->where('client_site_id', $site->id)
                ->where('name', (string) $default['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $querySet = LlmTrackingQuerySet::query()->firstOrCreate(
                [
                    'workspace_id' => $site->workspace_id,
                    'client_site_id' => $site->id,
                    'name' => (string) $default['query_set']['name'],
                ],
                [
                    'description' => (string) ($default['query_set']['description'] ?? ''),
                    'locale' => (string) ($default['locale'] ?? 'en'),
                    'is_active' => true,
                ],
            );

            $created[] = LlmTrackingQuery::query()->create([
                'workspace_id' => $site->workspace_id,
                'client_site_id' => $site->id,
                'llm_tracking_query_set_id' => $querySet->id,
                'name' => $default['name'],
                'query_text' => $default['query_text'],
                'target_brand' => $default['target_brand'],
                'target_domain' => $default['target_domain'],
                'brand_terms' => $default['brand_terms'],
                'competitor_terms' => $default['competitor_terms'],
                'target_urls' => $default['target_urls'],
                'tags' => $default['tags'],
                'locale' => $default['locale'],
                'frequency' => $default['frequency'],
                'priority' => $default['priority'],
                'is_active' => true,
            ])->toArray();
        }

        return $created;
    }

    private function shouldSeed(ClientSite $site): bool
    {
        $haystacks = [
            Str::lower((string) $site->name),
            Str::lower((string) $site->site_url),
            Str::lower((string) $site->base_url),
            Str::lower((string) $site->workspace?->name),
        ];

        foreach ($haystacks as $value) {
            if ($value !== '' && Str::contains($value, 'argusly')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function defaultsForSite(ClientSite $site): array
    {
        $baseUrl = rtrim((string) ($site->base_url ?: $site->site_url), '/');
        $targetUrls = $baseUrl !== '' ? [$baseUrl] : [];

        return [
            [
                'query_set' => [
                    'name' => 'SEO Focus',
                    'description' => 'Broad SEO and content platform discovery queries.',
                ],
                'name' => 'SEO Visibility - Content & Platform',
                'query_text' => 'AI content platform SEO tool content optimization platform semantic SEO topic clusters internal linking SEO automation content strategy tool SEO SaaS',
                'target_brand' => 'Argusly',
                'target_domain' => $baseUrl !== '' ? parse_url($baseUrl, PHP_URL_HOST) : null,
                'brand_terms' => ['Argusly', 'Argusly SEO', 'Argusly platform'],
                'competitor_terms' => ['Surfer SEO', 'Clearscope', 'MarketMuse', 'Frase', 'SE Ranking', 'Content Harmony'],
                'target_urls' => $targetUrls,
                'tags' => ['seo', 'platform', 'discovery'],
                'locale' => 'en',
                'frequency' => 'daily',
                'priority' => 90,
            ],
            [
                'query_set' => [
                    'name' => 'AI / GEO Focus',
                    'description' => 'Generative engine optimization and AI search discovery prompts.',
                ],
                'name' => 'AI Visibility - GEO & LLM Discovery',
                'query_text' => 'best AI content tools for SEO what tools improve AI search visibility how to optimize content for ChatGPT or AI search generative engine optimization tools AI content workflow platform semantic content for LLMs',
                'target_brand' => 'Argusly',
                'target_domain' => $baseUrl !== '' ? parse_url($baseUrl, PHP_URL_HOST) : null,
                'brand_terms' => ['Argusly', 'Argusly AI', 'Argusly GEO'],
                'competitor_terms' => ['Jasper AI', 'Copy.ai', 'Writesonic', 'Scalenut', 'Frase', 'MarketMuse'],
                'target_urls' => $targetUrls,
                'tags' => ['ai_visibility', 'geo', 'llm'],
                'locale' => 'en',
                'frequency' => 'daily',
                'priority' => 100,
            ],
            [
                'query_set' => [
                    'name' => 'Brand Monitoring',
                    'description' => 'Brand presence, branded search and comparison prompts.',
                ],
                'name' => 'Brand Presence - Argusly',
                'query_text' => 'Argusly what is Argusly Argusly review Argusly SEO tool Argusly AI content platform',
                'target_brand' => 'Argusly',
                'target_domain' => $baseUrl !== '' ? parse_url($baseUrl, PHP_URL_HOST) : null,
                'brand_terms' => ['Argusly', 'Publish Layer', 'Argusly AI', 'Argusly platform'],
                'competitor_terms' => ['Surfer SEO', 'Jasper AI', 'Frase', 'MarketMuse'],
                'target_urls' => $targetUrls,
                'tags' => ['brand', 'monitoring'],
                'locale' => 'en',
                'frequency' => 'daily',
                'priority' => 95,
            ],
        ];
    }
}
