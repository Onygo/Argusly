<?php

namespace App\Services\CampaignPlanning;

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\CampaignStatus;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Enums\ContentType;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignAssetGenerationService
{
    public function __construct(
        private readonly BriefToDraftService $briefToDraftService,
    ) {}

    /**
     * @return array{generated_content:int,generated_social:int,generated_answer_blocks:int,skipped:int}
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
            ];

            foreach ($campaign->contents->sortBy('sequence_order') as $asset) {
                $type = $asset->asset_type?->value ?? (string) $asset->asset_type;

                if (in_array($type, [
                    CampaignContentAssetType::LINKEDIN_POST->value,
                    CampaignContentAssetType::FOUNDER_POST->value,
                ], true)) {
                    $summary[$this->generateSocialVariant($campaign, $asset, $actor)]++;

                    continue;
                }

                if (in_array($type, [
                    CampaignContentAssetType::FAQ_BLOCK->value,
                    CampaignContentAssetType::ANSWER_BLOCK->value,
                ], true)) {
                    $result = $this->generateStructuredAnswerBlocks($campaign, $asset);
                    $summary[$result['status']] += $result['count'];

                    continue;
                }

                $summary[$this->generateContentDraft($campaign, $asset, $actor)]++;
            }

            return $summary;
        });
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

    private function generateContentDraft(Campaign $campaign, CampaignContent $asset, User $actor): string
    {
        $site = $this->generationSite($campaign);
        if (! $site) {
            return 'skipped';
        }

        if ($asset->content_id) {
            return $this->queueDraftForExistingAsset($campaign, $asset, $actor, $site);
        }

        $brief = (array) $asset->brief;
        $title = (string) $asset->working_title;
        $description = (string) data_get($brief, 'angle', $campaign->objective);
        $language = $campaign->workspace?->defaultContentLanguageCode() ?? 'en';

        $content = Content::query()->create([
            'workspace_id' => (string) $campaign->workspace_id,
            'client_site_id' => $site?->id,
            'title' => $title,
            'primary_keyword' => (string) data_get($brief, 'topic', $campaign->name),
            'type' => ContentType::ARTICLE->value,
            'status' => 'brief',
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
            'source' => ContentSource::AUTOMATION->value,
            'origin_type' => ContentOriginType::AUTOMATION->value,
            'delivery_status' => 'pending',
            'generation_mode' => 'balanced',
            'language' => $language,
            'auto_publish' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'seo_title' => Str::limit($title, 68, ''),
            'seo_meta_description' => Str::limit($description, 158, ''),
            'seo_h1' => $title,
            'schema_type' => $this->schemaTypeFor($asset),
        ]);

        $briefModel = $this->createBrief($campaign, $asset, $actor, $site, $content);
        $draft = $this->briefToDraftService->claimAndCreateDraft((string) $briefModel->id);

        $asset->forceFill([
            'content_id' => (string) $content->id,
            'status' => 'draft_queued',
            'metadata' => array_replace((array) $asset->metadata, [
                'generated_content_id' => (string) $content->id,
                'generated_brief_id' => (string) $briefModel->id,
                'generated_draft_id' => $draft ? (string) $draft->id : null,
                'generated_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return 'generated_content';
    }

    private function queueDraftForExistingAsset(Campaign $campaign, CampaignContent $asset, User $actor, ClientSite $site): string
    {
        $content = Content::query()->find($asset->content_id);
        if (! $content) {
            $asset->forceFill(['content_id' => null])->save();

            return $this->generateContentDraft($campaign, $asset->fresh(), $actor);
        }

        $brief = Brief::query()
            ->where('content_id', $content->id)
            ->where('source', 'campaign_planner')
            ->first();

        if (! $brief) {
            $brief = $this->createBrief($campaign, $asset, $actor, $site, $content);
        }

        $draft = Draft::query()
            ->where('brief_id', $brief->id)
            ->orderByDesc('created_at')
            ->first();

        if ($draft && in_array((string) $draft->status, ['queued', 'processing', 'generating'], true)) {
            $this->markAssetQueued($asset, $brief, $draft);

            return 'skipped';
        }

        if ($draft && trim((string) $draft->content_html) !== '') {
            $this->markAssetQueued($asset, $brief, $draft, 'draft_generated');

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
            $this->markAssetQueued($asset, $brief, $draft);
        }

        return $draft ? 'generated_content' : 'skipped';
    }

    /**
     * @return array{status:'generated_answer_blocks'|'skipped',count:int}
     */
    private function generateStructuredAnswerBlocks(Campaign $campaign, CampaignContent $asset): array
    {
        $target = $this->answerBlockTargetContent($campaign, $asset);
        if (! $target) {
            return ['status' => 'skipped', 'count' => 1];
        }

        $existingIds = collect((array) data_get($asset->metadata, 'generated_answer_block_ids', []))
            ->filter(fn (mixed $id): bool => StructuredAnswerBlock::query()->whereKey((string) $id)->exists())
            ->values();

        if ($existingIds->isNotEmpty()) {
            $this->markAnswerBlocksCreated($asset, $target, $existingIds->all(), skipped: true);

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

        $this->markAnswerBlocksCreated($asset, $target, $savedIds);

        return ['status' => 'generated_answer_blocks', 'count' => count($savedIds)];
    }

    private function answerBlockTargetContent(Campaign $campaign, CampaignContent $asset): ?Content
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

        return $targetAsset?->content_id ? Content::query()->find($targetAsset->content_id) : null;
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
    private function markAnswerBlocksCreated(CampaignContent $asset, Content $target, array $savedIds, bool $skipped = false): void
    {
        $asset->forceFill([
            'content_id' => (string) $target->id,
            'status' => 'answer_blocks_created',
            'metadata' => array_replace((array) $asset->metadata, [
                'generated_content_id' => (string) $target->id,
                'generated_answer_block_ids' => array_values($savedIds),
                'generated_answer_blocks_count' => count($savedIds),
                'generated_answer_blocks_target_content_id' => (string) $target->id,
                'generated_at' => $skipped
                    ? data_get($asset->metadata, 'generated_at')
                    : now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function markAssetQueued(CampaignContent $asset, Brief $brief, Draft $draft, string $status = 'draft_queued'): void
    {
        $asset->forceFill([
            'status' => $status,
            'metadata' => array_replace((array) $asset->metadata, [
                'generated_brief_id' => (string) $brief->id,
                'generated_draft_id' => (string) $draft->id,
                'generated_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function createBrief(Campaign $campaign, CampaignContent $asset, User $actor, ClientSite $site, Content $content): Brief
    {
        $brief = (array) $asset->brief;
        $title = (string) $asset->working_title;
        $language = $campaign->workspace?->defaultContentLanguageCode() ?? 'en';

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
            ],
        ]);
    }

    private function generateSocialVariant(Campaign $campaign, CampaignContent $asset, User $actor): string
    {
        $existing = SocialPostVariant::query()
            ->where('campaign_content_id', $asset->id)
            ->where('variant_type', 'campaign_planner_suggestion')
            ->exists();

        if ($existing) {
            $asset->forceFill([
                'status' => 'social_draft_created',
                'metadata' => array_replace((array) $asset->metadata, [
                    'generated_social_variant' => true,
                    'generated_at' => data_get($asset->metadata, 'generated_at') ?: now()->toIso8601String(),
                ]),
            ])->save();

            return 'skipped';
        }

        $brief = (array) $asset->brief;
        $body = $this->socialBody($campaign, $asset);

        SocialPostVariant::query()->create([
            'organization_id' => $campaign->organization_id,
            'workspace_id' => (string) $campaign->workspace_id,
            'campaign_id' => (string) $campaign->id,
            'campaign_content_id' => (string) $asset->id,
            'content_id' => $asset->source_content_id ?: $asset->content_id,
            'platform' => SocialPlatform::LINKEDIN->value,
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
                'asset_type' => $asset->asset_type?->value ?? $asset->asset_type,
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
            ],
        ]);

        $asset->forceFill([
            'status' => 'social_draft_created',
            'metadata' => array_replace((array) $asset->metadata, [
                'generated_social_variant' => true,
                'generated_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return 'generated_social';
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
            default => SocialPostType::THOUGHT_LEADERSHIP->value,
        };
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
