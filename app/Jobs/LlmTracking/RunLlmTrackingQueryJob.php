<?php

namespace App\Jobs\LlmTracking;

use App\Jobs\Stats\UpdateContentAiVisibilityJob;
use App\Jobs\PageIntelligence\LinkLlmTrackingSourcesToPagesJob;
use App\Models\CreditReservation;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Services\CreditReservationService;
use App\Services\LlmTracking\LlmAuthorityCandidateService;
use App\Services\LlmTracking\LlmVisibilityTrackingService;
use App\Services\PlanQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunLlmTrackingQueryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $queryId,
        public readonly ?string $runDate = null,
    ) {}

    public function uniqueId(): string
    {
        $dateKey = $this->runDate ?: now()->toDateString();

        return 'llm-tracking-query:' . $this->queryId . ':' . $dateKey;
    }

    public function handle(
        LlmVisibilityTrackingService $service,
        PlanQuotaService $planQuotaService,
        CreditReservationService $reservations,
        LlmAuthorityCandidateService $candidateService,
    ): void {
        $query = LlmTrackingQuery::query()
            ->with(['site.workspace'])
            ->find($this->queryId);

        if (! $query || ! $query->is_active || ! $query->site?->workspace) {
            return;
        }

        $runMoment = CarbonImmutable::now();
        $runDateKey = $this->resolveRunDateKey();
        $route = $service->resolveRoute($query);
        $variant = $service->resolvePromptVariant($query);
        $route['variant_key'] = (string) ($variant['key'] ?? 'exact');
        $cachedKey = $this->buildCachedKey($query, (string) ($route['model'] ?? ''), $runDateKey, (string) ($variant['key'] ?? 'exact'), (string) ($variant['query_text'] ?? $query->query_text));

        $cachedRun = LlmTrackingQueryRun::query()
            ->where('llm_tracking_query_id', $query->id)
            ->whereDate('run_at', $runDateKey)
            ->where('cached_key', $cachedKey)
            ->where('status', 'succeeded')
            ->where('is_cached', false)
            ->latest('run_at')
            ->first();

        if ($cachedRun) {
            $newCachedRun = $this->createCachedRun($query, $cachedRun, $cachedKey, $runMoment);
            $query->forceFill(['last_run_at' => $runMoment])->save();
            LinkLlmTrackingSourcesToPagesJob::dispatch($newCachedRun->id)
                ->onQueue($this->queue ?? 'default');

            return;
        }

        $run = LlmTrackingQueryRun::query()->create([
            'llm_tracking_query_id' => $query->id,
            'run_at' => $runMoment,
            'provider' => (string) ($route['provider'] ?? config('llm.default_provider', 'openai')),
            'model' => (string) ($route['model'] ?? ''),
            'provider_model_key' => Str::lower((string) ($route['provider'] ?? config('llm.default_provider', 'openai')) . ':' . (string) ($route['model'] ?? '')),
            'prompt_variant_key' => (string) ($variant['key'] ?? 'exact'),
            'prompt_variant_text' => (string) ($variant['query_text'] ?? $query->query_text),
            'prompt_variant_intent' => (string) ($variant['intent'] ?? 'exact'),
            'status' => 'running',
            'cached_key' => $cachedKey,
            'is_cached' => false,
        ]);

        $reservation = null;

        try {
            $planQuotaService->assertCanRunLlmQuery($query->site->workspace, $query->site);

            $reservation = $reservations->reserve(
                clientSiteId: (string) $query->site->id,
                amount: 1,
                idempotencyKey: 'llm_tracking_run:' . $run->id,
                purpose: 'llm_tracking_run',
                context: $query,
                options: [
                    'metadata' => [
                        'feature' => 'llm_tracking',
                        'query_id' => (int) $query->id,
                        'run_id' => (int) $run->id,
                        'model' => (string) ($route['model'] ?? ''),
                        'provider' => (string) ($route['provider'] ?? ''),
                    ],
                ],
            );

            $result = $service->run($query, $route);

            if ($reservation instanceof CreditReservation) {
                $reservations->capture($reservation, [
                    'metadata' => [
                        'feature' => 'llm_tracking',
                        'query_id' => (int) $query->id,
                        'run_id' => (int) $run->id,
                        'model' => (string) ($result['model'] ?? $route['model'] ?? ''),
                    ],
                ]);
            }

            $run->update([
                'provider' => (string) ($result['provider'] ?? $run->provider),
                'model' => (string) ($result['model'] ?? $run->model),
                'prompt_variant_key' => $result['prompt_variant_key'] ?? null,
                'prompt_variant_text' => $result['prompt_variant_text'] ?? null,
                'prompt_variant_intent' => $result['prompt_variant_intent'] ?? null,
                'provider_model_key' => $result['provider_model_key'] ?? Str::lower((string) ($result['provider'] ?? $run->provider) . ':' . (string) ($result['model'] ?? $run->model)),
                'status' => 'succeeded',
                'raw_response' => json_encode($result['raw_response'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'parsed_payload' => (array) ($result['parsed_payload'] ?? []),
                'answer_text' => (string) ($result['answer_text'] ?? ''),
                'normalized_response' => (string) ($result['normalized_response'] ?? $result['answer_text'] ?? ''),
                'answer_json' => $result['answer_json'] ?? null,
                'brand_hits' => (array) ($result['brand_hits'] ?? []),
                'competitor_hits' => (array) ($result['competitor_hits'] ?? []),
                'detected_brands' => (array) ($result['detected_brands'] ?? []),
                'detected_competitors' => (array) ($result['detected_competitors'] ?? []),
                'authority_entities' => (array) ($result['authority_entities'] ?? []),
                'entity_presence' => (array) ($result['entity_presence'] ?? []),
                'url_hits' => (array) ($result['url_hits'] ?? []),
                'citation_ranking' => (array) ($result['citation_ranking'] ?? []),
                'sources' => (array) ($result['sources'] ?? []),
                'detected_domains' => (array) ($result['detected_domains'] ?? []),
                'first_mention_index' => $result['first_mention_index'] ?? null,
                'first_mention_block' => $result['first_mention_block'] ?? null,
                'first_mention_context' => $result['first_mention_context'] ?? null,
                'share_of_voice_snapshot' => (array) ($result['share_of_voice_snapshot'] ?? []),
                'suggestions' => (array) ($result['suggestions'] ?? []),
                'brand_mentioned' => (bool) ($result['brand_mentioned'] ?? false),
                'urls_cited' => (bool) ($result['urls_cited'] ?? false),
                'competitors_mentioned' => (bool) ($result['competitors_mentioned'] ?? false),
                'presence_score' => $result['presence_score'] ?? null,
                'position_score' => $result['position_score'] ?? null,
                'citation_score' => $result['citation_score'] ?? null,
                'context_score' => $result['context_score'] ?? null,
                'context_label' => $result['context_label'] ?? null,
                'sentiment_score' => $result['sentiment_score'] ?? null,
                'sentiment_label' => $result['sentiment_label'] ?? null,
                'competitive_score' => $result['competitive_score'] ?? null,
                'competitor_share_score' => $result['competitor_share_score'] ?? null,
                'owned_visibility_score' => $result['owned_visibility_score'] ?? null,
                'earned_visibility_score' => $result['earned_visibility_score'] ?? null,
                'competitor_pressure_score' => $result['competitor_pressure_score'] ?? null,
                'citation_diversity_score' => $result['citation_diversity_score'] ?? null,
                'model_confidence_score' => $result['model_confidence_score'] ?? null,
                'real_world_gap_score' => $result['real_world_gap_score'] ?? null,
                'ai_visibility_score' => $result['ai_visibility_score'] ?? null,
                'visibility_breakdown' => (array) ($result['visibility_breakdown'] ?? []),
                'error_message' => null,
                'cached_key' => $cachedKey,
                'is_cached' => false,
            ]);

            $run->refresh();
            $candidateService->recordRun($run);

            $query->forceFill(['last_run_at' => $runMoment])->save();

            $planQuotaService->incrementUsage(
                workspace: $query->site->workspace,
                site: $query->site,
                metric: PlanQuotaService::METRIC_LLM_QUERIES_RUN,
                amount: 1,
            );

            BuildLlmTrackingAggregatesJob::dispatch($runDateKey)
                ->onQueue($this->queue ?? 'default');

            LinkLlmTrackingSourcesToPagesJob::dispatch($run->id)
                ->onQueue($this->queue ?? 'default');

            $analyticsSiteId = (string) ($query->site->analyticsSite?->id ?? '');
            if ($analyticsSiteId !== '') {
                UpdateContentAiVisibilityJob::dispatch($analyticsSiteId)
                    ->onQueue($this->queue ?? 'default');
            }
        } catch (\Throwable $exception) {
            if ($reservation instanceof CreditReservation && $reservation->isReserved()) {
                $reservations->release($reservation, 'llm_tracking_run_failed', [
                    'failureCode' => 'llm_tracking_failed',
                    'failureMessage' => $exception->getMessage(),
                ]);
            }

            $run->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveRunDateKey(): string
    {
        $runDate = trim((string) $this->runDate);
        if ($runDate === '') {
            return now()->toDateString();
        }

        return CarbonImmutable::parse($runDate)->toDateString();
    }

    private function buildCachedKey(LlmTrackingQuery $query, string $model, string $runDate, string $variantKey, string $variantText): string
    {
        $parts = [
            Str::lower(trim((string) $query->query_text)),
            Str::lower(trim($variantKey)),
            Str::lower(trim($variantText)),
            Str::lower(trim((string) $query->locale)),
            Str::lower(trim($model)),
            $runDate,
            implode('|', $this->normalizeList((array) ($query->brand_terms ?? []))),
            implode('|', $this->normalizeList((array) ($query->competitor_terms ?? []))),
            implode('|', $this->normalizeList((array) ($query->target_urls ?? []))),
        ];

        return sha1(implode('::', $parts));
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,string>
     */
    private function normalizeList(array $items): array
    {
        $clean = collect($items)
            ->map(fn ($item): string => Str::lower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($clean);

        return $clean;
    }

    private function createCachedRun(
        LlmTrackingQuery $query,
        LlmTrackingQueryRun $cachedRun,
        string $cachedKey,
        CarbonImmutable $runDate,
    ): LlmTrackingQueryRun {
        return LlmTrackingQueryRun::query()->create([
            'llm_tracking_query_id' => $query->id,
            'run_at' => $runDate,
            'provider' => $cachedRun->provider,
            'model' => $cachedRun->model,
            'prompt_variant_key' => $cachedRun->prompt_variant_key,
            'prompt_variant_text' => $cachedRun->prompt_variant_text,
            'prompt_variant_intent' => $cachedRun->prompt_variant_intent,
            'provider_model_key' => $cachedRun->provider_model_key,
            'status' => 'succeeded',
            'raw_response' => $cachedRun->raw_response,
            'parsed_payload' => array_merge((array) ($cachedRun->parsed_payload ?? []), [
                'cache' => [
                    'source_run_id' => (int) $cachedRun->id,
                    'cached_at' => now()->toIso8601String(),
                ],
            ]),
            'answer_text' => $cachedRun->answer_text,
            'normalized_response' => $cachedRun->normalized_response,
            'answer_json' => $cachedRun->answer_json,
            'brand_hits' => $cachedRun->brand_hits,
            'competitor_hits' => $cachedRun->competitor_hits,
            'detected_brands' => $cachedRun->detected_brands,
            'detected_competitors' => $cachedRun->detected_competitors,
            'authority_entities' => $cachedRun->authority_entities,
            'entity_presence' => $cachedRun->entity_presence,
            'url_hits' => $cachedRun->url_hits,
            'citation_ranking' => $cachedRun->citation_ranking,
            'sources' => $cachedRun->sources,
            'detected_domains' => $cachedRun->detected_domains,
            'first_mention_index' => $cachedRun->first_mention_index,
            'first_mention_block' => $cachedRun->first_mention_block,
            'first_mention_context' => $cachedRun->first_mention_context,
            'share_of_voice_snapshot' => $cachedRun->share_of_voice_snapshot,
            'suggestions' => $cachedRun->suggestions,
            'brand_mentioned' => (bool) $cachedRun->brand_mentioned,
            'urls_cited' => (bool) $cachedRun->urls_cited,
            'competitors_mentioned' => (bool) $cachedRun->competitors_mentioned,
            'presence_score' => $cachedRun->presence_score,
            'position_score' => $cachedRun->position_score,
            'citation_score' => $cachedRun->citation_score,
            'context_score' => $cachedRun->context_score,
            'context_label' => $cachedRun->context_label,
            'sentiment_score' => $cachedRun->sentiment_score,
            'sentiment_label' => $cachedRun->sentiment_label,
            'competitive_score' => $cachedRun->competitive_score,
            'competitor_share_score' => $cachedRun->competitor_share_score,
            'owned_visibility_score' => $cachedRun->owned_visibility_score,
            'earned_visibility_score' => $cachedRun->earned_visibility_score,
            'competitor_pressure_score' => $cachedRun->competitor_pressure_score,
            'citation_diversity_score' => $cachedRun->citation_diversity_score,
            'model_confidence_score' => $cachedRun->model_confidence_score,
            'real_world_gap_score' => $cachedRun->real_world_gap_score,
            'ai_visibility_score' => $cachedRun->ai_visibility_score,
            'visibility_breakdown' => $cachedRun->visibility_breakdown,
            'error_message' => null,
            'cached_key' => $cachedKey,
            'is_cached' => true,
        ]);
    }
}
