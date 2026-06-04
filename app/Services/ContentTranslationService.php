<?php

namespace App\Services;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Models\ContentAsset;
use App\Models\ContentTranslation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ContentTranslationService
{
    public function __construct(
        private readonly ContentLanguageService $languages,
        private readonly CreditService $credits,
        private readonly LlmResolver $resolver,
        private readonly LlmClientInterface $llm,
    ) {}

    /**
     * @param  array<int, string>  $targetLanguages
     * @return Collection<int, ContentTranslation>
     */
    public function createTranslations(ContentAsset $source, User $user, array $targetLanguages): Collection
    {
        $targets = collect($targetLanguages)
            ->filter(fn (mixed $code) => is_string($code) && $code !== '')
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            throw new InvalidArgumentException('Select at least one target language.');
        }

        return DB::transaction(function () use ($source, $user, $targets): Collection {
            return $targets->map(fn (string $targetLanguage) => $this->createTranslation($source, $user, $targetLanguage));
        });
    }

    public function createTranslation(ContentAsset $source, User $user, string $targetLanguage): ContentTranslation
    {
        $brand = $source->brand;
        $this->languages->validateForBrand($targetLanguage, $brand);

        if ($targetLanguage === $source->language) {
            throw new InvalidArgumentException('Translation target cannot match the source language.');
        }

        $duplicate = ContentTranslation::query()
            ->where('source_content_asset_id', $source->id)
            ->where('target_language', $targetLanguage)
            ->whereNotIn('status', ['failed', 'archived'])
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException("An active {$targetLanguage} translation already exists for this asset.");
        }

        $targetLocale = $this->languages->localeForLanguage($targetLanguage);
        $llm = $this->resolver->resolve($source->account, $brand);
        $response = $this->llm->generate(new LlmRequest(
            provider: $llm['provider']['provider'],
            model: $llm['model']['model'],
            messages: [
                [
                    'role' => 'user',
                    'content' => "Translate this content from {$source->language} to {$targetLanguage}:\n\n{$source->body}",
                ],
            ],
            systemPrompt: 'You are Argusly translation runtime. Preserve meaning, structure and brand terminology.',
            temperature: is_numeric($llm['temperature']) ? (float) $llm['temperature'] : null,
            maxTokens: is_numeric($llm['max_tokens']) ? (int) $llm['max_tokens'] : null,
            metadata: [
                'purpose' => 'translation',
                'account_id' => $source->account_id,
                'brand_id' => $source->brand_id,
                'user_id' => $user->id,
                'fallback_provider' => $llm['fallback_provider']['provider'] ?? null,
                'fallback_model' => $llm['fallback_model']['model'] ?? null,
                'source_content_asset_id' => $source->id,
                'source_language' => $source->language,
                'target_language' => $targetLanguage,
                'fake_content' => $source->body ?? '',
            ],
        ));

        $translated = ContentAsset::query()->create([
            'account_id' => $source->account_id,
            'brand_id' => $source->brand_id,
            'property_id' => $source->property_id,
            'channel_id' => $source->channel_id,
            'type' => $source->type,
            'status' => 'draft',
            'title' => $this->translatedTitle($source, $targetLanguage),
            'slug' => $this->translatedSlug($source, $targetLanguage),
            'language' => $targetLanguage,
            'locale' => $targetLocale,
            'source' => 'translation',
            'source_url' => $source->canonical_url ?? $source->source_url,
            'canonical_url' => null,
            'excerpt' => $source->excerpt,
            'body' => $response->content,
            'metadata' => [
                ...($source->metadata ?? []),
                'translation' => [
                    'source_content_asset_id' => $source->id,
                    'source_language' => $source->language,
                    'target_language' => $targetLanguage,
                    'placeholder' => true,
                ],
            ],
            'seo_metadata' => $source->seo_metadata,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $translation = ContentTranslation::query()->create([
            'account_id' => $source->account_id,
            'brand_id' => $source->brand_id,
            'source_content_asset_id' => $source->id,
            'translated_content_asset_id' => $translated->id,
            'source_language' => $source->language,
            'source_locale' => $source->locale,
            'target_language' => $targetLanguage,
            'target_locale' => $targetLocale,
            'status' => 'draft',
            'provider' => $response->provider,
            'model' => $response->model,
            'input_payload' => [
                'source_content_asset_id' => $source->id,
                'title' => $source->title,
                'language' => $source->language,
                'locale' => $source->locale,
            ],
            'output_payload' => [
                'translated_content_asset_id' => $translated->id,
                'target_language' => $targetLanguage,
                'target_locale' => $targetLocale,
                'draft_created' => true,
                'llm_response' => $response->toArray(),
            ],
            'requested_by' => $user->id,
        ]);

        app(DomainEventService::class)->recordForSubject('ContentTranslationRequested', $translation, $user, [
            'source_content_asset_id' => $source->id,
            'translated_content_asset_id' => $translated->id,
            'source_language' => $source->language,
            'target_language' => $targetLanguage,
            'cost_credits' => $this->credits->cost('content_translation'),
        ], $translation->created_at);

        app(DomainEventService::class)->recordForSubject('ContentAssetTranslationCreated', $translated, $user, [
            'content_translation_id' => $translation->id,
            'source_content_asset_id' => $source->id,
            'source_language' => $source->language,
            'target_language' => $targetLanguage,
        ], $translated->created_at);

        return $translation->refresh();
    }

    private function translatedTitle(ContentAsset $source, string $targetLanguage): string
    {
        return Str::limit($source->title, 240, '').' ['.strtoupper($targetLanguage).']';
    }

    private function translatedSlug(ContentAsset $source, string $targetLanguage): string
    {
        return Str::slug($source->slug ?: $source->title).'-'.$targetLanguage;
    }
}
