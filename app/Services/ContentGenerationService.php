<?php

namespace App\Services;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Jobs\GenerateContentAssetJob;
use App\Models\ContentAsset;
use App\Models\GeneratedAsset;
use App\Models\User;
use App\Services\Signals\SignalManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ContentGenerationService
{
    public function __construct(
        private readonly GenerationRunLogger $logger,
        private readonly CreditService $credits,
        private readonly SignalManager $signals,
        private readonly LlmResolver $resolver,
        private readonly LlmClientInterface $llm,
    ) {}

    /**
     * @param  array{type?: string|null, prompt?: string|null, language?: string|null}  $attributes
     */
    public function requestForContentAsset(ContentAsset $contentAsset, User $user, array $attributes = []): GeneratedAsset
    {
        $type = $attributes['type'] ?? $this->typeForContentAsset($contentAsset);

        $this->ensureType($type);

        $language = $attributes['language'] ?? $contentAsset->language;
        $llm = $this->resolver->resolve($contentAsset->account, $contentAsset->brand);
        $creditTransaction = $this->credits->consume(
            $contentAsset->account,
            $user,
            'content_generation',
            'Content generation requested.',
            $contentAsset,
            [
                'brand_id' => $contentAsset->brand_id,
                'content_asset_id' => $contentAsset->id,
                'type' => $type,
                'language' => $language,
            ],
        );

        $creditTransaction->forceFill(['type' => 'content_generation'])->save();

        $generatedAsset = GeneratedAsset::query()->create([
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'type' => $type,
            'status' => 'queued',
            'prompt' => $attributes['prompt'] ?? null,
            'input_payload' => [
                'content_asset' => [
                    'id' => $contentAsset->id,
                    'uuid' => $contentAsset->uuid,
                    'type' => $contentAsset->type,
                    'status' => $contentAsset->status,
                    'title' => $contentAsset->title,
                    'excerpt' => $contentAsset->excerpt,
                    'language' => $contentAsset->language,
                    'locale' => $contentAsset->locale,
                    'property_id' => $contentAsset->property_id,
                    'channel_id' => $contentAsset->channel_id,
                ],
                'llm' => [
                    'source' => $llm['source'],
                    'fallback_provider' => $llm['fallback_provider']['provider'] ?? null,
                    'fallback_model' => $llm['fallback_model']['model'] ?? null,
                    'temperature' => $llm['temperature'],
                    'max_tokens' => $llm['max_tokens'],
                    'credits_precharged' => true,
                    'credit_transaction_id' => $creditTransaction->id,
                ],
            ],
            'language' => $language,
            'locale' => $language === $contentAsset->language
                ? $contentAsset->locale
                : app(ContentLanguageService::class)->localeForLanguage($language),
            'provider' => $llm['provider']['provider'],
            'model' => $llm['model']['model'],
            'cost_credits' => $this->credits->cost('content_generation'),
            'created_by' => $user->id,
        ]);

        $this->logger->queued($generatedAsset);

        GenerateContentAssetJob::dispatch($generatedAsset->id);

        return $generatedAsset;
    }

    public function processGeneratedAsset(GeneratedAsset $generatedAsset): GeneratedAsset
    {
        if (! in_array($generatedAsset->status, ['queued', 'processing'], true)) {
            return $generatedAsset;
        }

        $generatedAsset->forceFill(['status' => 'processing'])->save();
        $this->logger->processing($generatedAsset);

        $output = $this->generateOutput($generatedAsset);

        $generatedAsset->forceFill([
            'status' => 'completed',
            'title' => $output['title'],
            'body' => $output['body'],
            'output_payload' => $output,
        ])->save();

        $this->logger->completed($generatedAsset);
        $this->signals->produce($generatedAsset);

        return $generatedAsset->refresh();
    }

    private function typeForContentAsset(ContentAsset $contentAsset): string
    {
        return in_array($contentAsset->type, GeneratedAsset::TYPES, true)
            ? $contentAsset->type
            : 'refresh';
    }

    private function ensureType(string $type): void
    {
        if (! in_array($type, GeneratedAsset::TYPES, true)) {
            throw new InvalidArgumentException("Invalid generated asset type [{$type}].");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generateOutput(GeneratedAsset $generatedAsset): array
    {
        $source = $generatedAsset->contentAsset;
        $baseTitle = $source?->title ?? $generatedAsset->title ?? 'Untitled content';
        $type = Str::of($generatedAsset->type)->replace('_', ' ')->headline();
        $fallbackBody = implode("\n\n", [
            "# {$baseTitle}",
            "This {$generatedAsset->type} draft sharpens the existing asset into a clearer, more useful piece for the selected audience.",
            "## Main angle\nTurn the topic into a practical answer. Start with the reader's problem, explain what has changed, and make the recommendation concrete enough for an editor to expand with proof and examples.",
            "## Recommended improvements\n- Make the first paragraph state the reader problem directly.\n- Add one specific example from the brand context.\n- Include source links or evidence before publication.\n- Close with a next step that fits the asset's intent.",
            "## Draft direction\nUse the current content asset as the foundation, then improve clarity, structure and usefulness. Avoid generic claims and keep the language tied to the brand's actual offer.",
        ]);
        $response = $this->llm->generate(new LlmRequest(
            provider: $generatedAsset->provider ?? 'fake',
            model: $generatedAsset->model ?? 'static-foundation-v1',
            messages: [
                [
                    'role' => 'user',
                    'content' => $generatedAsset->prompt ?: "Create a {$generatedAsset->type} draft for {$baseTitle}. Use the current asset as context and produce publishable Markdown, not a placeholder.",
                ],
            ],
            systemPrompt: 'You are Argusly content generation runtime. Produce useful, specific marketing content drafts. Avoid placeholder language and meta commentary.',
            temperature: null,
            maxTokens: null,
            metadata: [
                'purpose' => 'content_generation',
                'account_id' => $generatedAsset->account_id,
                'brand_id' => $generatedAsset->brand_id,
                'user_id' => $generatedAsset->created_by,
                'fallback_provider' => $generatedAsset->input_payload['llm']['fallback_provider'] ?? null,
                'fallback_model' => $generatedAsset->input_payload['llm']['fallback_model'] ?? null,
                'credits_precharged' => $generatedAsset->input_payload['llm']['credits_precharged'] ?? false,
                'credit_transaction_id' => $generatedAsset->input_payload['llm']['credit_transaction_id'] ?? null,
                'content_asset_id' => $source?->id,
                'generated_asset_id' => $generatedAsset->id,
                'type' => $generatedAsset->type,
                'language' => $generatedAsset->language,
                'fake_content' => $fallbackBody,
            ],
        ));

        return [
            'title' => "{$type}: {$baseTitle}",
            'body' => $response->content,
            'provider' => $response->provider,
            'model' => $response->model,
            'llm_response' => $response->toArray(),
            'fake' => (bool) ($response->rawResponse['fake'] ?? false),
        ];
    }
}
