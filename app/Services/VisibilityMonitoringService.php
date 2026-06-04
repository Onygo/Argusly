<?php

namespace App\Services;

use App\Jobs\RunVisibilityCheckJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\VisibilityCheck;
use App\Models\VisibilityPromptTemplate;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityResult;
use App\Models\VisibilitySnapshot;
use App\Services\Llm\LlmPromptRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class VisibilityMonitoringService
{
    public function __construct(
        private readonly EvidenceService $evidence,
        private readonly ContentLanguageService $languages,
        private readonly LlmPromptRuntime $llm,
    ) {}

    /**
     * @param  array{provider: string, query: string, brand?: string|null, status?: string|null}  $attributes
     */
    public function createCheck(Account $account, Brand $brand, array $attributes): VisibilityCheck
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $provider = $attributes['provider'];
        $status = $attributes['status'] ?? 'active';

        if (! in_array($provider, VisibilityCheck::PROVIDERS, true)) {
            throw new InvalidArgumentException("Invalid visibility provider [{$provider}].");
        }

        if (! in_array($status, VisibilityCheck::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid visibility status [{$status}].");
        }

        return VisibilityCheck::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'provider' => $provider,
                'query' => trim($attributes['query']),
            ],
            [
                'brand' => $attributes['brand'] ?? $brand->name,
                'status' => $status,
            ],
        );
    }

    public function queueCheck(VisibilityCheck $check): void
    {
        $idempotencySuffix = $check->last_checked_at?->timestamp ?? 'pending';

        app(OutboxService::class)->enqueue(
            $check->account,
            $check->brandModel,
            'ai_visibility_provider_call',
            [
                'idempotency_key' => "visibility-check:{$check->id}:{$idempotencySuffix}",
                'visibility_check_id' => $check->id,
                'provider' => $check->provider,
                'query' => $check->query,
                'brand' => $check->brand,
                'prepared_for_external_call' => true,
            ],
        );

        RunVisibilityCheckJob::dispatch($check->id);
    }

    public function runPlaceholderCheck(VisibilityCheck $check): VisibilityResult
    {
        $score = $this->placeholderScore($check);
        $mentionFound = $score >= 35;
        $position = $mentionFound ? max(1, (int) ceil((100 - $score) / 10)) : null;

        $result = $check->results()->create([
            'account_id' => $check->account_id,
            'brand_id' => $check->brand_id,
            'provider' => $check->provider,
            'query' => $check->query,
            'language' => 'en',
            'locale' => 'en_US',
            'market' => null,
            'brand' => $check->brand,
            'score' => $score,
            'position' => $position,
            'mention_found' => $mentionFound,
            'metadata' => [
                'placeholder' => true,
                'future_providers' => VisibilityCheck::PROVIDERS,
                'architecture' => [
                    'google' => 'planned',
                    'google_ai_overviews' => 'planned',
                    'chatgpt' => 'planned',
                    'claude' => 'planned',
                    'gemini' => 'planned',
                    'perplexity' => 'planned',
                ],
            ],
            'captured_at' => now(),
        ]);
        $providerRun = $this->recordPlaceholderProviderRun($check, $result);

        $check->forceFill(['last_checked_at' => $result->captured_at])->save();
        $this->evidence->createForSubject($result, [
            'evidence_type' => str_contains($check->provider, 'Google') ? 'search_result' : 'ai_answer',
            'title' => "{$check->provider} visibility result",
            'snippet' => $mentionFound
                ? "{$check->brand} was found for {$check->query}."
                : "{$check->brand} was not found for {$check->query}.",
            'raw_payload' => [
                'provider' => $check->provider,
                'query' => $check->query,
                'brand' => $check->brand,
                'score' => $score,
                'position' => $position,
                'mention_found' => $mentionFound,
                'placeholder' => true,
            ],
            'confidence_score' => $score,
            'captured_at' => $result->captured_at,
        ]);
        $this->evidence->createForSubject($providerRun, [
            'evidence_type' => 'provider_payload',
            'title' => "{$check->provider} placeholder payload",
            'snippet' => $providerRun->normalized_answer,
            'raw_payload' => [
                'provider_run_id' => $providerRun->id,
                'provider' => $providerRun->provider,
                'query' => $providerRun->query,
                'placeholder' => true,
            ],
            'confidence_score' => $score,
            'captured_at' => $providerRun->captured_at,
        ]);
        $this->captureSnapshot($check->account, $check->brandModel, $check->provider, $result->captured_at, [
            'language' => $result->language,
            'locale' => $result->locale,
            'market' => $result->market,
        ]);
        app(DomainEventService::class)->recordForSubject('VisibilityCheckCompleted', $result, null, [
            'visibility_check_id' => $check->id,
            'provider' => $result->provider,
            'query' => $result->query,
            'language' => $result->language,
            'locale' => $result->locale,
            'market' => $result->market,
            'score' => $result->score,
            'position' => $result->position,
            'mention_found' => $result->mention_found,
        ], $result->captured_at);

        return $result;
    }

    /**
     * @param  array{name: string, prompt: string, language?: string|null, intent?: string|null, locale?: string|null, market?: string|null, persona?: string|null, status?: string|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function createPromptTemplate(Account $account, Brand $brand, array $attributes): VisibilityPromptTemplate
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $status = $attributes['status'] ?? 'active';
        $language = $this->languages->validateForBrand($attributes['language'] ?? $this->languages->defaultFor($brand, $account), $brand);

        if (! in_array($status, VisibilityPromptTemplate::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid visibility prompt template status [{$status}].");
        }

        return VisibilityPromptTemplate::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'name' => $attributes['name'],
            ],
            [
                'prompt' => $attributes['prompt'],
                'language' => $language,
                'intent' => $attributes['intent'] ?? null,
                'locale' => $attributes['locale'] ?? $this->languages->localeForLanguage($language),
                'market' => $attributes['market'] ?? null,
                'persona' => $attributes['persona'] ?? null,
                'status' => $status,
                'metadata' => $attributes['metadata'] ?? null,
            ],
        );
    }

    /**
     * @return Collection<int, VisibilityCheck>
     */
    public function checksForTenant(Account $account, Brand $brand): Collection
    {
        return $this->tenantChecks($account, $brand)
            ->with([
                'latestResult' => fn ($query) => $query->with('evidenceItems.source')->limit(1),
                'latestProviderRun' => fn ($query) => $query
                    ->with(['promptTemplate', 'citations', 'answerEntities', 'evidenceItems.source'])
                    ->limit(1),
            ])
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, VisibilityPromptTemplate>
     */
    public function promptTemplatesForTenant(Account $account, Brand $brand, array $filters = []): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return VisibilityPromptTemplate::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['market'] ?? null, fn (Builder $query, string $market) => $query->where('market', $market))
            ->with(['providerRuns' => fn ($query) => $query
                ->with(['citations', 'answerEntities'])
                ->latest('captured_at')])
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, VisibilityProviderRun>
     */
    public function providerRunsForTenant(Account $account, Brand $brand, int $limit = 10, array $filters = []): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return VisibilityProviderRun::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['market'] ?? null, fn (Builder $query, string $market) => $query->where('market', $market))
            ->with(['promptTemplate', 'citations', 'answerEntities', 'evidenceItems.source'])
            ->latest('captured_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, VisibilitySnapshot>
     */
    public function timelineForTenant(Account $account, Brand $brand, int $limit = 30, array $filters = []): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return VisibilitySnapshot::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['market'] ?? null, fn (Builder $query, string $market) => $query->where('market', $market))
            ->latest('captured_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * @return array{checks: int, latest_score: int|null, mentions_found: int, providers: int}
     */
    public function dashboardStats(Account $account, ?Brand $brand): array
    {
        if (! $brand) {
            return ['checks' => 0, 'latest_score' => null, 'mentions_found' => 0, 'providers' => 0];
        }

        $checks = $this->tenantChecks($account, $brand);
        $latestSnapshot = VisibilitySnapshot::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->latest('captured_at')
            ->first();

        return [
            'checks' => (clone $checks)->count(),
            'latest_score' => $latestSnapshot?->score,
            'mentions_found' => (int) VisibilityResult::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('mention_found', true)
                ->count(),
            'providers' => (clone $checks)->distinct('provider')->count('provider'),
        ];
    }

    /**
     * @return Collection<string, VisibilityProviderRun>
     */
    public function latestRunsByLanguage(Account $account, Brand $brand, array $filters = []): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return VisibilityProviderRun::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($filters['market'] ?? null, fn (Builder $query, string $market) => $query->where('market', $market))
            ->completed()
            ->latest('captured_at')
            ->get()
            ->unique('language')
            ->keyBy('language');
    }

    public function captureSnapshot(Account $account, Brand $brand, ?string $provider = null, mixed $capturedAt = null, array $context = []): VisibilitySnapshot
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $capturedAt ??= now();
        $language = $context['language'] ?? null;
        $market = $context['market'] ?? null;

        $results = VisibilityResult::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($provider, fn (Builder $query) => $query->where('provider', $provider))
            ->when($language, fn (Builder $query, string $value) => $query->where('language', $value))
            ->when($market, fn (Builder $query, string $value) => $query->where('market', $value))
            ->where('captured_at', '>=', now()->subDay())
            ->get();

        return VisibilitySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'language' => $language,
            'locale' => $context['locale'] ?? null,
            'market' => $market,
            'persona' => $context['persona'] ?? null,
            'intent' => $context['intent'] ?? null,
            'score' => $results->isEmpty() ? null : (int) round($results->avg('score')),
            'position' => $results->whereNotNull('position')->isEmpty() ? null : (int) round($results->whereNotNull('position')->avg('position')),
            'mention_found' => $results->contains('mention_found', true),
            'results_count' => $results->count(),
            'metadata' => [
                'placeholder' => true,
                'aggregation_window' => '24 hours',
            ],
            'captured_at' => $capturedAt,
        ]);
    }

    private function tenantChecks(Account $account, Brand $brand): Builder
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return VisibilityCheck::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id);
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Visibility brand must belong to the account.');
        }
    }

    private function recordPlaceholderProviderRun(VisibilityCheck $check, VisibilityResult $result): VisibilityProviderRun
    {
        $normalizedAnswer = $result->mention_found
            ? "{$check->brand} appears in the placeholder {$check->provider} answer for this query."
            : "{$check->brand} does not appear in the placeholder {$check->provider} answer for this query.";
        $llmResponse = $this->llm->generate(
            account: $check->account,
            brand: $check->brandModel,
            user: null,
            purpose: 'visibility_check',
            messages: [[
                'role' => 'user',
                'content' => $check->query,
            ]],
            systemPrompt: 'You are Argusly AI visibility runtime. Produce a monitored answer for the tracked brand.',
            fakeContent: $normalizedAnswer,
            metadata: [
                'visibility_check_id' => $check->id,
                'visibility_provider' => $check->provider,
                'brand_name' => $check->brand,
            ],
        );
        $normalizedAnswer = $llmResponse->content;

        $run = $check->providerRuns()->create([
            'account_id' => $check->account_id,
            'brand_id' => $check->brand_id,
            'provider' => $check->provider,
            'model' => $llmResponse->model,
            'query' => $check->query,
            'language' => 'en',
            'locale' => 'en_US',
            'input_language' => 'en',
            'normalized_answer_language' => 'en',
            'raw_response' => json_encode([
                'provider' => $check->provider,
                'query' => $check->query,
                'answer' => $normalizedAnswer,
                'placeholder' => true,
                'llm_provider' => $llmResponse->provider,
                'llm_model' => $llmResponse->model,
            ], JSON_THROW_ON_ERROR),
            'normalized_answer' => $normalizedAnswer,
            'latency_ms' => 0,
            'cost_credits' => 0,
            'status' => 'completed',
            'captured_at' => $result->captured_at,
            'metadata' => [
                'placeholder' => true,
                'llm_response' => $llmResponse->toArray(),
                'result_id' => $result->id,
                'provider_adapters' => [
                    'ChatGPT' => 'planned',
                    'Claude' => 'planned',
                    'Gemini' => 'planned',
                    'Perplexity' => 'planned',
                    'Google AI Overviews' => 'planned',
                ],
            ],
        ]);

        $domain = str($check->brand)->slug()->append('.example')->toString();
        $run->citations()->create([
            'account_id' => $check->account_id,
            'brand_id' => $check->brand_id,
            'url' => "https://{$domain}/ai-visibility-reference",
            'domain' => $domain,
            'title' => "{$check->brand} visibility reference",
            'snippet' => 'Deterministic placeholder citation reserved for future provider parser output.',
            'rank' => $result->position ?? 1,
            'trust_score' => $result->score,
            'metadata' => ['placeholder' => true],
        ]);

        $run->answerEntities()->create([
            'account_id' => $check->account_id,
            'brand_id' => $check->brand_id,
            'entity_name' => $check->brand,
            'entity_type' => 'brand',
            'sentiment' => $result->mention_found ? 'positive' : 'neutral',
            'position' => $result->position,
            'metadata' => ['placeholder' => true],
        ]);

        return $run;
    }

    private function placeholderScore(VisibilityCheck $check): int
    {
        $hash = crc32($check->provider.'|'.$check->query.'|'.$check->brand.'|'.$check->brand_id);

        return 20 + (int) ($hash % 76);
    }
}
