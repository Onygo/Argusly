<?php

namespace App\Services\ContentAutomation;

use App\Actions\Agents\RunInternalLinkingForContent;
use App\Enums\ContentAutomationMode;
use App\Enums\ContentAutomationPublicationMode;
use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Draft;
use App\Models\User;
use App\Services\Brief\BriefDefaultBuilder;
use App\Services\Content\ContentDeduplicationService;
use App\Services\Briefs\BriefPromptBuilder;
use App\Services\Content\ContentTranslationCoordinator;
use App\Services\Content\LocalePublishingSyncService;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use App\Services\Translation\TranslationService;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\KeywordSanitizer;
use App\Support\TitleSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContentAutomationArticleService
{
    public function __construct(
        private readonly BriefPromptBuilder $promptBuilder,
        private readonly BriefDefaultBuilder $briefDefaultBuilder,
        private readonly ContentDeduplicationService $contentDeduplicationService,
        private readonly RunInternalLinkingForContent $runInternalLinkingForContent,
        private readonly ContentTranslationCoordinator $translationCoordinator,
        private readonly ContentPublicationService $publicationService,
        private readonly LaravelConnectorPublishingService $laravelPublishingService,
        private readonly LaravelConnectorDestinationResolver $laravelDestinationResolver,
        private readonly AutomationLocaleResolver $localeResolver,
        private readonly LocalePublishingSyncService $localePublishingSyncService,
        private readonly TranslationService $translationService,
    ) {}

    /**
     * @param  array<string, mixed>  $articlePlan
     * @return array<string, mixed>
     */
    public function execute(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        array $articlePlan,
        User $actor,
        ?ContentAutomationRunItem $item = null,
    ): array {
        $stage = 'site_resolution';
        $site = $automation->clientSite ?: $this->resolveFallbackSite($automation);
        if (! $site) {
            throw new \RuntimeException('Automation has no usable site context.');
        }

        Log::info('content_automation.site_resolved', $this->logContext($automation, $run, $item, $articlePlan, [
            'site_id' => (string) $site->id,
            'locale' => (string) ($articlePlan['target_locale'] ?? $automation->sourceLocale()),
        ]));

        try {
            $stage = 'persistence';
            [$content, $brief, $draft] = $this->createStructures($automation, $run, $articlePlan, $site, $actor, $item);

            Log::info('content_automation.content_persisted', $this->logContext($automation, $run, $item, $articlePlan, [
                'created_content_id' => (string) $content->id,
                'brief_id' => (string) $brief->id,
                'draft_id' => (string) $draft->id,
                'normalized_payload' => [
                    'source' => $content->getRawOriginal('source'),
                    'title' => (string) $content->title,
                    'title_length' => mb_strlen((string) $content->title),
                    'external_key' => (string) ($content->external_key ?? ''),
                    'language' => $content->localeCode(),
                ],
            ]));

            $stage = 'generation';
            GenerateDraftJob::dispatchSync((string) $draft->id);
        } catch (\Throwable $exception) {
            Log::error('content_automation.item_failed', $this->logContext($automation, $run, $item, $articlePlan, [
                'failure_stage' => $stage,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception' => $exception,
            ]));

            throw $exception;
        }

        $content = $content->fresh(['drafts', 'clientSite', 'contentDestination']) ?? $content;
        $draft = Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->firstOrFail();

        $result = [
            'content_id' => (string) $content->id,
            'brief_id' => (string) $brief->id,
            'draft_id' => (string) $draft->id,
            'title' => (string) $content->title,
            'status' => (string) $draft->status,
            'published_content_ids' => [],
            'queued_translation_locales' => [],
            'publication' => null,
        ];

        if ($automation->include_internal_linking) {
            try {
                $linkingRun = $this->runInternalLinkingForContent->execute($content, $actor);
                $result['internal_linking_run_id'] = (string) $linkingRun->id;
            } catch (\Throwable $exception) {
                Log::warning('content_automation.internal_linking_failed', [
                    'automation_id' => (string) $automation->id,
                    'run_id' => (string) $run->id,
                    'content_id' => (string) $content->id,
                    'message' => $exception->getMessage(),
                ]);

                $result['internal_linking_error'] = $exception->getMessage();
            }
        }

        if ($this->localeResolver->shouldTranslate($automation)) {
            $translationLocales = collect($this->localeResolver->targetLocales($automation));

            foreach ($translationLocales as $locale) {
                try {
                    $queued = $this->translationCoordinator->queue($content, $locale, (string) $actor->id);
                    $result['queued_translation_locales'][] = (string) $queued['target_language']->value;
                    $result['translation_queue_results'][] = [
                        'locale' => (string) $queued['target_language']->value,
                        'mode' => (string) ($queued['mode'] ?? 'translate'),
                        'existing_variant_id' => $queued['existing_variant']?->id ? (string) $queued['existing_variant']->id : null,
                        'source_content_id' => (string) $content->id,
                        'family_id' => $content->localizationRootId(),
                        'translation_request_id' => $queued['translation_request']?->id ? (string) $queued['translation_request']->id : null,
                        'translation_request_status' => (string) ($queued['translation_request']?->status ?? ''),
                    ];
                } catch (\Throwable $exception) {
                    $existingVariant = $this->existingTranslationVariantForQueueFailure($draft, (string) $locale, $exception);

                    if ($existingVariant instanceof Content) {
                        Log::info('content_automation.translation_existing_variant_reused', [
                            'automation_id' => (string) $automation->id,
                            'run_id' => (string) $run->id,
                            'content_id' => (string) $content->id,
                            'target_locale' => (string) $locale,
                            'existing_variant_id' => (string) $existingVariant->id,
                            'message' => $exception->getMessage(),
                        ]);

                        $result['queued_translation_locales'][] = (string) $existingVariant->localeCode();
                        $result['translation_queue_results'][] = [
                            'locale' => (string) $existingVariant->localeCode(),
                            'mode' => 'existing_reused',
                            'existing_variant_id' => (string) $existingVariant->id,
                            'source_content_id' => (string) $content->id,
                            'family_id' => $content->localizationRootId(),
                            'translation_request_id' => null,
                            'translation_request_status' => 'existing_reused',
                            'reused_after_queue_failure' => true,
                        ];

                        continue;
                    }

                    Log::warning('content_automation.translation_queue_failed', [
                        'automation_id' => (string) $automation->id,
                        'run_id' => (string) $run->id,
                        'content_id' => (string) $content->id,
                        'target_locale' => (string) $locale,
                        'message' => $exception->getMessage(),
                    ]);

                    $result['translation_errors'][] = [
                        'locale' => (string) $locale,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        if ($automation->publication_mode === ContentAutomationPublicationMode::AUTO_PUBLISH) {
            Log::info('content_automation.publish_started', $this->logContext($automation, $run, $item, $articlePlan, [
                'created_content_id' => (string) $content->id,
                'draft_id' => (string) $draft->id,
            ]));

            try {
                $publication = $this->publishContent($content, $draft);
            } catch (\Throwable $exception) {
                Log::error('content_automation.item_failed', $this->logContext($automation, $run, $item, $articlePlan, [
                    'failure_stage' => 'publish',
                    'created_content_id' => (string) $content->id,
                    'draft_id' => (string) $draft->id,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]));

                if ($item instanceof ContentAutomationRunItem) {
                    $item->forceFill([
                        'status' => ContentAutomationRunItem::STATUS_PARTIAL,
                        'failure_stage' => 'publish',
                        'last_error_code' => 'publish_exception',
                        'last_error_message' => $exception->getMessage(),
                    ])->save();
                }

                $result['item_status'] = ContentAutomationRunItem::STATUS_PARTIAL;
                $result['failure_stage'] = 'publish';
                $result['last_error_code'] = 'publish_exception';
                $result['last_error_message'] = $exception->getMessage();

                return $result;
            }

            $result['publication'] = $publication;

            if ((bool) ($publication['success'] ?? false)) {
                $result['published_content_ids'][] = (string) $content->id;

                if ($automation->usesSyncedFamilyPublishing() && $automation->autoPublishTranslationsWithSource()) {
                    $this->localePublishingSyncService->syncSourceImmediatePublish(
                        $content->fresh(['clientSite', 'contentDestination']) ?? $content
                    );
                }

                Log::info('content_automation.publish_succeeded', $this->logContext($automation, $run, $item, $articlePlan, [
                    'created_content_id' => (string) $content->id,
                    'draft_id' => (string) $draft->id,
                    'publication_message' => (string) ($publication['message'] ?? ''),
                ]));
            } else {
                $result['item_status'] = ContentAutomationRunItem::STATUS_PARTIAL;
                $result['failure_stage'] = 'publish';
                $result['last_error_code'] = 'publish_skipped';
                $result['last_error_message'] = (string) ($publication['message'] ?? 'Publication did not queue or complete.');

                Log::warning('content_automation.item_failed', $this->logContext($automation, $run, $item, $articlePlan, [
                    'failure_stage' => 'publish',
                    'created_content_id' => (string) $content->id,
                    'draft_id' => (string) $draft->id,
                    'exception_class' => null,
                    'exception_message' => $result['last_error_message'],
                ]));
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $articlePlan
     * @return array{0:Content,1:Brief,2:Draft}
     */
    private function createStructures(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        array $articlePlan,
        ClientSite $site,
        User $actor,
        ?ContentAutomationRunItem $item = null,
    ): array {
        $workspace = $automation->workspace;
        if (! $workspace) {
            throw new \RuntimeException('Automation workspace could not be resolved.');
        }

        $titleResult = TitleSanitizer::normalizeWithMetadata(
            $articlePlan['title'] ?? 'Untitled automation article',
            fallback: 'Untitled automation article'
        );
        $title = $titleResult['title'];
        if ($titleResult['was_shortened']) {
            Log::notice('content_automation.title_shortened', $this->logContext($automation, $run, $item, $articlePlan, [
                'original_length' => $titleResult['original_length'],
                'persisted_length' => $titleResult['persisted_length'],
                'max_length' => $titleResult['max_length'],
            ]));
        }

        // Derive primary keyword from first related keyword, or fall back to sanitized title
        $rawKeyword = ($articlePlan['related_keywords'][0] ?? null) ?: $title;
        $keywordResult = KeywordSanitizer::normalizeWithMetadata($rawKeyword, fallback: $title);
        $primaryKeyword = $keywordResult['keyword'];

        if ($keywordResult['was_sanitized']) {
            Log::notice('content_automation.keyword_sanitized', $this->logContext($automation, $run, $item, $articlePlan, [
                'original_length' => $keywordResult['original_length'],
                'persisted_length' => $keywordResult['persisted_length'],
                'was_truncated' => $keywordResult['was_truncated'],
                'was_rejected' => $keywordResult['was_rejected'],
                'rejection_reason' => $keywordResult['rejection_reason'],
            ]));
        }
        $notes = $this->composeNotes($automation, $articlePlan);
        $language = (string) ($articlePlan['target_locale'] ?? $automation->sourceLocale());
        $preferredLength = trim((string) data_get($automation->settings, 'preferred_length', 'medium')) ?: 'medium';
        $audience = $this->resolveAudience($automation);
        $tone = $this->resolveTone($automation);

        return DB::transaction(function () use (
            $automation,
            $run,
            $site,
            $workspace,
            $actor,
            $title,
            $primaryKeyword,
            $language,
            $preferredLength,
            $audience,
            $tone,
            $notes,
            $articlePlan,
            $titleResult,
            $item
        ): array {
            $isChainMode = $automation->mode instanceof ContentAutomationMode
                && in_array($automation->mode, [ContentAutomationMode::CHAIN, ContentAutomationMode::PILLAR_PLUS_CLUSTER], true);
            $originType = $isChainMode
                ? ContentOriginType::CHAINED_VIA_AUTOMATION
                : ContentOriginType::AUTOMATION;

            $stableItemKey = (string) ($item?->id ?: ($articlePlan['stable_key'] ?? $articlePlan['sequence'] ?? 1));
            $externalKey = 'automation-' . $automation->id . '-' . $stableItemKey;

            $contentPayload = ContentPersistencePayloadNormalizer::normalize([
                'id' => (string) Str::uuid(),
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $site->id,
                'content_destination_id' => $automation->content_destination_id,
                'title' => $title,
                'language' => $language,
                'translation_source_locale' => null,
                'is_source_locale' => true,
                'primary_keyword' => $primaryKeyword,
                'type' => 'article',
                'status' => 'brief',
                'source' => ContentSource::AUTOMATION->value,
                'origin_type' => $originType->value,
                'automation_id' => (string) $automation->id,
                'automation_run_id' => (string) $run->id,
                'external_key' => $externalKey,
                'publish_status' => 'draft',
                'delivery_status' => 'pending',
                'generation_mode' => 'balanced',
                'brand_voice_id' => $automation->use_brand_voice_id,
                'buyer_persona_id' => $automation->use_buyer_persona_id,
                'team_member_id' => $automation->use_team_persona_id,
                'preferred_length' => $preferredLength,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $content = $this->contentDeduplicationService->createOrReuse($contentPayload, [
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $site->id,
                'automation_id' => (string) $automation->id,
                'automation_run_id' => (string) $run->id,
                'external_key' => $externalKey,
                'language' => $language,
                'type' => 'article',
                'primary_keyword' => $primaryKeyword,
                'title' => $title,
            ]);

            $content = $this->syncSourceContentFamilySettings($content, $automation, $actor->id);

            $brief = Brief::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            if (! $brief) {
                $brief = Brief::query()->create(ContentPersistencePayloadNormalizer::normalizeBrief([
                'id' => (string) Str::uuid(),
                'client_site_id' => (string) $site->id,
                'content_destination_id' => $automation->content_destination_id,
                'created_by_user_id' => $actor->id,
                'content_id' => (string) $content->id,
                'status' => 'done',
                'source' => ContentSource::AUTOMATION->value,
                'progress' => 1,
                'title' => $title,
                'language' => $language,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
                'intent' => (string) ($articlePlan['search_intent'] ?? 'informational'),
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => array_values((array) ($articlePlan['related_keywords'] ?? [])),
                'audience' => $audience,
                'target_audience' => $audience,
                'funnel_stage' => $this->nullableString($articlePlan['funnel_stage'] ?? $automation->funnel_stage),
                'search_intent' => (string) ($articlePlan['search_intent'] ?? 'informational'),
                'notes' => $notes,
                'tone_of_voice' => $tone,
                'unique_angle' => $this->nullableString($articlePlan['angle'] ?? null),
                'client_refs' => [
                    'client_type' => 'content_automation',
                    'site_url' => (string) ($site->base_url ?: $site->site_url),
                    'brand_voice_id' => $automation->use_brand_voice_id,
                    'buyer_persona_id' => $automation->use_buyer_persona_id,
                    'team_member_id' => $automation->use_team_persona_id,
                    'preferred_length' => $preferredLength,
                    'content_automation' => [
                        'automation_id' => (string) $automation->id,
                        'run_id' => (string) $run->id,
                        'sequence' => (int) ($articlePlan['sequence'] ?? 1),
                        'chain_title' => (string) data_get($articlePlan, 'chain_title', ''),
                        'pillar_role' => (string) ($articlePlan['pillar_role'] ?? 'supporting'),
                        'goal' => (string) ($articlePlan['goal'] ?? ''),
                        'internal_link_targets' => array_values((array) ($articlePlan['internal_link_targets'] ?? [])),
                        'original_title' => $titleResult['was_shortened'] ? $titleResult['original_title'] : null,
                        'title_shortened' => $titleResult['was_shortened'],
                    ],
                ],
                ]));
            }

            $draft = Draft::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            if (! $draft) {
                $draft = $this->createDraftFromBrief($brief, $content, $automation, $articlePlan, $item);
            }

            if ($item instanceof ContentAutomationRunItem) {
                $item->forceFill([
                    'content_id' => (string) $content->id,
                    'brief_id' => (string) $brief->id,
                    'draft_id' => (string) $draft->id,
                    'client_site_id' => (string) $site->id,
                    'locale' => $language,
                    'title' => $title,
                    'metadata' => array_merge(is_array($item->metadata) ? $item->metadata : [], [
                        'content_id' => (string) $content->id,
                        'brief_id' => (string) $brief->id,
                    'draft_id' => (string) $draft->id,
                    'original_title' => $titleResult['was_shortened'] ? $titleResult['original_title'] : null,
                    'title_shortened' => $titleResult['was_shortened'],
                    'duplicate_prevented' => (bool) $content->getAttribute('dedupe_was_reused'),
                    'dedupe_fingerprint' => (string) ($content->dedupe_fingerprint ?? ''),
                ]),
            ])->save();
            }

            return [$content, $brief, $draft];
        });
    }

    /**
     * @param  array<string, mixed>  $articlePlan
     */
    private function createDraftFromBrief(
        Brief $brief,
        Content $content,
        ContentAutomation $automation,
        array $articlePlan,
        ?ContentAutomationRunItem $item = null,
    ): Draft {
        $promptMeta = $this->promptBuilder->buildDraftMeta($brief);
        $prompt = $this->promptBuilder->buildPrompt($brief);
        $briefDefaults = $this->briefDefaultBuilder->buildDraftMeta(
            (string) $brief->title,
            (string) ($brief->primary_keyword ?: $brief->title),
            (string) $brief->language,
        );

        // Ensure primary_keyword in draft meta is sanitized
        $draftPrimaryKeyword = KeywordSanitizer::normalize(
            $brief->primary_keyword ?: $briefDefaults['primary_keyword'],
            fallback: (string) $brief->title
        );

        $meta = [
            'language' => (string) $brief->language,
            'intent' => $brief->intent ?: $briefDefaults['intent'],
            'intent_keys' => (array) ($briefDefaults['intent_keys'] ?? []),
            'primary_keyword' => $draftPrimaryKeyword,
            'audience' => $brief->audience ?: $briefDefaults['audience'],
            'audience_tags' => [],
            'brand_voice_id' => $automation->use_brand_voice_id,
            'buyer_persona_id' => $automation->use_buyer_persona_id,
            'team_member_id' => $automation->use_team_persona_id,
            'preferred_length' => (string) data_get($automation->settings, 'preferred_length', 'medium'),
            'notes' => $brief->notes,
            'secondary_keywords' => $brief->secondary_keywords,
            'tone' => $brief->tone_of_voice,
            'funnel_stage' => $brief->funnel_stage ?: $briefDefaults['funnel_stage'],
            'search_intent' => $brief->search_intent ?: $briefDefaults['search_intent'],
            'unique_angle' => $brief->unique_angle,
            'call_to_action' => $brief->call_to_action,
            'structure' => $briefDefaults['structure'],
            'client_refs' => $brief->client_refs ?? [],
            'source' => (string) ($brief->source ?: ContentSource::AUTOMATION->value),
            'brief_prompt' => $this->promptBuilder->buildPrompt($brief),
            'content_automation' => [
                'automation_id' => (string) $automation->id,
                'sequence' => (int) ($articlePlan['sequence'] ?? 1),
                'related_keywords' => array_values((array) ($articlePlan['related_keywords'] ?? [])),
                'internal_link_targets' => array_values((array) ($articlePlan['internal_link_targets'] ?? [])),
            ],
        ];
        $meta = array_replace_recursive($meta, $promptMeta);
        $meta['brief_prompt'] = $prompt;

        if ($item instanceof ContentAutomationRunItem) {
            $item->forceFill([
                'prompt_hash' => hash('sha256', $prompt),
                'provider' => trim((string) data_get($meta, 'provider', config('argusly.ai.provider', ''))),
                'model' => trim((string) data_get($meta, 'model', config('argusly.ai.model', ''))),
            ])->save();
        }

        return Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $brief->id,
            'content_id' => (string) $content->id,
            'client_site_id' => (string) $brief->client_site_id,
            'content_destination_id' => $brief->content_destination_id,
            'status' => 'queued',
            'attempts' => 0,
            'title' => (string) $brief->title,
            'seo_title' => (string) $brief->title,
            'seo_h1' => (string) $brief->title,
            'output_type' => (string) ($brief->output_type ?: 'kb_article'),
            'language' => (string) $brief->language,
            'draft_type' => DraftType::ORIGINAL->value,
            'content_html' => null,
            'meta' => $meta,
            'links' => null,
            'credit_cost' => max(1, (int) config('argusly.ai.drafts.credit_cost', 4)),
            'delivery_status' => 'pending',
        ]);
    }

    /**
     * @return array{success:bool,queued:bool,message:string}
     */
    private function publishContent(Content $content, Draft $draft): array
    {
        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));

        if ($siteType === ClientSite::TYPE_WORDPRESS) {
            $dispatch = $this->publicationService->dispatchWordPressPublication($content, $draft, [
                'source' => 'content_automation.run',
            ]);

            return [
                'success' => (bool) ($dispatch['queued'] ?? false) || (string) ($dispatch['skip_reason'] ?? '') === 'publication_already_queued',
                'queued' => (bool) ($dispatch['queued'] ?? false),
                'message' => (bool) ($dispatch['queued'] ?? false)
                    ? 'WordPress publication queued.'
                    : (string) ($dispatch['skip_reason'] ?? 'WordPress publication skipped.'),
            ];
        }

        if ($siteType === ClientSite::TYPE_LARAVEL) {
            if ($this->laravelDestinationResolver->resolveForContent($content)) {
                $result = $this->publicationService->publishVariantNow($content, $content->localeCode(), [
                    'source' => 'content_automation.run',
                ]);

                return [
                    'success' => (bool) ($result['queued'] ?? false) || (string) ($result['skip_reason'] ?? '') === 'publication_already_queued',
                    'queued' => (bool) ($result['queued'] ?? false),
                    'message' => (bool) ($result['queued'] ?? false)
                        ? 'Laravel publication queued.'
                        : (string) ($result['skip_reason'] ?? 'Laravel publication skipped.'),
                ];
            }

            $this->laravelPublishingService->publish($content, $draft, 'publish_now', 'content_automation.run');

            return [
                'success' => true,
                'queued' => true,
                'message' => 'Laravel content marked as published.',
            ];
        }

        return [
            'success' => false,
            'queued' => false,
            'message' => 'Publishing is not supported for this site type.',
        ];
    }

    private function syncSourceContentFamilySettings(
        Content $content,
        ContentAutomation $automation,
        int|string|null $actorId = null,
    ): Content {
        $familyId = (string) ($content->family_id ?: $content->id);
        $shouldAutoPublishTranslations = $automation->usesSyncedFamilyPublishing()
            && $automation->autoPublishTranslationsWithSource();
        $updates = [];

        if ((string) ($content->family_id ?? '') !== $familyId) {
            $updates['family_id'] = $familyId;
        }

        if (! (bool) $content->is_source_locale) {
            $updates['is_source_locale'] = true;
        }

        if ($content->translation_source_content_id !== null) {
            $updates['translation_source_content_id'] = null;
        }

        if ($content->translation_source_locale !== null) {
            $updates['translation_source_locale'] = null;
        }

        if ((bool) ($content->sync_with_source ?? true) !== true) {
            $updates['sync_with_source'] = true;
        }

        if ((bool) ($content->auto_publish ?? true) !== $shouldAutoPublishTranslations) {
            $updates['auto_publish'] = $shouldAutoPublishTranslations;
        }

        if ($actorId !== null && empty($content->updated_by)) {
            $updates['updated_by'] = $actorId;
        }

        if ($updates !== []) {
            $content->forceFill($updates)->save();
        }

        return $content->fresh(['clientSite', 'contentDestination']) ?? $content;
    }

    private function existingTranslationVariantForQueueFailure(Draft $sourceDraft, string $locale, \Throwable $exception): ?Content
    {
        $message = strtolower($exception->getMessage());

        if (! str_contains($message, 'already exists for this draft')
            && ! str_contains($message, 'already processing')) {
            return null;
        }

        $targetLanguage = SupportedLanguage::fromStringOrDefault($locale);

        try {
            $existingVariant = $this->translationService->resolveExistingTargetVariantForRefresh(
                $sourceDraft,
                $targetLanguage
            );

            if ($existingVariant instanceof Content) {
                return $existingVariant;
            }
        } catch (\Throwable $resolverException) {
            Log::warning('content_automation.translation_existing_variant_resolver_failed', [
                'source_draft_id' => (string) $sourceDraft->id,
                'target_locale' => $targetLanguage->value,
                'queue_failure_message' => $exception->getMessage(),
                'resolver_failure_message' => $resolverException->getMessage(),
            ]);
        }

        $originalSourceDraft = $sourceDraft->getOriginalSourceDraft() ?? $sourceDraft;
        $originalSourceDraft->loadMissing('content.translationSourceContent', 'content.familyRoot', 'content.localizedVariants');

        $sourceContent = $originalSourceDraft->content;

        if (! $sourceContent instanceof Content) {
            return null;
        }

        $existingVariant = $sourceContent->localizedVariantFor($targetLanguage->value);

        if ($existingVariant instanceof Content && (string) $existingVariant->id !== (string) $sourceContent->id) {
            return $existingVariant;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $articlePlan
     */
    private function composeNotes(ContentAutomation $automation, array $articlePlan): string
    {
        $parts = array_filter([
            trim((string) ($automation->content_goal ?? '')) !== '' ? 'Goal: ' . trim((string) $automation->content_goal) : null,
            trim((string) ($articlePlan['goal'] ?? '')) !== '' ? 'Article goal: ' . trim((string) $articlePlan['goal']) : null,
            trim((string) ($articlePlan['angle'] ?? '')) !== '' ? 'Angle: ' . trim((string) $articlePlan['angle']) : null,
            trim((string) ($automation->campaign_context ?? '')) !== '' ? 'Campaign context: ' . trim((string) $automation->campaign_context) : null,
            trim((string) ($automation->company_context_override ?? '')) !== '' ? 'Company context: ' . trim((string) $automation->company_context_override) : null,
            trim((string) data_get($automation->settings, 'content_pillars', '')) !== ''
                ? 'Content pillars: ' . trim((string) data_get($automation->settings, 'content_pillars'))
                : null,
            ! empty($articlePlan['internal_link_targets'] ?? [])
                ? 'Internal linking priority: reference these related chain articles where natural: ' . implode(', ', (array) $articlePlan['internal_link_targets'])
                : null,
            $automation->avoid_topic_overlap
                ? 'Avoid overlapping with existing recent site topics and keep the angle distinct.'
                : null,
        ]);

        return $parts === [] ? '' : implode("\n\n", $parts);
    }

    private function resolveAudience(ContentAutomation $automation): ?string
    {
        $buyerName = trim((string) ($automation->buyerPersona?->name ?? ''));
        if ($buyerName !== '') {
            return $buyerName;
        }

        $teamName = trim((string) ($automation->teamPersona?->name ?? ''));
        if ($teamName !== '') {
            return $teamName;
        }

        return $this->nullableString($automation->workspace?->companyProfile?->target_audience);
    }

    private function resolveTone(ContentAutomation $automation): ?string
    {
        $tone = trim((string) ($automation->brandVoice?->tone_of_voice ?? $automation->brandVoice?->default_tone ?? ''));

        return $tone !== '' ? $tone : null;
    }

    private function resolveFallbackSite(ContentAutomation $automation): ?ClientSite
    {
        return ClientSite::query()
            ->where('workspace_id', $automation->workspace_id)
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->orderBy('created_at')
            ->first();
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array<string,mixed> $articlePlan
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function logContext(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        ?ContentAutomationRunItem $item,
        array $articlePlan = [],
        array $extra = [],
    ): array {
        return array_merge([
            'automation_id' => (string) $automation->id,
            'automation_name' => (string) $automation->name,
            'run_id' => (string) $run->id,
            'item_id' => $item ? (string) $item->id : null,
            'site_id' => (string) ($item?->client_site_id ?? $automation->client_site_id ?? ''),
            'locale' => (string) ($item?->locale ?? ($articlePlan['target_locale'] ?? $automation->sourceLocale())),
            'chain_index' => (int) ($item?->chain_index ?? ($articlePlan['sequence'] ?? 0)),
            'provider' => $item?->provider,
            'model' => $item?->model,
            'prompt_hash' => $item?->prompt_hash,
            'prompt_present' => $item?->prompt_hash !== null,
            'created_content_id' => $item?->content_id,
        ], $extra);
    }
}
