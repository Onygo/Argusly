<?php

namespace App\Services\Content;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Services\Translation\TranslationPromptBuilder;
use App\Support\DutchTextCasingNormalizer;
use App\Support\TitleSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ContentDeclaredLocaleTranslationRepairService
{
    public function __construct(
        private readonly LlmManager $llmManager,
        private readonly TranslationPromptBuilder $promptBuilder,
    ) {}

    /**
     * @return array{success:bool,content_id:string,draft_id:string,version_id:string,revision_id:string,title:string,input_tokens:int,output_tokens:int,total_tokens:int,request_id:?string}
     */
    public function translate(Content $content, SupportedLanguage $sourceLanguage, bool $updateSlug = true, ?string $modelOverride = null): array
    {
        $content->loadMissing(['currentVersion', 'brief', 'drafts', 'seo']);

        $targetLanguage = SupportedLanguage::fromStringOrDefault($content->localeCode());
        if ($sourceLanguage === $targetLanguage) {
            throw new RuntimeException('Declared locale and detected source language are the same; no in-place translation is needed.');
        }

        $sourceDraft = $this->sourceDraftForContent($content, $sourceLanguage);
        $result = $this->translateDraftPayload($sourceDraft, $sourceLanguage, $targetLanguage, $modelOverride);

        return DB::transaction(function () use ($content, $sourceDraft, $sourceLanguage, $targetLanguage, $result, $updateSlug): array {
            $lockedContent = Content::query()
                ->with(['currentVersion', 'brief', 'drafts', 'seo'])
                ->lockForUpdate()
                ->findOrFail($content->id);

            $draft = $this->persistDraft($lockedContent, $sourceDraft, $targetLanguage, $result);
            $brief = $this->persistBrief($lockedContent, $draft, $targetLanguage, $result);
            if ((string) $draft->brief_id !== (string) $brief->id) {
                $draft->forceFill(['brief_id' => (string) $brief->id])->saveQuietly();
            }

            $revision = $this->createRevision($lockedContent, $draft, $sourceLanguage, $targetLanguage, $result);
            $version = $this->createVersion($lockedContent, $draft, $sourceLanguage, $targetLanguage, $result);

            $seo = $this->normalizedSeo($result, $draft->title);
            $contentUpdates = [
                'current_revision_id' => (string) $revision->id,
                'current_version_id' => (string) $version->id,
                'title' => $draft->title,
                'language' => $targetLanguage->value,
                'seo_title' => $seo['seo_title'],
                'seo_meta_description' => $seo['seo_meta_description'],
                'seo_h1' => $seo['seo_h1'],
                'seo_og_title' => $seo['seo_og_title'],
                'seo_og_description' => $seo['seo_og_description'],
                'seo_twitter_title' => $seo['seo_twitter_title'],
                'seo_twitter_description' => $seo['seo_twitter_description'],
                'primary_keyword' => $seo['primary_keyword'] ?: $lockedContent->primary_keyword,
                'public_blog_excerpt' => $seo['seo_meta_description'] ?: $lockedContent->public_blog_excerpt,
                'locale_repair_meta' => array_replace_recursive((array) ($lockedContent->locale_repair_meta ?? []), [
                    'last_translate_to_declared_locale' => [
                        'source_locale' => $sourceLanguage->value,
                        'target_locale' => $targetLanguage->value,
                        'draft_id' => (string) $draft->id,
                        'version_id' => (string) $version->id,
                        'revision_id' => (string) $revision->id,
                        'model_used' => (string) ($result['model_used'] ?? ''),
                        'request_id' => (string) ($result['request_id'] ?? ''),
                        'translated_at' => now()->toIso8601String(),
                    ],
                ]),
            ];

            $slug = $this->localizedSlug($result, (string) $draft->title);
            if ($updateSlug && $slug !== '') {
                $contentUpdates['publish_url_key'] = $slug;
            }

            $lockedContent->forceFill($contentUpdates)->save();

            ContentSeo::query()->updateOrCreate(
                ['content_id' => (string) $lockedContent->id],
                [
                    'meta_title' => $seo['seo_title'] ?: $draft->title,
                    'meta_description' => $seo['seo_meta_description'] ?: null,
                    'primary_keyword' => $seo['primary_keyword'] ?: null,
                    'secondary_keywords' => $seo['secondary_keywords'],
                    'robots_index' => $lockedContent->robots_index,
                    'robots_follow' => $lockedContent->robots_follow,
                    'schema_type' => $lockedContent->schema_type,
                ],
            );

            Log::info('content.locale_repair.translated_to_declared_locale', [
                'content_id' => (string) $lockedContent->id,
                'source_locale' => $sourceLanguage->value,
                'target_locale' => $targetLanguage->value,
                'draft_id' => (string) $draft->id,
                'version_id' => (string) $version->id,
                'revision_id' => (string) $revision->id,
                'update_slug' => $updateSlug,
            ]);

            return [
                'success' => true,
                'content_id' => (string) $lockedContent->id,
                'draft_id' => (string) $draft->id,
                'version_id' => (string) $version->id,
                'revision_id' => (string) $revision->id,
                'title' => (string) $draft->title,
                'input_tokens' => (int) ($result['input_tokens'] ?? 0),
                'output_tokens' => (int) ($result['output_tokens'] ?? 0),
                'total_tokens' => (int) ($result['total_tokens'] ?? 0),
                'request_id' => $result['request_id'] ?? null,
            ];
        });
    }

    private function sourceDraftForContent(Content $content, SupportedLanguage $sourceLanguage): Draft
    {
        $draft = $content->drafts
            ->sortByDesc(fn (Draft $draft): int => $draft->updated_at?->timestamp ?? 0)
            ->first();

        $body = trim((string) ($content->currentVersion?->body ?: $draft?->content_html ?: ''));
        if ($body === '') {
            throw new RuntimeException('Content has no draft or current version body to translate.');
        }

        if ($draft instanceof Draft) {
            $draft->forceFill([
                'title' => (string) $content->title,
                'content_html' => $body,
                'seo_title' => $content->seo_title ?: $draft->seo_title,
                'seo_meta_description' => $content->seo_meta_description ?: $draft->seo_meta_description,
                'seo_h1' => $content->seo_h1 ?: $draft->seo_h1,
                'seo_og_title' => $content->seo_og_title ?: $draft->seo_og_title,
                'seo_og_description' => $content->seo_og_description ?: $draft->seo_og_description,
                'seo_twitter_title' => $content->seo_twitter_title ?: $draft->seo_twitter_title,
                'seo_twitter_description' => $content->seo_twitter_description ?: $draft->seo_twitter_description,
            ]);

            return $draft;
        }

        return new Draft([
            'content_id' => (string) $content->id,
            'client_site_id' => $content->client_site_id,
            'content_destination_id' => $content->content_destination_id,
            'status' => 'ready',
            'title' => (string) $content->title,
            'language' => $sourceLanguage->value,
            'draft_type' => DraftType::ORIGINAL->value,
            'output_type' => 'kb_article',
            'content_html' => $body,
            'seo_title' => $content->seo_title,
            'seo_meta_description' => $content->seo_meta_description,
            'seo_h1' => $content->seo_h1,
            'seo_og_title' => $content->seo_og_title,
            'seo_og_description' => $content->seo_og_description,
            'seo_twitter_title' => $content->seo_twitter_title,
            'seo_twitter_description' => $content->seo_twitter_description,
            'robots_index' => $content->robots_index,
            'robots_follow' => $content->robots_follow,
            'schema_type' => $content->schema_type,
            'meta' => [
                'language' => $sourceLanguage->value,
                'repair_source' => 'content_current_version',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function translateDraftPayload(Draft $sourceDraft, SupportedLanguage $sourceLanguage, SupportedLanguage $targetLanguage, ?string $modelOverride): array
    {
        $model = $modelOverride ?: (string) config('translation.default_model', 'gpt-4.1-mini');
        $request = new LlmRequest(
            messages: [
                new LlmMessage('system', $this->promptBuilder->buildSystemPrompt($targetLanguage)),
                new LlmMessage('user', $this->promptBuilder->buildUserPrompt($sourceDraft, $sourceLanguage, $targetLanguage)),
            ],
            model: $model,
            temperature: 0.3,
            maxTokens: $this->promptBuilder->getMaxOutputTokens($sourceDraft),
            responseFormat: 'json',
            metadata: [
                'feature' => 'content_declared_locale_translation_repair',
                'content_id' => (string) $sourceDraft->content_id,
                'source_language' => $sourceLanguage->value,
                'target_language' => $targetLanguage->value,
                'prompt_version' => TranslationPromptBuilder::PROMPT_VERSION,
            ],
        );

        $response = $this->llmManager->generateJson($request, $this->promptBuilder->responseSchema());
        if (! is_array($response->json)) {
            throw new RuntimeException('Translation repair response was not valid JSON.');
        }

        $result = $response->json;
        $result['model_used'] = $response->modelUsed;
        $result['input_tokens'] = $response->usage->inputTokens;
        $result['output_tokens'] = $response->usage->outputTokens;
        $result['total_tokens'] = $response->usage->totalTokens;
        $result['request_id'] = $response->requestId;

        if ($targetLanguage === SupportedLanguage::NL) {
            $result['title'] = DutchTextCasingNormalizer::normalizeText((string) ($result['title'] ?? ''));
            foreach (['seo_title', 'seo_meta_description', 'seo_h1', 'seo_og_title', 'seo_og_description', 'seo_twitter_title', 'seo_twitter_description'] as $field) {
                if (isset($result['seo'][$field]) && is_string($result['seo'][$field])) {
                    $result['seo'][$field] = DutchTextCasingNormalizer::normalizeText($result['seo'][$field]);
                }
            }
        }

        if (trim((string) ($result['content_html'] ?? '')) === '') {
            throw new RuntimeException('Translation repair returned empty content_html.');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function persistDraft(Content $content, Draft $sourceDraft, SupportedLanguage $targetLanguage, array $result): Draft
    {
        $title = TitleSanitizer::normalizeWithMetadata((string) ($result['title'] ?? $content->title), fallback: (string) $content->title)['title'];
        $seo = $this->normalizedSeo($result, $title);
        $draft = $sourceDraft->exists ? $sourceDraft : new Draft();
        $draftType = $draft->exists
            ? (string) ($draft->getRawOriginal('draft_type') ?: DraftType::ORIGINAL->value)
            : DraftType::ORIGINAL->value;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta = array_replace_recursive($meta, [
            'language' => $targetLanguage->value,
            'locale_repair' => [
                'repair_type' => 'translate_to_declared_locale',
                'target_locale' => $targetLanguage->value,
                'translated_at' => now()->toIso8601String(),
                'model_used' => (string) ($result['model_used'] ?? ''),
                'request_id' => (string) ($result['request_id'] ?? ''),
            ],
        ]);

        $draft->forceFill([
            'brief_id' => $draft->brief_id ?: $content->brief?->id,
            'content_id' => (string) $content->id,
            'client_site_id' => $content->client_site_id,
            'content_destination_id' => $content->content_destination_id,
            'status' => 'ready',
            'title' => $title,
            'language' => $targetLanguage->value,
            'draft_type' => $draftType,
            'output_type' => $draft->output_type ?: 'kb_article',
            'content_html' => (string) $result['content_html'],
            'seo_title' => $seo['seo_title'],
            'seo_meta_description' => $seo['seo_meta_description'],
            'seo_h1' => $seo['seo_h1'],
            'seo_og_title' => $seo['seo_og_title'],
            'seo_og_description' => $seo['seo_og_description'],
            'seo_twitter_title' => $seo['seo_twitter_title'],
            'seo_twitter_description' => $seo['seo_twitter_description'],
            'robots_index' => $content->robots_index,
            'robots_follow' => $content->robots_follow,
            'schema_type' => $content->schema_type,
            'model_used' => (string) ($result['model_used'] ?? ''),
            'last_error' => null,
            'delivery_status' => 'pending',
            'delivery_last_error' => null,
            'meta' => $meta,
        ])->saveQuietly();

        return $draft->fresh(['brief']) ?? $draft;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function persistBrief(Content $content, Draft $draft, SupportedLanguage $targetLanguage, array $result): Brief
    {
        $brief = $content->brief ?: $draft->brief ?: new Brief();
        $seo = $this->normalizedSeo($result, (string) $draft->title);

        $brief->forceFill([
            'client_site_id' => $content->client_site_id,
            'content_destination_id' => $content->content_destination_id,
            'content_id' => (string) $content->id,
            'status' => $brief->status ?: 'done',
            'source' => $brief->source ?: 'locale_repair',
            'progress' => $brief->progress ?: 1,
            'title' => (string) $draft->title,
            'language' => $targetLanguage->value,
            'content_type' => $brief->content_type ?: 'blog',
            'output_type' => $brief->output_type ?: ($draft->output_type ?: 'kb_article'),
            'primary_keyword' => $seo['primary_keyword'] ?: $brief->primary_keyword,
            'secondary_keywords' => $seo['secondary_keywords'] ?: $brief->secondary_keywords,
        ])->saveQuietly();

        return $brief;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function createRevision(Content $content, Draft $draft, SupportedLanguage $sourceLanguage, SupportedLanguage $targetLanguage, array $result): ContentRevision
    {
        $latestNumber = (int) ContentRevision::query()
            ->where('content_id', (string) $content->id)
            ->max('revision_number');

        ContentRevision::query()
            ->where('content_id', (string) $content->id)
            ->update(['is_active' => false]);

        return ContentRevision::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'draft_id' => (string) $draft->id,
            'revision_number' => $latestNumber + 1,
            'label' => 'R' . ($latestNumber + 1),
            'content_html' => (string) $result['content_html'],
            'meta' => [
                'repair_type' => 'translate_to_declared_locale',
                'source_locale' => $sourceLanguage->value,
                'target_locale' => $targetLanguage->value,
                'model_used' => (string) ($result['model_used'] ?? ''),
                'request_id' => (string) ($result['request_id'] ?? ''),
            ],
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function createVersion(Content $content, Draft $draft, SupportedLanguage $sourceLanguage, SupportedLanguage $targetLanguage, array $result): ContentVersion
    {
        return ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'type' => ContentVersion::TYPE_REVISION,
            'parent_version_id' => $content->current_version_id,
            'body' => (string) $result['content_html'],
            'meta' => [
                'draft_id' => (string) $draft->id,
                'repair_type' => 'translate_to_declared_locale',
                'source_locale' => $sourceLanguage->value,
                'target_locale' => $targetLanguage->value,
                'model' => (string) ($result['model_used'] ?? ''),
                'request_id' => (string) ($result['request_id'] ?? ''),
                'input_tokens' => (int) ($result['input_tokens'] ?? 0),
                'output_tokens' => (int) ($result['output_tokens'] ?? 0),
                'total_tokens' => (int) ($result['total_tokens'] ?? 0),
            ],
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);
    }

    /**
     * @param array<string,mixed> $result
     * @return array{seo_title:string,seo_meta_description:string,seo_h1:string,seo_og_title:string,seo_og_description:string,seo_twitter_title:string,seo_twitter_description:string,primary_keyword:string,secondary_keywords:array<int,string>}
     */
    private function normalizedSeo(array $result, string $fallbackTitle): array
    {
        $seo = is_array($result['seo'] ?? null) ? $result['seo'] : [];

        return [
            'seo_title' => trim((string) ($seo['seo_title'] ?? $fallbackTitle)) ?: $fallbackTitle,
            'seo_meta_description' => trim((string) ($seo['seo_meta_description'] ?? '')),
            'seo_h1' => trim((string) ($seo['seo_h1'] ?? $fallbackTitle)) ?: $fallbackTitle,
            'seo_og_title' => trim((string) ($seo['seo_og_title'] ?? $seo['seo_title'] ?? $fallbackTitle)) ?: $fallbackTitle,
            'seo_og_description' => trim((string) ($seo['seo_og_description'] ?? $seo['seo_meta_description'] ?? '')),
            'seo_twitter_title' => trim((string) ($seo['seo_twitter_title'] ?? $seo['seo_title'] ?? $fallbackTitle)) ?: $fallbackTitle,
            'seo_twitter_description' => trim((string) ($seo['seo_twitter_description'] ?? $seo['seo_meta_description'] ?? '')),
            'primary_keyword' => trim((string) ($seo['suggested_primary_keyword'] ?? '')),
            'secondary_keywords' => collect((array) ($seo['secondary_keywords'] ?? []))
                ->map(fn (mixed $keyword): string => trim((string) $keyword))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    private function localizedSlug(array $result, string $fallbackTitle): string
    {
        $slug = Str::slug((string) data_get($result, 'seo.slug', ''));

        return $slug !== '' ? $slug : Str::slug($fallbackTitle);
    }
}
