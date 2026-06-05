<?php

namespace App\Services\LlmTracking;

use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Services\Llm\LlmRoutingService;
use Illuminate\Support\Str;

class LlmVisibilityTrackingService
{
    public function __construct(
        private readonly LlmManager $llmManager,
        private readonly LlmRoutingService $routingService,
        private readonly LlmTrackingAnalyzer $analyzer,
        private readonly LlmVisibilityScoreCalculator $scoreCalculator,
        private readonly LlmAuthorityEntityExtractor $entityExtractor,
    ) {
    }

    /**
     * @param array<string,mixed> $routeOverride
     * @return array<string,mixed>
     */
    public function run(LlmTrackingQuery $query, array $routeOverride = []): array
    {
        $route = $routeOverride !== [] ? $routeOverride : $this->resolveRoute($query);
        $variant = $this->resolvePromptVariant($query, (string) ($route['variant_key'] ?? ''));
        $payload = $this->buildPayload($query, $route, $variant);
        $llmResponse = $this->llmManager->generateText(new LlmRequest(
            messages: [
                new LlmMessage('system', (string) ($payload['system'] ?? '')),
                new LlmMessage('user', (string) ($payload['user'] ?? '')),
            ],
            model: (string) ($payload['model'] ?? ''),
            metadata: [
                'feature' => 'llm_tracking',
                'modality' => 'text',
                'workspaceId' => (string) ($query->workspace_id ?? ''),
                'siteId' => (string) ($query->client_site_id ?? ''),
                'queryId' => (string) ($query->id ?? ''),
                'trigger' => 'llm_visibility_tracking',
                'locale' => (string) ($query->locale ?: 'en'),
            ],
        ));

        $responseText = trim((string) $llmResponse->text);

        $rawResponse = is_array($llmResponse->raw) ? $llmResponse->raw : [];
        $analysis = $this->analyzer->analyzeAnswer($responseText, $query, $rawResponse);
        $authorityEntities = $this->entityExtractor->extract($responseText, $query, $analysis->sources);
        $discoveredCompetitorHits = $this->hitsFromAuthorityEntities($authorityEntities);
        $competitorHits = array_values(array_merge($analysis->competitorHits, $discoveredCompetitorHits));
        $scorecard = $this->scoreCalculator->calculate(
            $query,
            $responseText,
            $analysis->brandHits,
            $competitorHits,
            $analysis->citationRanking,
            $analysis->sources,
            $analysis->detectedDomains,
            $analysis->firstMentionIndex,
            $analysis->firstMentionBlock,
            $analysis->firstMentionContext,
            (string) $llmResponse->providerName,
            $this->providerEvidence($query),
        );
        $brandTerms = collect($analysis->brandHits)->pluck('term')->filter()->values()->all();
        $competitorTerms = collect($competitorHits)->pluck('term')->filter()->values()->all();
        $matchedUrls = collect($analysis->urlHits)->pluck('target_url')->filter()->values()->all();

        return [
            'provider' => $llmResponse->providerName,
            'model' => $llmResponse->modelUsed,
            'prompt_variant_key' => (string) ($variant['key'] ?? 'exact'),
            'prompt_variant_text' => (string) ($variant['query_text'] ?? $query->query_text),
            'prompt_variant_intent' => (string) ($variant['intent'] ?? 'exact'),
            'provider_model_key' => Str::lower((string) $llmResponse->providerName . ':' . (string) $llmResponse->modelUsed),
            'request_id' => $llmResponse->requestId,
            'usage' => $llmResponse->usage->toArray(),
            'raw_response' => $rawResponse,
            'parsed_payload' => [
                'response_text' => $responseText,
                'matched_brand_terms' => $brandTerms,
                'matched_competitor_terms' => $competitorTerms,
                'matched_target_urls' => $matchedUrls,
            ],
            'answer_text' => $responseText,
            'normalized_response' => $responseText,
            'answer_json' => null,
            'brand_hits' => $analysis->brandHits,
            'competitor_hits' => $competitorHits,
            'detected_brands' => (array) ($scorecard['detected_brands'] ?? []),
            'detected_competitors' => (array) ($scorecard['detected_competitors'] ?? []),
            'authority_entities' => $authorityEntities,
            'entity_presence' => (array) ($scorecard['entity_presence'] ?? []),
            'url_hits' => $analysis->urlHits,
            'citation_ranking' => $analysis->citationRanking,
            'sources' => $analysis->sources,
            'detected_domains' => $analysis->detectedDomains,
            'first_mention_index' => $analysis->firstMentionIndex,
            'first_mention_block' => $analysis->firstMentionBlock,
            'first_mention_context' => $analysis->firstMentionContext,
            'share_of_voice_snapshot' => $analysis->shareOfVoiceSnapshot,
            'suggestions' => $analysis->suggestions,
            'brand_mentioned' => $analysis->brandMentioned(),
            'competitors_mentioned' => $analysis->competitorsMentioned(),
            'urls_cited' => $analysis->urlsCited(),
            'presence_score' => $scorecard['presence_score'] ?? null,
            'position_score' => $scorecard['position_score'] ?? null,
            'citation_score' => $scorecard['citation_score'] ?? null,
            'context_score' => $scorecard['context_score'] ?? null,
            'context_label' => $scorecard['context_label'] ?? null,
            'sentiment_score' => $scorecard['sentiment_score'] ?? null,
            'sentiment_label' => $scorecard['sentiment_label'] ?? null,
            'competitive_score' => $scorecard['competitive_score'] ?? null,
            'competitor_share_score' => $scorecard['competitor_share_score'] ?? null,
            'owned_visibility_score' => $scorecard['owned_visibility_score'] ?? null,
            'earned_visibility_score' => $scorecard['earned_visibility_score'] ?? null,
            'competitor_pressure_score' => $scorecard['competitor_pressure_score'] ?? null,
            'citation_diversity_score' => $scorecard['citation_diversity_score'] ?? null,
            'model_confidence_score' => $scorecard['model_confidence_score'] ?? null,
            'real_world_gap_score' => $scorecard['real_world_gap_score'] ?? null,
            'ai_visibility_score' => $scorecard['ai_visibility_score'] ?? null,
            'visibility_breakdown' => (array) ($scorecard['visibility_breakdown'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function analyzeStoredRun(LlmTrackingQueryRun $run): array
    {
        $run->loadMissing('trackingQuery');

        if (! $run->trackingQuery) {
            return [];
        }

        $rawResponse = json_decode((string) ($run->raw_response ?? ''), true);

        $analysis = $this->analyzer->analyzeAnswer(
            (string) ($run->answer_text ?? ''),
            $run->trackingQuery,
            is_array($rawResponse) ? $rawResponse : [],
        );

        $authorityEntities = $this->entityExtractor->extract((string) ($run->answer_text ?? ''), $run->trackingQuery, $analysis->sources);
        $competitorHits = array_values(array_merge($analysis->competitorHits, $this->hitsFromAuthorityEntities($authorityEntities)));

        $scorecard = $this->scoreCalculator->calculate(
            $run->trackingQuery,
            (string) ($run->answer_text ?? ''),
            $analysis->brandHits,
            $competitorHits,
            $analysis->citationRanking,
            $analysis->sources,
            $analysis->detectedDomains,
            $analysis->firstMentionIndex,
            $analysis->firstMentionBlock,
            $analysis->firstMentionContext,
            (string) ($run->provider ?? ''),
            $this->providerEvidence($run->trackingQuery, $run->id),
        );

        return [
            'normalized_response' => (string) ($run->answer_text ?? ''),
            'brand_hits' => $analysis->brandHits,
            'competitor_hits' => $competitorHits,
            'detected_brands' => (array) ($scorecard['detected_brands'] ?? []),
            'detected_competitors' => (array) ($scorecard['detected_competitors'] ?? []),
            'authority_entities' => $authorityEntities,
            'entity_presence' => (array) ($scorecard['entity_presence'] ?? []),
            'url_hits' => $analysis->urlHits,
            'citation_ranking' => $analysis->citationRanking,
            'sources' => $analysis->sources,
            'detected_domains' => $analysis->detectedDomains,
            'first_mention_index' => $analysis->firstMentionIndex,
            'first_mention_block' => $analysis->firstMentionBlock,
            'first_mention_context' => $analysis->firstMentionContext,
            'share_of_voice_snapshot' => $analysis->shareOfVoiceSnapshot,
            'suggestions' => $analysis->suggestions,
            'brand_mentioned' => $analysis->brandMentioned(),
            'competitors_mentioned' => $analysis->competitorsMentioned(),
            'urls_cited' => $analysis->urlsCited(),
            'presence_score' => $scorecard['presence_score'] ?? null,
            'position_score' => $scorecard['position_score'] ?? null,
            'citation_score' => $scorecard['citation_score'] ?? null,
            'context_score' => $scorecard['context_score'] ?? null,
            'context_label' => $scorecard['context_label'] ?? null,
            'sentiment_score' => $scorecard['sentiment_score'] ?? null,
            'sentiment_label' => $scorecard['sentiment_label'] ?? null,
            'competitive_score' => $scorecard['competitive_score'] ?? null,
            'competitor_share_score' => $scorecard['competitor_share_score'] ?? null,
            'owned_visibility_score' => $scorecard['owned_visibility_score'] ?? null,
            'earned_visibility_score' => $scorecard['earned_visibility_score'] ?? null,
            'competitor_pressure_score' => $scorecard['competitor_pressure_score'] ?? null,
            'citation_diversity_score' => $scorecard['citation_diversity_score'] ?? null,
            'model_confidence_score' => $scorecard['model_confidence_score'] ?? null,
            'real_world_gap_score' => $scorecard['real_world_gap_score'] ?? null,
            'ai_visibility_score' => $scorecard['ai_visibility_score'] ?? null,
            'visibility_breakdown' => (array) ($scorecard['visibility_breakdown'] ?? []),
        ];
    }

    /**
     * @return array{provider:string,model:string}
     */
    public function resolveRoute(LlmTrackingQuery $query): array
    {
        $route = $this->routingService->resolve(
            feature: 'llm_tracking',
            modality: 'text',
            workspaceId: (string) $query->workspace_id,
            siteId: (string) $query->client_site_id,
            requestedProvider: null,
            requestedModel: null,
        );

        return [
            'provider' => (string) ($route['provider'] ?? config('llm.default_provider', 'openai')),
            'model' => (string) ($route['model'] ?? ''),
        ];
    }

    public function parseDeterministic(string $responseText, array $brandTerms, array $competitorTerms, array $targetUrls): array
    {
        $haystack = Str::lower($responseText);

        $brandMatches = $this->matchedTerms($haystack, $brandTerms);
        $competitorMatches = $this->matchedTerms($haystack, $competitorTerms);
        $urlMatches = $this->matchedTerms($haystack, $targetUrls, exactMatch: false);

        return [
            'brand_mentioned' => $brandMatches !== [],
            'matched_brand_terms' => $brandMatches,
            'competitors_mentioned' => $competitorMatches !== [],
            'matched_competitor_terms' => $competitorMatches,
            'urls_cited' => $urlMatches !== [],
            'matched_target_urls' => $urlMatches,
        ];
    }

    /**
     * @param array{provider:string,model:string} $route
     * @return array<string,mixed>
     */
    private function buildPayload(LlmTrackingQuery $query, array $route, array $variant): array
    {
        $brandTerms = $this->joinTerms((array) ($query->brand_terms ?? []));
        $competitorTerms = $this->joinTerms((array) ($query->competitor_terms ?? []));
        $targetUrls = $this->joinTerms((array) ($query->target_urls ?? []));

        $system = implode("\n", [
            'You are simulating a normal answer a buyer might get from an AI assistant.',
            'Do not favor the tracked brand, owned URLs, or configured competitors.',
            'Mention brands, products, publishers, and sources only when they are genuinely relevant.',
            'When possible, include concrete source URLs that support the answer.',
        ]);

        $user = implode("\n", [
            'User query: ' . trim((string) ($variant['query_text'] ?? $query->query_text)),
            'Intent variant: ' . trim((string) ($variant['intent'] ?? 'exact')),
            'Locale: ' . (string) ($query->locale ?: 'en'),
            'Tracked brand terms for measurement only: ' . $brandTerms,
            'Known tracked competitor/benchmark terms for measurement only: ' . $competitorTerms,
            'Owned target URLs for measurement only: ' . $targetUrls,
            'Return a compact plain-text answer with naturally cited URLs if you would normally cite sources.',
        ]);

        return [
            'model' => (string) ($route['model'] ?? ''),
            'system' => $system,
            'user' => $user,
        ];
    }

    private function matchedTerms(string $haystack, array $terms, bool $exactMatch = true): array
    {
        $matches = [];

        foreach ($terms as $term) {
            $needle = trim((string) $term);
            if ($needle === '') {
                continue;
            }

            $needleLower = Str::lower($needle);
            $found = $exactMatch
                ? str_contains($haystack, $needleLower)
                : str_contains($haystack, $this->normalizeUrlForMatch($needleLower));

            if ($found) {
                $matches[] = $needle;
            }
        }

        return array_values(array_unique($matches));
    }

    private function normalizeUrlForMatch(string $url): string
    {
        $value = trim($url);
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = rtrim($value, '/');

        return $value;
    }

    private function joinTerms(array $terms): string
    {
        $clean = collect($terms)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        return $clean === [] ? '-' : implode(', ', $clean);
    }

    /**
     * @return array{key:string,intent:string,query_text:string}
     */
    public function resolvePromptVariant(LlmTrackingQuery $query, string $variantKey = ''): array
    {
        $variants = $this->queryVariants($query);
        if ($variantKey !== '') {
            $match = collect($variants)->firstWhere('key', $variantKey);
            if (is_array($match)) {
                return $match;
            }
        }

        return $variants[array_rand($variants)];
    }

    /**
     * @return array<int,array{key:string,intent:string,query_text:string}>
     */
    public function queryVariants(LlmTrackingQuery $query): array
    {
        $stored = collect((array) ($query->query_variants ?? []))
            ->map(function ($variant): array {
                if (is_string($variant)) {
                    return ['key' => Str::slug($variant), 'intent' => 'custom', 'query_text' => $variant];
                }

                return [
                    'key' => (string) ($variant['key'] ?? Str::slug((string) ($variant['query_text'] ?? 'custom'))),
                    'intent' => (string) ($variant['intent'] ?? 'custom'),
                    'query_text' => (string) ($variant['query_text'] ?? ''),
                ];
            })
            ->filter(fn (array $variant): bool => trim((string) $variant['query_text']) !== '')
            ->values()
            ->all();

        if ($stored !== []) {
            return $stored;
        }

        $base = trim((string) $query->query_text);
        $brand = trim((string) ($query->target_brand ?? ''));

        return [
            ['key' => 'exact', 'intent' => 'exact', 'query_text' => $base],
            ['key' => 'buyer_intent', 'intent' => 'buyer', 'query_text' => $base . ' and GEO'],
            ['key' => 'comparison_intent', 'intent' => 'comparison', 'query_text' => 'best alternatives and comparisons for ' . $base],
            ['key' => 'category_intent', 'intent' => 'category', 'query_text' => 'tools to improve AI search visibility'],
            ['key' => 'problem_intent', 'intent' => 'problem', 'query_text' => ($brand !== '' ? 'how to solve AI visibility gaps like ' . $brand : 'how to solve AI visibility gaps')],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $authorityEntities
     * @return array<int,array<string,mixed>>
     */
    private function hitsFromAuthorityEntities(array $authorityEntities): array
    {
        return collect($authorityEntities)
            ->filter(fn (array $entity): bool => in_array((string) ($entity['entity_category'] ?? ''), ['competitor', 'benchmark', 'complementary_platform'], true))
            ->map(fn (array $entity): array => [
                'term' => (string) ($entity['brand_name'] ?? ''),
                'count' => (int) ($entity['mention_count'] ?? 1),
                'first_position' => null,
                'first_sentence_index' => null,
                'context_snippets' => (array) ($entity['context_snippets'] ?? []),
                'normalized_position' => null,
                'bucket' => match (true) {
                    ((int) ($entity['rank'] ?? 99)) <= 2 => 'first',
                    ((int) ($entity['rank'] ?? 99)) <= 5 => 'middle',
                    default => 'last',
                },
            ])
            ->filter(fn (array $hit): bool => trim((string) $hit['term']) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function providerEvidence(LlmTrackingQuery $query, ?int $excludeRunId = null): array
    {
        $runs = LlmTrackingQueryRun::query()
            ->where('llm_tracking_query_id', $query->id)
            ->where('status', 'succeeded')
            ->when($excludeRunId, fn ($builder) => $builder->where('id', '!=', $excludeRunId))
            ->latest('run_at')
            ->limit(30)
            ->get(['provider', 'brand_mentioned']);

        return [
            'providers_seen' => $runs->pluck('provider')->filter()->unique()->values()->all(),
            'providers_with_brand' => $runs->filter(fn (LlmTrackingQueryRun $run): bool => (bool) $run->brand_mentioned)->pluck('provider')->filter()->unique()->values()->all(),
            'run_count' => $runs->count(),
        ];
    }
}
