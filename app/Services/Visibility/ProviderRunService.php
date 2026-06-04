<?php

namespace App\Services\Visibility;

use App\Models\Account;
use App\Models\Brand;
use App\Models\VisibilityCheck;
use App\Models\VisibilityPromptTemplate;
use App\Models\VisibilityProviderRun;
use App\Services\ContentLanguageService;
use App\Services\DomainEventService;
use App\Services\Llm\LlmPromptRuntime;
use Throwable;

class ProviderRunService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ContentLanguageService $languages,
        private readonly LlmPromptRuntime $llm,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function runPrompt(
        Account $account,
        Brand $brand,
        string $provider,
        string $prompt,
        ?VisibilityCheck $check = null,
        ?VisibilityPromptTemplate $template = null,
        array $context = [],
    ): VisibilityProviderRun {
        $adapter = $this->providers->get($provider);
        $startedAt = microtime(true);
        $language = $this->languages->validateForBrand($context['language'] ?? $template?->language ?? $this->languages->defaultFor($brand, $account), $brand);
        $locale = $context['locale'] ?? $template?->locale ?? $this->languages->localeForLanguage($language);
        $market = $context['market'] ?? $template?->market ?? null;
        $persona = $context['persona'] ?? $template?->persona ?? null;
        $intent = $context['intent'] ?? $template?->intent ?? null;
        $fallbackAnswer = "{$adapter->name()} fake answer mentions {$brand->name} as a relevant option for {$prompt}.";
        $llmResponse = $this->llm->generate(
            account: $account,
            brand: $brand,
            user: null,
            purpose: 'visibility_check',
            messages: [[
                'role' => 'user',
                'content' => $prompt,
            ]],
            systemPrompt: 'You are Argusly AI visibility runtime. Answer the visibility prompt for monitoring and citation parsing.',
            fakeContent: $fallbackAnswer,
            metadata: [
                'visibility_provider' => $provider,
                'visibility_check_id' => $check?->id,
                'visibility_prompt_template_id' => $template?->id,
                'brand_name' => $brand->name,
                'language' => $language,
                'locale' => $locale,
                'market' => $market,
                'persona' => $persona,
                'intent' => $intent,
            ],
        );

        $run = VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'visibility_check_id' => $check?->id,
            'provider' => $adapter->name(),
            'model' => $llmResponse->model,
            'prompt_template_id' => $template?->id,
            'query' => $prompt,
            'language' => $language,
            'locale' => $locale,
            'market' => $market,
            'persona' => $persona,
            'intent' => $intent,
            'input_language' => $language,
            'target_market' => $market,
            'normalized_answer_language' => $language,
            'status' => 'processing',
            'captured_at' => now(),
            'metadata' => [
                'adapter_key' => $adapter->key(),
                'fake' => true,
                'llm_provider' => $llmResponse->provider,
                'llm_model' => $llmResponse->model,
                'language' => $language,
                'locale' => $locale,
                'market' => $market,
                'persona' => $persona,
                'intent' => $intent,
                'llm_response' => $llmResponse->toArray(),
            ],
        ]);

        try {
            $response = $adapter->runPrompt($prompt, [
                ...$context,
                'account' => $account->name,
                'brand' => $context['brand'] ?? $check?->brand ?? $brand->name,
                'brand_id' => $brand->id,
                'language' => $language,
                'locale' => $locale,
                'market' => $market,
                'persona' => $persona,
                'intent' => $intent,
                'answer' => $llmResponse->content,
            ]);
            $normalizedAnswer = $adapter->normalizeAnswer($response);
            $detectedLanguage = $this->detectedLanguage($response, $normalizedAnswer, $language);
            $citations = $adapter->extractCitations($response);
            $entities = $adapter->extractEntities($response);
            $score = $adapter->calculateVisibilityScore($normalizedAnswer, $citations, $entities, [
                ...$context,
                'brand' => $context['brand'] ?? $check?->brand ?? $brand->name,
                'language' => $language,
                'locale' => $locale,
                'market' => $market,
                'persona' => $persona,
                'intent' => $intent,
            ]);

            $run->forceFill([
                'raw_response' => json_encode($response, JSON_THROW_ON_ERROR),
                'normalized_answer' => $normalizedAnswer,
                'normalized_answer_language' => $detectedLanguage ?? $language,
                'detected_language' => $detectedLanguage,
                'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'cost_credits' => (int) ($context['cost_credits'] ?? data_get($response, 'usage.cost_credits', 0)),
                'status' => 'completed',
                'metadata' => [
                    ...($run->metadata ?? []),
                    'visibility_score' => $score,
                    'citation_count' => count($citations),
                    'entity_count' => count($entities),
                    'detected_language' => $detectedLanguage,
                ],
            ])->save();

            $this->storeCitations($run, $citations);
            $this->storeEntities($run, $entities);

            app(DomainEventService::class)->recordForSubject('VisibilityProviderRunCompleted', $run->refresh(), null, [
                'provider' => $run->provider,
                'adapter_key' => $adapter->key(),
                'language' => $run->language,
                'locale' => $run->locale,
                'market' => $run->market,
                'persona' => $run->persona,
                'intent' => $run->intent,
                'input_language' => $run->input_language,
                'target_market' => $run->target_market,
                'normalized_answer_language' => $run->normalized_answer_language,
                'detected_language' => $run->detected_language,
                'visibility_score' => $score,
                'citation_count' => count($citations),
                'entity_count' => count($entities),
            ]);

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'metadata' => [
                    ...($run->metadata ?? []),
                    'error' => $exception->getMessage(),
                ],
            ])->save();

            app(DomainEventService::class)->recordForSubject('VisibilityProviderRunFailed', $run->refresh(), null, [
                'provider' => $run->provider,
                'adapter_key' => $adapter->key(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     */
    private function storeCitations(VisibilityProviderRun $run, array $citations): void
    {
        foreach ($citations as $index => $citation) {
            $run->citations()->create([
                'account_id' => $run->account_id,
                'brand_id' => $run->brand_id,
                'url' => $citation['url'],
                'domain' => $citation['domain'] ?? parse_url($citation['url'], PHP_URL_HOST),
                'title' => $citation['title'] ?? null,
                'snippet' => $citation['snippet'] ?? null,
                'rank' => $citation['rank'] ?? $index + 1,
                'trust_score' => $citation['trust_score'] ?? null,
                'metadata' => $citation['metadata'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entities
     */
    private function storeEntities(VisibilityProviderRun $run, array $entities): void
    {
        foreach ($entities as $index => $entity) {
            $run->answerEntities()->create([
                'account_id' => $run->account_id,
                'brand_id' => $run->brand_id,
                'entity_name' => $entity['entity_name'],
                'entity_type' => $entity['entity_type'] ?? null,
                'sentiment' => $entity['sentiment'] ?? null,
                'position' => $entity['position'] ?? $index + 1,
                'metadata' => $entity['metadata'] ?? null,
            ]);
        }
    }

    private function detectedLanguage(array $response, string $normalizedAnswer, string $fallback): ?string
    {
        $detected = data_get($response, 'detected_language')
            ?? data_get($response, 'answer_language')
            ?? data_get($response, 'language');

        if (is_string($detected) && $detected !== '') {
            return strtolower($detected);
        }

        return $normalizedAnswer !== '' ? $fallback : null;
    }
}
