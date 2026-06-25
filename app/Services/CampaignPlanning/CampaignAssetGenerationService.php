<?php

namespace App\Services\CampaignPlanning;

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\CampaignStatus;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\SupportedLanguage;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Models\Brief;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\SocialPostVariant;
use App\Models\StructuredAnswerBlock;
use App\Models\User;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\Credits\GenerationPricing;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CampaignAssetGenerationService
{
    public function __construct(
        private readonly BriefToDraftService $briefToDraftService,
        private readonly GenerationPricing $pricing,
        private readonly ContentPublicationService $publicationService,
        private readonly LaravelConnectorDestinationResolver $laravelDestinationResolver,
        private readonly LaravelConnectorPublishingService $laravelPublishingService,
    ) {}

    /**
     * @return array{generated_content:int,generated_social:int,generated_answer_blocks:int,skipped:int,scheduled_articles:int,due_publications_queued:int}
     */
    public function generate(Campaign $campaign, User $actor): array
    {
        return DB::transaction(function () use ($campaign, $actor): array {
            $campaign = Campaign::query()
                ->with(['workspace.clientSites', 'contents'])
                ->lockForUpdate()
                ->findOrFail($campaign->id);

            $this->approveCampaignForGeneration($campaign, $actor);

            $summary = [
                'generated_content' => 0,
                'generated_social' => 0,
                'generated_answer_blocks' => 0,
                'skipped' => 0,
                'scheduled_articles' => 0,
                'due_publications_queued' => 0,
            ];

            $languages = $this->campaignLanguages($campaign);

            foreach ($campaign->contents->sortBy('sequence_order') as $asset) {
                $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

                if (in_array($type, [
                    CampaignContentAssetType::LINKEDIN_POST->value,
                    CampaignContentAssetType::INSTAGRAM_POST->value,
                    CampaignContentAssetType::FOUNDER_POST->value,
                ], true)) {
                    foreach ($languages as $language) {
                        $summary[$this->generateSocialVariant($campaign, $asset, $actor, $language)]++;
                    }

                    continue;
                }

                if (in_array($type, [
                    CampaignContentAssetType::FAQ_BLOCK->value,
                    CampaignContentAssetType::ANSWER_BLOCK->value,
                ], true)) {
                    foreach ($languages as $language) {
                        $result = $this->generateStructuredAnswerBlocks($campaign, $asset, $language);
                        $summary[$result['status']] += $result['count'];
                    }

                    continue;
                }

                foreach ($languages as $language) {
                    $summary[$this->generateContentDraft($campaign, $asset, $actor, $language)]++;
                }
            }

            $summary = array_replace($summary, $this->publicationSummary($campaign));

            return $summary;
        });
    }

    /**
     * @return array{
     *   estimated_credits:int,
     *   pending_credits:int,
     *   credits_per_draft:int,
     *   draft_assets:int,
     *   pending_draft_assets:int,
     *   no_credit_assets:int,
     *   already_generated_assets:int,
     *   skipped_assets:int
     * }
     */
    public function estimate(Campaign $campaign): array
    {
        $campaign->loadMissing(['contents']);

        $languages = $this->campaignLanguages($campaign);
        $languageCount = max(1, count($languages));
        $creditsPerDraft = $this->creditsPerDraft();
        $draftAssets = 0;
        $pendingDraftAssets = 0;
        $noCreditAssets = 0;
        $alreadyGeneratedAssets = 0;
        $skippedAssets = 0;

        foreach ($campaign->contents->sortBy('sequence_order') as $asset) {
            $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

            if ($this->isNoCreditAssetType($type)) {
                $noCreditAssets++;

                if ($this->assetAlreadyGeneratedForAllLanguages($asset, $type, $languages)) {
                    $alreadyGeneratedAssets++;
                }

                continue;
            }

            $draftAssets++;

            if ($this->assetAlreadyGeneratedForAllLanguages($asset, $type, $languages)) {
                $alreadyGeneratedAssets++;
                $skippedAssets++;

                continue;
            }

            $pendingDraftAssets += $this->pendingLanguageCount($asset, $type, $languages);
        }

        return [
            'estimated_credits' => $draftAssets * $languageCount * $creditsPerDraft,
            'pending_credits' => $pendingDraftAssets * $creditsPerDraft,
            'credits_per_draft' => $creditsPerDraft,
            'draft_assets' => $draftAssets,
            'pending_draft_assets' => $pendingDraftAssets,
            'no_credit_assets' => $noCreditAssets,
            'already_generated_assets' => $alreadyGeneratedAssets,
            'skipped_assets' => $skippedAssets,
            'language_count' => $languageCount,
            'languages' => $languages,
        ];
    }

    private function approveCampaignForGeneration(Campaign $campaign, User $actor): void
    {
        $planningContext = (array) $campaign->ai_planning_context;
        $checkpoints = (array) data_get($planningContext, 'approval_checkpoints', []);
        foreach ($checkpoints as $key => $checkpoint) {
            $checkpoints[$key] = array_replace((array) $checkpoint, [
                'status' => CampaignApprovalStatus::APPROVED->value,
                'approved_at' => now()->toIso8601String(),
                'approved_by' => $actor->id,
            ]);
        }
        data_set($planningContext, 'approval_checkpoints', $checkpoints);

        $campaign->forceFill([
            'status' => CampaignStatus::APPROVED->value,
            'approval_status' => CampaignApprovalStatus::APPROVED->value,
            'approved_at' => $campaign->approved_at ?: now(),
            'approved_by' => $campaign->approved_by ?: $actor->id,
            'ai_planning_context' => $planningContext,
        ])->save();

        $campaign->contents()->update([
            'approval_status' => CampaignApprovalStatus::APPROVED->value,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);
    }

    private function generateContentDraft(Campaign $campaign, CampaignContent $asset, User $actor, string $language): string
    {
        $site = $this->generationSite($campaign);
        if (! $site) {
            return 'skipped';
        }

        if ($this->generatedContentIdForLanguage($asset, $language)) {
            return $this->queueDraftForExistingAsset($campaign, $asset, $actor, $site, $language);
        }

        $brief = (array) $asset->brief;
        $title = (string) $asset->working_title;
        $description = (string) data_get($brief, 'angle', $campaign->objective);
        $language = SupportedLanguage::fromStringOrDefault($language)->value;
        $autoPublishArticle = $this->shouldAutoPublishArticle($asset);
        $scheduledPublishAt = $autoPublishArticle ? $this->plannerScheduledFor($asset) : null;
        $sourceContent = $this->sourceContentForAsset($asset, $campaign, $language);
        if ($sourceContent && ! $sourceContent->family_id) {
            $sourceContent->forceFill([
                'family_id' => (string) $sourceContent->id,
                'translation_source_locale' => null,
                'is_source_locale' => true,
            ])->save();
        }

        $content = Content::query()->create([
            'workspace_id' => (string) $campaign->workspace_id,
            'client_site_id' => $site?->id,
            'title' => $title,
            'language' => $language,
            'family_id' => $sourceContent?->localizationRootId(),
            'translation_source_content_id' => $sourceContent ? (string) $sourceContent->id : null,
            'translation_source_locale' => $sourceContent?->localeCode(),
            'is_source_locale' => $sourceContent ? false : true,
            'sync_with_source' => (bool) $sourceContent,
            'translation_generated_at' => $sourceContent ? now() : null,
            'translation_source_updated_at' => $sourceContent?->updated_at,
            'primary_keyword' => (string) data_get($brief, 'topic', $campaign->name),
            'type' => ContentType::ARTICLE->value,
            'status' => 'brief',
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
            'source' => ContentSource::AUTOMATION->value,
            'origin_type' => ContentOriginType::AUTOMATION->value,
            'delivery_status' => 'pending',
            'scheduled_publish_at' => $scheduledPublishAt,
            'publish_status' => $scheduledPublishAt ? 'scheduled' : 'draft',
            'generation_mode' => 'balanced',
            'language' => $language,
            'auto_publish' => $autoPublishArticle,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'seo_title' => Str::limit($title, 68, ''),
            'seo_meta_description' => Str::limit($description, 158, ''),
            'seo_h1' => $title,
            'schema_type' => $this->schemaTypeFor($asset),
        ]);

        $briefModel = $this->createBrief($campaign, $asset, $actor, $site, $content, $language);
        $draft = $this->briefToDraftService->claimAndCreateDraft((string) $briefModel->id);

        $asset->forceFill([
            'content_id' => $this->isPrimaryCampaignLanguage($campaign, $language) ? (string) $content->id : $asset->content_id,
            'status' => 'draft_queued',
            'metadata' => array_replace_recursive((array) $asset->metadata, [
                'generated_content_id' => (string) $content->id,
                'generated_brief_id' => (string) $briefModel->id,
                'generated_draft_id' => $draft ? (string) $draft->id : null,
                'generated_locale_content_ids' => [
                    $language => (string) $content->id,
                ],
                'generated_locale_brief_ids' => [
                    $language => (string) $briefModel->id,
                ],
                'generated_locale_draft_ids' => [
                    $language => $draft ? (string) $draft->id : null,
                ],
                'auto_publish' => $autoPublishArticle,
                'scheduled_publish_at' => $scheduledPublishAt?->toIso8601String(),
                'generated_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return 'generated_content';
    }

    private function queueDraftForExistingAsset(Campaign $campaign, CampaignContent $asset, User $actor, ClientSite $site, string $language): string
    {
        $content = Content::query()->find($this->generatedContentIdForLanguage($asset, $language));
        if (! $content) {
            $meta = (array) $asset->metadata;
            data_forget($meta, 'generated_locale_content_ids.'.$language);
            $asset->forceFill(['metadata' => $meta])->save();

            return $this->generateContentDraft($campaign, $asset->fresh(), $actor, $language);
        }

        $this->syncPlannerPublishSchedule($asset, $content);

        $brief = Brief::query()
            ->where('content_id', $content->id)
            ->where('source', 'campaign_planner')
            ->first();

        if (! $brief) {
            $brief = $this->createBrief($campaign, $asset, $actor, $site, $content, $language);
        } else {
            $this->ensureBriefRequiredCredits($brief);
        }

        $draft = Draft::query()
            ->where('brief_id', $brief->id)
            ->orderByDesc('created_at')
            ->first();

        if ($draft && in_array((string) $draft->status, ['queued', 'processing', 'generating'], true)) {
            $this->markAssetQueued($asset, $brief, $draft, 'draft_queued', $language);

            return 'skipped';
        }

        if ($draft && trim((string) $draft->content_html) !== '') {
            $this->markAssetQueued($asset, $brief, $draft, 'draft_generated', $language);

            return 'skipped';
        }

        if ((string) $brief->status === 'done' && ! $draft) {
            $brief->forceFill([
                'status' => 'ready_for_generation',
                'progress' => 0.1,
            ])->save();
        }

        $draft = $this->briefToDraftService->claimAndCreateDraft((string) $brief->id);

        if ($draft) {
            $this->markAssetQueued($asset, $brief, $draft, 'draft_queued', $language);
        }

        return $draft ? 'generated_content' : 'skipped';
    }

    /**
     * @return array{status:'generated_answer_blocks'|'skipped',count:int}
     */
    private function generateStructuredAnswerBlocks(Campaign $campaign, CampaignContent $asset, string $language): array
    {
        $target = $this->answerBlockTargetContent($campaign, $asset, $language);
        if (! $target) {
            return ['status' => 'skipped', 'count' => 1];
        }

        $existingIds = collect((array) data_get(
            $asset->metadata,
            'generated_answer_block_ids_by_locale.'.$language,
            $this->isPrimaryCampaignLanguage($campaign, $language)
                ? data_get($asset->metadata, 'generated_answer_block_ids', [])
                : []
        ))
            ->filter(fn (mixed $id): bool => StructuredAnswerBlock::query()->whereKey((string) $id)->exists())
            ->values();

        if ($existingIds->isNotEmpty()) {
            $this->markAnswerBlocksCreated($asset, $target, $existingIds->all(), $language, skipped: true);

            return ['status' => 'skipped', 'count' => 1];
        }

        $items = $this->answerBlockItems($campaign, $asset);
        $savedIds = [];

        foreach ($items as $index => $item) {
            $existing = $target->answerBlocks()
                ->where('question', $item['question'])
                ->first();

            if ($existing) {
                $existing->forceFill([
                    'answer' => $item['answer'],
                    'entities' => $item['entities'],
                    'platforms' => $item['platforms'],
                    'order' => (int) ($target->answerBlocks()->max('order') ?? -1) + $index + 1,
                ])->save();

                $savedIds[] = (string) $existing->id;

                continue;
            }

            $block = StructuredAnswerBlock::query()->create([
                'content_id' => (string) $target->id,
                'question' => $item['question'],
                'answer' => $item['answer'],
                'entities' => $item['entities'],
                'platforms' => $item['platforms'],
                'order' => (int) ($target->answerBlocks()->max('order') ?? -1) + 1,
            ]);

            $savedIds[] = (string) $block->id;
        }

        $target->forceFill([
            'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
            'answer_block_visibility' => Content::ANSWER_BLOCK_VISIBILITY_VISIBLE,
            'answer_block_position' => Content::ANSWER_BLOCK_POSITION_AI_OPTIMIZED,
            'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_COMPLETED,
            'answer_block_generation_persisted_count' => $target->answerBlocks()->count(),
            'answer_block_generation_completed_at' => now(),
            'answer_block_generation_meta' => array_replace_recursive((array) $target->answer_block_generation_meta, [
                'campaign_planner' => [
                    'campaign_id' => (string) $campaign->id,
                    'campaign_content_id' => (string) $asset->id,
                    'saved_block_ids' => $savedIds,
                    'completed_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        $this->markAnswerBlocksCreated($asset, $target, $savedIds, $language);

        return ['status' => 'generated_answer_blocks', 'count' => count($savedIds)];
    }

    private function answerBlockTargetContent(Campaign $campaign, CampaignContent $asset, string $language): ?Content
    {
        $targets = collect((array) $asset->internal_linking_targets)
            ->push('pillar_article')
            ->filter()
            ->unique()
            ->values();

        $targetAsset = $campaign->contents
            ->sortBy('sequence_order')
            ->first(function (CampaignContent $candidate) use ($targets): bool {
                $key = (string) data_get($candidate->metadata, 'planner_key', '');

                return $targets->contains($key) && filled($candidate->content_id);
            });

        if (! $targetAsset) {
            return null;
        }

        $contentId = $this->generatedContentIdForLanguage($targetAsset, $language);

        return $contentId ? Content::query()->find($contentId) : null;
    }

    /**
     * @return list<array{question:string,answer:string,entities:list<string>,platforms:list<string>}>
     */
    private function answerBlockItems(Campaign $campaign, CampaignContent $asset): array
    {
        $brief = (array) $asset->brief;
        $topic = Str::title((string) data_get($brief, 'topic', $campaign->name));
        $audience = trim((string) data_get($brief, 'audience_segment', 'marketing teams'));
        $angle = trim((string) data_get($brief, 'angle', $campaign->objective));
        $baseAnswer = $angle !== '' ? $angle : $campaign->objective;
        $entities = collect([$topic, $audience, 'campaign planning', 'AI visibility'])
            ->filter()
            ->values()
            ->all();

        $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

        if ($type === CampaignContentAssetType::FAQ_BLOCK->value) {
            return [
                [
                    'question' => 'Why does '.$topic.' matter now?',
                    'answer' => $baseAnswer,
                    'entities' => $entities,
                    'platforms' => ['website', 'ai_search'],
                ],
                [
                    'question' => 'How does '.$topic.' help '.$audience.'?',
                    'answer' => $topic.' helps '.$audience.' connect strategy, execution, governance, measurement, and reuse across the campaign path.',
                    'entities' => $entities,
                    'platforms' => ['website', 'ai_search'],
                ],
                [
                    'question' => 'When should a team use '.$topic.'?',
                    'answer' => 'Use '.$topic.' when a topic needs coordinated articles, distribution assets, decision support, internal links, and review gates instead of isolated content pieces.',
                    'entities' => $entities,
                    'platforms' => ['website', 'ai_search'],
                ],
            ];
        }

        return [[
            'question' => 'What is '.$topic.'?',
            'answer' => $baseAnswer,
            'entities' => $entities,
            'platforms' => ['website', 'ai_search'],
        ]];
    }

    /**
     * @param list<string> $savedIds
     */
    private function markAnswerBlocksCreated(CampaignContent $asset, Content $target, array $savedIds, string $language, bool $skipped = false): void
    {
        $asset->forceFill([
            'content_id' => $asset->content_id ?: (string) $target->id,
            'status' => 'answer_blocks_created',
            'metadata' => array_replace_recursive((array) $asset->metadata, [
                'generated_content_id' => (string) $target->id,
                'generated_answer_block_ids' => array_values($savedIds),
                'generated_answer_blocks_count' => count($savedIds),
                'generated_answer_blocks_target_content_id' => (string) $target->id,
                'generated_answer_block_ids_by_locale' => [
                    $language => array_values($savedIds),
                ],
                'generated_answer_blocks_target_content_ids_by_locale' => [
                    $language => (string) $target->id,
                ],
                'generated_at' => $skipped
                    ? data_get($asset->metadata, 'generated_at')
                    : now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function markAssetQueued(CampaignContent $asset, Brief $brief, Draft $draft, string $status = 'draft_queued', ?string $language = null): void
    {
        $language = $language ?: SupportedLanguage::fromStringOrDefault($draft->language)->value;
        $asset->forceFill([
            'status' => $status,
            'metadata' => array_replace_recursive((array) $asset->metadata, [
                'generated_brief_id' => (string) $brief->id,
                'generated_draft_id' => (string) $draft->id,
                'generated_locale_brief_ids' => [
                    $language => (string) $brief->id,
                ],
                'generated_locale_draft_ids' => [
                    $language => (string) $draft->id,
                ],
                'generated_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function createBrief(Campaign $campaign, CampaignContent $asset, User $actor, ClientSite $site, Content $content, string $language): Brief
    {
        $brief = (array) $asset->brief;
        $title = (string) $asset->working_title;
        $language = SupportedLanguage::fromStringOrDefault($language)->value;

        return Brief::query()->create([
            'client_site_id' => (string) $site->id,
            'created_by_user_id' => $actor->id,
            'content_id' => (string) $content->id,
            'status' => 'draft',
            'source' => 'campaign_planner',
            'progress' => 0,
            'title' => $title,
            'language' => $language,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'primary_keyword' => (string) data_get($brief, 'topic', $campaign->name),
            'intent' => (string) data_get($brief, 'search_intent', 'informational'),
            'target_audience' => (string) data_get($brief, 'audience_segment', ''),
            'audience' => (string) data_get($brief, 'audience_segment', ''),
            'funnel_stage' => (string) data_get($brief, 'funnel_stage', 'awareness'),
            'search_intent' => (string) data_get($brief, 'search_intent', 'informational'),
            'tone_of_voice' => (string) data_get($asset->ai_generation_context, 'tone_variation', ''),
            'unique_angle' => (string) data_get($brief, 'angle', $campaign->objective),
            'key_points' => (array) data_get($asset->ai_generation_context, 'deterministic_outline', []),
            'call_to_action' => 'Review and approve this campaign asset before publishing.',
            'notes' => $this->briefNotes($campaign, $asset),
            'client_refs' => [
                'source' => 'campaign_planner',
                'campaign_id' => (string) $campaign->id,
                'campaign_content_id' => (string) $asset->id,
                'review_required' => true,
                'auto_publish' => false,
                'schema_type' => $this->schemaTypeFor($asset),
                'required_credits' => $this->creditsPerDraft(),
                'language' => $language,
                'planner_scheduled_for' => $asset->scheduled_for?->toIso8601String(),
            ],
        ]);
    }

    private function ensureBriefRequiredCredits(Brief $brief): void
    {
        if ((int) data_get($brief->client_refs, 'required_credits', 0) > 0) {
            return;
        }

        $brief->forceFill([
            'client_refs' => array_replace((array) $brief->client_refs, [
                'required_credits' => $this->creditsPerDraft(),
            ]),
        ])->save();
    }

    private function creditsPerDraft(): int
    {
        return $this->pricing->requiredCredits(GenerationPricing::TYPE_ARTICLE, null);
    }

    private function isNoCreditAssetType(string $type): bool
    {
        return in_array($type, [
            CampaignContentAssetType::LINKEDIN_POST->value,
            CampaignContentAssetType::INSTAGRAM_POST->value,
            CampaignContentAssetType::FOUNDER_POST->value,
            CampaignContentAssetType::FAQ_BLOCK->value,
            CampaignContentAssetType::ANSWER_BLOCK->value,
        ], true);
    }

    /**
     * @param  list<string>  $languages
     */
    private function assetAlreadyGeneratedForAllLanguages(CampaignContent $asset, string $type, array $languages): bool
    {
        foreach ($languages as $language) {
            if (! $this->assetAlreadyGeneratedForLanguage($asset, $type, $language)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $languages
     */
    private function pendingLanguageCount(CampaignContent $asset, string $type, array $languages): int
    {
        return collect($languages)
            ->reject(fn (string $language): bool => $this->assetAlreadyGeneratedForLanguage($asset, $type, $language))
            ->count();
    }

    private function assetAlreadyGeneratedForLanguage(CampaignContent $asset, string $type, string $language): bool
    {
        if (in_array($type, [
            CampaignContentAssetType::LINKEDIN_POST->value,
            CampaignContentAssetType::INSTAGRAM_POST->value,
            CampaignContentAssetType::FOUNDER_POST->value,
        ], true)) {
            return (bool) data_get($asset->metadata, 'generated_social_variants_by_locale.'.$language)
                || ($this->isPrimaryAssetLanguage($asset, $language) && (bool) data_get($asset->metadata, 'generated_social_variant'));
        }

        if (in_array($type, [
            CampaignContentAssetType::FAQ_BLOCK->value,
            CampaignContentAssetType::ANSWER_BLOCK->value,
        ], true)) {
            return ! empty(data_get($asset->metadata, 'generated_answer_block_ids_by_locale.'.$language, []))
                || ($this->isPrimaryAssetLanguage($asset, $language) && ! empty(data_get($asset->metadata, 'generated_answer_block_ids', [])));
        }

        return filled($this->generatedContentIdForLanguage($asset, $language))
            || filled(data_get($asset->metadata, 'generated_locale_draft_ids.'.$language));
    }

    private function generatedContentIdForLanguage(CampaignContent $asset, string $language): ?string
    {
        $language = SupportedLanguage::fromStringOrDefault($language)->value;
        $contentId = trim((string) data_get($asset->metadata, 'generated_locale_content_ids.'.$language, ''));

        if ($contentId !== '') {
            return $contentId;
        }

        if ($this->isPrimaryAssetLanguage($asset, $language) && $asset->content_id) {
            return (string) $asset->content_id;
        }

        return null;
    }

    private function isPrimaryAssetLanguage(CampaignContent $asset, string $language): bool
    {
        $campaign = $asset->relationLoaded('campaign') ? $asset->campaign : null;
        if ($campaign instanceof Campaign) {
            return $this->isPrimaryCampaignLanguage($campaign, $language);
        }

        return $language === SupportedLanguage::default()->value;
    }

    private function isPrimaryCampaignLanguage(Campaign $campaign, string $language): bool
    {
        $languages = $this->campaignLanguages($campaign);

        return $language === ($languages[0] ?? $campaign->workspace?->defaultContentLanguageCode() ?? SupportedLanguage::default()->value);
    }

    /**
     * @return list<string>
     */
    private function campaignLanguages(Campaign $campaign): array
    {
        $campaign->loadMissing('workspace');

        $workspace = $campaign->workspace;
        $enabled = $workspace?->enabled_content_languages ?: [SupportedLanguage::default()->value];
        $default = $workspace?->defaultContentLanguageCode() ?: SupportedLanguage::default()->value;
        $selected = (array) data_get($campaign->metadata, 'campaign_languages', data_get($campaign->ai_planning_context, 'languages', []));

        $languages = collect($selected)
            ->map(fn (mixed $language): string => SupportedLanguage::fromStringOrDefault((string) $language)->value)
            ->filter(fn (string $language): bool => in_array($language, $enabled, true))
            ->prepend($default)
            ->unique()
            ->values()
            ->all();

        return $languages !== [] ? $languages : [$default];
    }

    private function sourceContentForAsset(CampaignContent $asset, Campaign $campaign, string $language): ?Content
    {
        if ($this->isPrimaryCampaignLanguage($campaign, $language)) {
            return null;
        }

        $primaryLanguage = $this->campaignLanguages($campaign)[0] ?? $campaign->workspace?->defaultContentLanguageCode() ?? SupportedLanguage::default()->value;
        $primaryId = $this->generatedContentIdForLanguage($asset, $primaryLanguage);

        return $primaryId ? Content::query()->find($primaryId) : null;
    }

    private function generateSocialVariant(Campaign $campaign, CampaignContent $asset, User $actor, string $language): string
    {
        $language = SupportedLanguage::fromStringOrDefault($language)->value;
        $existing = SocialPostVariant::query()
            ->where('campaign_content_id', $asset->id)
            ->where('variant_type', 'campaign_planner_suggestion')
            ->get()
            ->contains(fn (SocialPostVariant $variant): bool => SupportedLanguage::fromStringOrDefault((string) data_get($variant->metadata, 'locale', data_get($variant->generation_prompt_context, 'locale', '')))->value === $language);

        if ($existing) {
            $asset->forceFill([
                'status' => 'social_draft_created',
                'metadata' => array_replace_recursive((array) $asset->metadata, [
                    'generated_social_variant' => true,
                    'generated_social_variants_by_locale' => [
                        $language => true,
                    ],
                    'generated_at' => data_get($asset->metadata, 'generated_at') ?: now()->toIso8601String(),
                ]),
            ])->save();

            return 'skipped';
        }

        $brief = (array) $asset->brief;
        $platform = $this->platformFor($asset);
        $body = $this->socialBody($campaign, $asset);

        SocialPostVariant::query()->create([
            'organization_id' => $campaign->organization_id,
            'workspace_id' => (string) $campaign->workspace_id,
            'campaign_id' => (string) $campaign->id,
            'campaign_content_id' => (string) $asset->id,
            'content_id' => $asset->source_content_id ?: $asset->content_id,
            'platform' => $platform,
            'post_type' => $this->postTypeFor($asset),
            'variant_type' => 'campaign_planner_suggestion',
            'status' => SocialPostVariantStatus::DRAFT->value,
            'variant_number' => 1,
            'hook' => (string) data_get($brief, 'angle', $asset->working_title),
            'body' => $body,
            'hashtags' => $this->hashtags($campaign),
            'generation_prompt_context' => [
                'source' => 'campaign_planner',
                'campaign_id' => (string) $campaign->id,
                'campaign_content_id' => (string) $asset->id,
                'platform' => $platform,
                'asset_type' => $asset->asset_type?->value ?? $asset->asset_type,
                'locale' => $language,
                'media_required' => $platform === SocialPlatform::INSTAGRAM->value,
                'planner_scheduled_for' => $asset->scheduled_for?->toIso8601String(),
                'brief' => $brief,
            ],
            'generation_result' => [
                'mode' => 'deterministic_suggested_copy',
                'review_required' => true,
            ],
            'generated_at' => now(),
            'submitted_for_approval_at' => now(),
            'metadata' => [
                'created_by' => $actor->id,
                'auto_publish' => false,
                'locale' => $language,
                'planner_scheduled_for' => $asset->scheduled_for?->toIso8601String(),
            ],
        ]);

        $asset->forceFill([
            'status' => 'social_draft_created',
            'metadata' => array_replace_recursive((array) $asset->metadata, [
                'generated_social_variant' => true,
                'generated_social_variants_by_locale' => [
                    $language => true,
                ],
                'generated_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return 'generated_social';
    }

    private function shouldAutoPublishArticle(CampaignContent $asset): bool
    {
        return ($asset->asset_type?->value ?? (string) $asset->asset_type) === CampaignContentAssetType::ARTICLE->value
            && $this->plannerScheduledFor($asset) !== null;
    }

    private function plannerScheduledFor(CampaignContent $asset): ?\Illuminate\Support\Carbon
    {
        return $asset->scheduled_for?->copy();
    }

    private function syncPlannerPublishSchedule(CampaignContent $asset, Content $content): void
    {
        if (! $this->shouldAutoPublishArticle($asset)) {
            return;
        }

        $scheduledPublishAt = $this->plannerScheduledFor($asset);
        $content->forceFill([
            'scheduled_publish_at' => $scheduledPublishAt,
            'publish_status' => 'scheduled',
            'publish_error' => null,
            'auto_publish' => true,
        ])->save();

        $this->dispatchDuePlannerPublication($content->fresh(['clientSite']) ?? $content);
    }

    private function dispatchDuePlannerPublication(Content $content): bool
    {
        if (! $content->scheduled_publish_at || $content->scheduled_publish_at->isFuture()) {
            return false;
        }

        $draft = Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();

        if (! $draft || trim((string) $draft->content_html) === '') {
            return false;
        }

        $content->loadMissing('clientSite');
        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            return false;
        }

        try {
            if ($siteType === ClientSite::TYPE_LARAVEL && ! $this->laravelDestinationResolver->resolveForContent($content)) {
                $this->laravelPublishingService->publish($content, $draft, 'scheduled_publish', 'campaign_planner.auto_publish');

                return true;
            }

            $dispatch = $this->publicationService->dispatchPublication($content, $draft, [
                'source' => 'campaign_planner.auto_publish',
                'allow_stale_reclaim' => true,
            ]);

            return (bool) ($dispatch['queued'] ?? false);
        } catch (Throwable $exception) {
            Log::warning('campaign_planner.auto_publish_dispatch_failed', [
                'content_id' => (string) $content->id,
                'scheduled_publish_at' => $content->scheduled_publish_at?->toIso8601String(),
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * @return array{scheduled_articles:int,due_publications_queued:int}
     */
    private function publicationSummary(Campaign $campaign): array
    {
        $articleContentIds = $campaign->contents()
            ->where('asset_type', CampaignContentAssetType::ARTICLE->value)
            ->get()
            ->flatMap(function (CampaignContent $asset): array {
                $ids = collect((array) data_get($asset->metadata, 'generated_locale_content_ids', []))
                    ->filter()
                    ->values()
                    ->all();

                if ($asset->content_id) {
                    $ids[] = (string) $asset->content_id;
                }

                return $ids;
            })
            ->filter()
            ->unique()
            ->values();

        if ($articleContentIds->isEmpty()) {
            return [
                'scheduled_articles' => 0,
                'due_publications_queued' => 0,
            ];
        }

        $articles = Content::query()
            ->whereIn('id', $articleContentIds->all())
            ->get();

        return [
            'scheduled_articles' => $articles
                ->filter(fn (Content $content): bool => $content->scheduled_publish_at !== null && (string) $content->publish_status === 'scheduled')
                ->count(),
            'due_publications_queued' => $articles
                ->filter(fn (Content $content): bool => in_array((string) $content->publish_status, ['publishing', 'published'], true))
                ->count(),
        ];
    }

    private function generationSite(Campaign $campaign): ?ClientSite
    {
        if ($campaign->client_site_id) {
            return ClientSite::query()->find($campaign->client_site_id);
        }

        return $campaign->workspace?->clientSites
            ->sortByDesc(fn (ClientSite $site): int => $site->is_active ? 1 : 0)
            ->first();
    }

    private function schemaTypeFor(CampaignContent $asset): string
    {
        $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

        return match ($type) {
            CampaignContentAssetType::FAQ_BLOCK->value => 'FAQPage',
            CampaignContentAssetType::ANSWER_BLOCK->value => 'Question',
            default => 'Article',
        };
    }

    private function postTypeFor(CampaignContent $asset): string
    {
        $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

        return match ($type) {
            CampaignContentAssetType::FOUNDER_POST->value => SocialPostType::BUILDING_IN_PUBLIC->value,
            CampaignContentAssetType::INSTAGRAM_POST->value => SocialPostType::IMAGE->value,
            default => SocialPostType::THOUGHT_LEADERSHIP->value,
        };
    }

    private function platformFor(CampaignContent $asset): string
    {
        $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

        return $type === CampaignContentAssetType::INSTAGRAM_POST->value
            ? SocialPlatform::INSTAGRAM->value
            : SocialPlatform::LINKEDIN->value;
    }

    private function briefNotes(Campaign $campaign, CampaignContent $asset): string
    {
        $outline = collect((array) data_get($asset->ai_generation_context, 'deterministic_outline', []))
            ->map(fn (mixed $item): string => '- '.(string) $item)
            ->implode("\n");

        return trim(sprintf(
            "Generated from campaign plan: %s\n\nReview focus: %s\n\nOutline:\n%s",
            $campaign->name,
            (string) data_get($asset->brief, 'angle', 'Review this campaign asset before publishing.'),
            $outline
        ));
    }

    private function socialBody(Campaign $campaign, CampaignContent $asset): string
    {
        $brief = (array) $asset->brief;
        $audience = (string) data_get($brief, 'audience_segment', 'marketing teams');
        $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

        if ($type === CampaignContentAssetType::INSTAGRAM_POST->value) {
            return trim(implode("\n\n", [
                'A sharper campaign idea starts with one visible moment.',
                'Show the workflow, the before/after, or the decision point behind '.$campaign->name.'.',
                'What would make this easier to approve and ship?',
            ]));
        }

        return trim(implode("\n\n", [
            'For '.$audience.', the practical question is not whether the topic matters. It is how to turn it into a repeatable operating model with clear review gates.',
            'The campaign path starts with the pillar, expands into supporting guidance, then repurposes the strongest ideas into distribution assets.',
            'What would make this easier for your team to approve and ship?',
        ]));
    }

    /**
     * @return list<string>
     */
    private function hashtags(Campaign $campaign): array
    {
        return collect(explode(' ', (string) $campaign->name))
            ->map(fn (string $part): string => preg_replace('/[^A-Za-z0-9]/', '', $part) ?: '')
            ->filter()
            ->take(3)
            ->map(fn (string $part): string => '#'.$part)
            ->values()
            ->all();
    }
}
