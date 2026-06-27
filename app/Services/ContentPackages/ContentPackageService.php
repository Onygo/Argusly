<?php

namespace App\Services\ContentPackages;

use App\Enums\DraftType;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentPackage;
use App\Models\Draft;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\OpportunityExecutionPlan;
use App\Models\RecommendedAction;
use App\Models\SocialPostVariant;
use App\Models\User;
use App\Services\OpportunityIntelligence\BriefDraftService;
use App\Services\OpportunityIntelligence\ExecutionPlanBriefService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ContentPackageService
{
    public function __construct(
        private readonly ExecutionPlanBriefService $executionPlanBriefs,
        private readonly BriefDraftService $briefDrafts,
    ) {
    }

    public function prepareFromQueueItem(GrowthAutopilotQueueItem $item, User $user): ContentPackage
    {
        $item->loadMissing(['workspace', 'recommendedAction']);
        $source = $item->source;

        return DB::transaction(function () use ($item, $user, $source): ContentPackage {
            $existing = $this->existingPackage($item);
            if ($existing) {
                return $existing;
            }

            $site = $this->resolveClientSite($item);
            $package = ContentPackage::query()->create([
                'workspace_id' => $item->workspace_id,
                'organization_id' => $item->organization_id,
                'client_site_id' => $site->id,
                'growth_autopilot_queue_item_id' => $item->id,
                'recommended_action_id' => $item->recommended_action_id,
                'source_type' => $item::class,
                'source_id' => (string) $item->id,
                'source_signature' => $this->signature($item),
                'status' => ContentPackage::STATUS_PREPARING,
                'title' => $item->opportunity,
                'opportunity_summary' => $item->expected_impact,
                'recommended_action' => $item->recommended_action,
                'metadata' => [
                    'source_model' => $item->source_type,
                    'source_id' => $item->source_id,
                ],
            ]);

            $brief = $this->prepareBrief($package, $item, $site, $user, $source);
            $draft = $this->prepareDraft($package, $brief, $item, $user);
            $variant = $this->prepareLinkedInVariant($package, $draft, $item);

            $package->forceFill([
                'brief_id' => $brief->id,
                'draft_id' => $draft->id,
                'linkedin_variant_id' => $variant->id,
                'cta_recommendation' => $this->ctaRecommendation($item),
                'internal_linking_suggestions' => $this->internalLinkingSuggestions($draft, $item),
                'publishing_checklist' => $this->publishingChecklist($item),
                'prepared_assets' => $this->preparedAssets($brief, $draft, $variant),
                'status' => ContentPackage::STATUS_PREPARED,
                'prepared_at' => now(),
            ])->save();

            $this->markQueuePrepared($item, $package);

            return $package->fresh(['brief', 'draft', 'linkedInVariant']);
        });
    }

    private function existingPackage(GrowthAutopilotQueueItem $item): ?ContentPackage
    {
        return ContentPackage::query()
            ->where('source_signature', $this->signature($item))
            ->first();
    }

    private function resolveClientSite(GrowthAutopilotQueueItem $item): ClientSite
    {
        $site = ClientSite::query()
            ->where('workspace_id', $item->workspace_id)
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->first();

        if (! $site) {
            throw new RuntimeException('A connected site is required before Argusly can prepare a content package.');
        }

        return $site;
    }

    private function prepareBrief(ContentPackage $package, GrowthAutopilotQueueItem $item, ClientSite $site, User $user, ?Model $source): Brief
    {
        if ($source instanceof OpportunityExecutionPlan && in_array((string) $source->status, [OpportunityExecutionPlan::STATUS_APPROVED, OpportunityExecutionPlan::STATUS_PLANNED], true)) {
            return $this->executionPlanBriefs->createBrief($source, $user);
        }

        return Brief::query()->create([
            'client_site_id' => $site->id,
            'created_by_user_id' => $user->id,
            'status' => 'draft',
            'source' => 'content_package',
            'progress' => 0,
            'title' => Str::limit($item->opportunity, 180, ''),
            'language' => 'en',
            'content_type' => 'article',
            'output_type' => 'article',
            'intent' => Str::limit((string) $item->expected_impact, 255, ''),
            'audience' => 'B2B buyers researching this topic',
            'target_audience' => 'B2B buyers researching this topic',
            'tone_of_voice' => 'clear, practical and evidence-led',
            'unique_angle' => Str::limit((string) data_get($item->metadata, 'why_this_matters', $item->expected_impact), 500, ''),
            'key_points' => [
                $item->recommended_action,
                (string) $item->expected_impact,
                (string) data_get($item->metadata, 'what_argusly_will_do', ''),
            ],
            'call_to_action' => (string) data_get($this->ctaRecommendation($item), 'text'),
            'notes' => 'Prepared by Argusly Content Packages from Growth Autopilot.',
            'client_refs' => [
                'source' => 'content_package',
                'content_package_id' => (string) $package->id,
                'growth_autopilot_queue_item_id' => (string) $item->id,
                'recommended_action_id' => (string) $item->recommended_action_id,
                'workspace_id' => (string) $item->workspace_id,
            ],
        ]);
    }

    private function prepareDraft(ContentPackage $package, Brief $brief, GrowthAutopilotQueueItem $item, User $user): Draft
    {
        if ((string) $brief->source === 'opportunity_execution_plan') {
            return $this->briefDrafts->createDraft($brief, $user);
        }

        $existingDraftId = (string) data_get($brief->client_refs, 'draft_id', '');
        if ($existingDraftId !== '') {
            $existing = Draft::query()->whereKey($existingDraftId)->where('brief_id', $brief->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        $draft = Draft::query()->create([
            'brief_id' => $brief->id,
            'client_site_id' => $brief->client_site_id,
            'status' => Draft::STATUS_DRAFT,
            'title' => $brief->title,
            'seo_title' => $brief->title,
            'seo_h1' => $brief->title,
            'seo_meta_description' => Str::limit((string) ($brief->unique_angle ?: $item->expected_impact), 155, ''),
            'output_type' => $brief->output_type ?: 'article',
            'language' => SupportedLanguage::tryFromString((string) $brief->language)?->value ?? SupportedLanguage::default()->value,
            'draft_type' => DraftType::ORIGINAL->value,
            'content_html' => $this->starterDraftHtml($brief, $item),
            'meta' => [
                'source' => 'content_package',
                'generation_mode' => 'package_starter_draft',
                'generate_later' => true,
                'content_package_id' => (string) $package->id,
                'growth_autopilot_queue_item_id' => (string) $item->id,
            ],
            'links' => [],
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
        ]);

        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
        $refs['draft_id'] = (string) $draft->id;
        $refs['draft_created_by'] = (string) $user->id;
        $refs['draft_created_at'] = now()->toIso8601String();
        $brief->forceFill(['client_refs' => $refs])->save();

        return $draft;
    }

    private function prepareLinkedInVariant(ContentPackage $package, Draft $draft, GrowthAutopilotQueueItem $item): SocialPostVariant
    {
        $existing = SocialPostVariant::query()
            ->where('workspace_id', $item->workspace_id)
            ->where('metadata->content_package_id', (string) $package->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return SocialPostVariant::query()->create([
            'organization_id' => $item->organization_id,
            'workspace_id' => $item->workspace_id,
            'content_id' => $draft->content_id,
            'platform' => SocialPlatform::LINKEDIN->value,
            'post_type' => SocialPostType::THOUGHT_LEADERSHIP->value,
            'variant_type' => 'content_package',
            'status' => SocialPostVariantStatus::DRAFT->value,
            'variant_number' => 1,
            'hook' => Str::limit($item->opportunity, 120, ''),
            'body' => $this->linkedInBody($item),
            'hashtags' => ['#AIVisibility', '#ContentStrategy'],
            'selected' => true,
            'generated_at' => now(),
            'generation_prompt_context' => [
                'source' => 'content_package',
                'content_package_id' => (string) $package->id,
                'approval_required' => true,
            ],
            'metadata' => [
                'content_package_id' => (string) $package->id,
                'draft_id' => (string) $draft->id,
            ],
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function ctaRecommendation(GrowthAutopilotQueueItem $item): array
    {
        return [
            'label' => 'Primary CTA',
            'text' => 'Turn this insight into your next measurable growth action.',
            'rationale' => 'The CTA asks for a concrete next step while staying aligned with the opportunity.',
            'placement' => 'Article ending and LinkedIn post ending',
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function internalLinkingSuggestions(Draft $draft, GrowthAutopilotQueueItem $item): array
    {
        return [
            [
                'source' => 'content_package',
                'anchor' => Str::limit($item->opportunity, 60, ''),
                'target' => 'Best matching existing article',
                'reason' => 'Connect the new draft to related content before publishing.',
                'status' => 'suggested',
                'draft_id' => (string) $draft->id,
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function publishingChecklist(GrowthAutopilotQueueItem $item): array
    {
        return [
            ['item' => 'Review brief', 'status' => 'ready'],
            ['item' => 'Review draft', 'status' => 'ready'],
            ['item' => 'Approve LinkedIn variant', 'status' => 'ready'],
            ['item' => 'Confirm CTA', 'status' => 'ready'],
            ['item' => 'Review internal links', 'status' => 'ready'],
            ['item' => 'Schedule publishing', 'status' => 'pending_approval'],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function preparedAssets(Brief $brief, Draft $draft, SocialPostVariant $variant): array
    {
        return [
            ['type' => 'brief', 'label' => 'Brief', 'id' => (string) $brief->id, 'status' => 'prepared'],
            ['type' => 'draft', 'label' => 'Draft', 'id' => (string) $draft->id, 'status' => 'prepared'],
            ['type' => 'linkedin_variant', 'label' => 'LinkedIn Variant', 'id' => (string) $variant->id, 'status' => 'prepared'],
            ['type' => 'cta_recommendation', 'label' => 'CTA Recommendation', 'status' => 'prepared'],
            ['type' => 'internal_linking_suggestions', 'label' => 'Internal Linking Suggestions', 'status' => 'prepared'],
            ['type' => 'publishing_checklist', 'label' => 'Publishing Checklist', 'status' => 'prepared'],
        ];
    }

    private function starterDraftHtml(Brief $brief, GrowthAutopilotQueueItem $item): string
    {
        return '<article data-source="content_package">'
            .'<h1>'.e((string) $brief->title).'</h1>'
            .'<p><strong>Opportunity:</strong> '.e((string) $item->expected_impact).'</p>'
            .'<h2>Recommended action</h2>'
            .'<p>'.e((string) $item->recommended_action).'</p>'
            .'<h2>Draft outline</h2>'
            .'<ol><li>Explain the customer problem.</li><li>Show the opportunity evidence.</li><li>Recommend the practical next step.</li></ol>'
            .'<h2>CTA</h2>'
            .'<p>'.e((string) data_get($this->ctaRecommendation($item), 'text')).'</p>'
            .'</article>';
    }

    private function linkedInBody(GrowthAutopilotQueueItem $item): string
    {
        return Str::limit($item->opportunity, 160, '')
            ."\n\n"
            .Str::limit((string) $item->expected_impact, 260, '')
            ."\n\n"
            .'Argusly prepared the brief, draft, CTA, internal links, and publishing checklist so this can move from idea to execution.';
    }

    private function markQueuePrepared(GrowthAutopilotQueueItem $item, ContentPackage $package): void
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $metadata['content_package_id'] = (string) $package->id;

        $item->forceFill([
            'status' => GrowthAutopilotQueueItem::STATUS_PREPARED,
            'metadata' => $metadata,
        ])->save();
    }

    private function signature(GrowthAutopilotQueueItem $item): string
    {
        return sha1(implode('|', [
            'content-package',
            $item->workspace_id,
            $item->source_signature,
        ]));
    }
}
