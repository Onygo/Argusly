<?php

namespace App\Services;

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
    ) {}

    /**
     * @param  array{type?: string|null, prompt?: string|null, language?: string|null}  $attributes
     */
    public function requestForContentAsset(ContentAsset $contentAsset, User $user, array $attributes = []): GeneratedAsset
    {
        $type = $attributes['type'] ?? $this->typeForContentAsset($contentAsset);

        $this->ensureType($type);

        $creditTransaction = $this->credits->consume(
            $contentAsset->account,
            $user,
            'content_generation',
            'Content generation requested.',
            $contentAsset,
            ['content_asset_id' => $contentAsset->id, 'type' => $type],
        );

        $language = $attributes['language'] ?? $contentAsset->language;

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
            ],
            'language' => $language,
            'locale' => $language === $contentAsset->language
                ? $contentAsset->locale
                : app(ContentLanguageService::class)->localeForLanguage($language),
            'provider' => 'argusly_fake',
            'model' => 'static-foundation-v1',
            'cost_credits' => $this->credits->cost('content_generation'),
            'created_by' => $user->id,
        ]);

        $creditTransaction->update([
            'subject_type' => $generatedAsset->getMorphClass(),
            'subject_id' => $generatedAsset->id,
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

        $output = $this->fakeOutput($generatedAsset);

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
    private function fakeOutput(GeneratedAsset $generatedAsset): array
    {
        $source = $generatedAsset->contentAsset;
        $baseTitle = $source?->title ?? $generatedAsset->title ?? 'Untitled content';
        $type = Str::of($generatedAsset->type)->replace('_', ' ')->headline();

        return [
            'title' => "{$type}: {$baseTitle}",
            'body' => implode("\n\n", [
                "Static Argusly generation output for {$baseTitle}.",
                'This placeholder prepares the content generation flow without calling a real AI provider.',
                'Future connector work can replace this fake output with provider-backed generation, scoring and publishing handoff.',
            ]),
            'provider' => $generatedAsset->provider,
            'model' => $generatedAsset->model,
            'fake' => true,
        ];
    }
}
