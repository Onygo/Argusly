<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Enums\GrowthProgramStatus;
use App\Enums\ProgrammaticPatternType;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticClusterItem;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticOpportunity;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProgrammaticGrowthDemoSeeder
{
    public function seed(Workspace $workspace, ?User $owner = null): GrowthProgram
    {
        return DB::transaction(function () use ($workspace, $owner): GrowthProgram {
            $workspace->loadMissing('organization');

            $site = ClientSite::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Programmatic Growth Demo Site',
                ],
                [
                    'type' => 'demo',
                    'site_url' => 'https://programmatic-growth-demo.example.com',
                    'allowed_domains' => ['programmatic-growth-demo.example.com'],
                    'is_active' => true,
                ],
            );

            $destination = ContentDestination::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Demo draft destination',
                ],
                [
                    'type' => 'api',
                    'status' => 'active',
                    'environment' => 'staging',
                    'config' => ['demo' => true, 'publishing_disabled' => true],
                    'default_language' => 'en',
                    'default_content_type' => 'article',
                    'export_format' => 'html',
                    'tracking_enabled' => false,
                    'seo_audit_enabled' => false,
                    'created_by' => $owner?->id,
                ],
            );

            $program = GrowthProgram::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'name' => 'Demo Programmatic Growth Flow',
                ],
                [
                    'organization_id' => $workspace->organization_id,
                    'description' => 'Safe internal demo flow for the controlled Programmatic Growth beta.',
                    'status' => GrowthProgramStatus::SCHEDULED->value,
                    'owner_user_id' => $owner?->id,
                    'score' => 82,
                    'estimated_reach' => 5400,
                    'estimated_ai_visibility_impact' => 68,
                    'metadata' => ['demo' => true, 'live_publishing_disabled' => true],
                    'detected_at' => now()->subDays(7),
                    'qualified_at' => now()->subDays(6),
                    'planned_at' => now()->subDays(5),
                    'briefed_at' => now()->subDays(4),
                    'drafting_at' => now()->subDays(3),
                    'review_at' => now()->subDays(2),
                    'scheduled_at' => now(),
                ],
            );

            $opportunity = ProgrammaticOpportunity::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'source_type' => GrowthProgram::class,
                    'source_id' => $program->id,
                    'pattern_type' => ProgrammaticPatternType::FAQ_LIBRARY->value,
                    'base_topic' => 'AI visibility reporting',
                ],
                [
                    'organization_id' => $workspace->organization_id,
                    'growth_program_id' => $program->id,
                    'variable_axis' => 'buyer question',
                    'example_variables' => ['measurement', 'reporting', 'governance'],
                    'estimated_variants_count' => 6,
                    'scale_score' => 84,
                    'business_value_score' => 78,
                    'seo_opportunity_score' => 72,
                    'ai_visibility_score' => 69,
                    'competition_score' => 42,
                    'confidence_score' => 81,
                    'status' => ProgrammaticOpportunity::STATUS_VALIDATED,
                    'explanation' => ['summary' => 'Generic FAQ expansion opportunity for internal testing.'],
                    'metadata' => ['demo' => true],
                    'detected_at' => now()->subDays(7),
                    'validated_at' => now()->subDays(6),
                ],
            );

            $cluster = ProgrammaticCluster::query()->firstOrCreate(
                ['programmatic_opportunity_id' => $opportunity->id],
                [
                    'organization_id' => $workspace->organization_id,
                    'workspace_id' => $workspace->id,
                    'growth_program_id' => $program->id,
                    'name' => 'AI visibility reporting FAQ cluster',
                    'description' => 'Cluster preview with safe generic assets.',
                    'pattern_type' => ProgrammaticPatternType::FAQ_LIBRARY->value,
                    'base_topic' => 'AI visibility reporting',
                    'variable_axis' => 'buyer question',
                    'status' => ProgrammaticCluster::STATUS_VALIDATED,
                    'estimated_assets_count' => 3,
                    'estimated_reach' => 5400,
                    'estimated_ai_visibility' => 68,
                    'estimated_business_impact' => 74,
                    'confidence_score' => 80,
                    'metadata' => ['demo' => true],
                ],
            );

            $items = collect(['measurement', 'reporting', 'governance'])->map(function (string $topic) use ($workspace, $cluster): ProgrammaticClusterItem {
                return ProgrammaticClusterItem::query()->firstOrCreate(
                    [
                        'programmatic_cluster_id' => $cluster->id,
                        'variable_value' => $topic,
                        'asset_type' => 'article',
                    ],
                    [
                        'workspace_id' => $workspace->id,
                        'title' => Str::headline($topic).' FAQ for AI visibility teams',
                        'slug' => 'ai-visibility-'.$topic.'-faq',
                        'growth_asset_type' => GrowthAssetType::FAQ_PAGE->value,
                        'intent' => 'informational',
                        'priority_score' => 70,
                        'seo_score' => 65,
                        'ai_visibility_score' => 72,
                        'business_value_score' => 68,
                        'recommended_word_count_min' => 900,
                        'recommended_word_count_max' => 1300,
                        'recommended_schema_types' => ['FAQPage'],
                        'recommended_cta' => 'Compare reporting gaps in your workspace.',
                        'internal_linking_role' => 'supporting',
                        'briefing_requirements' => ['Answer practical buyer questions.'],
                        'ai_visibility_requirements' => ['Use concise answer blocks.'],
                        'seo_requirements' => ['Include FAQ schema.'],
                        'duplicate_risk_score' => 12,
                        'canonical_group_key' => 'demo-ai-visibility-'.$topic,
                        'status' => ProgrammaticClusterItem::STATUS_ACCEPTED,
                        'metadata' => ['demo' => true],
                    ],
                );
            });

            $blueprint = ProgrammaticBriefBlueprint::query()->firstOrCreate(
                ['programmatic_cluster_item_id' => $items->first()->id],
                [
                    'workspace_id' => $workspace->id,
                    'growth_program_id' => $program->id,
                    'programmatic_cluster_id' => $cluster->id,
                    'growth_asset_type' => GrowthAssetType::FAQ_PAGE->value,
                    'title' => 'How should teams measure AI visibility?',
                    'slug' => 'measure-ai-visibility',
                    'intent' => 'informational',
                    'audience' => 'B2B marketing and content teams',
                    'primary_keyword' => 'measure AI visibility',
                    'secondary_keywords' => ['AI visibility reporting', 'answer visibility'],
                    'outline' => ['Definition', 'Metrics', 'Governance checklist'],
                    'required_sections' => ['What to measure', 'How to review evidence'],
                    'faq_questions' => ['What is AI visibility?', 'Which metrics matter first?'],
                    'schema_recommendations' => ['FAQPage'],
                    'internal_linking_plan' => ['Link to AI visibility dashboard guidance.'],
                    'cta_recommendation' => 'Review AI visibility opportunities.',
                    'seo_requirements' => ['Use descriptive H2s.'],
                    'ai_visibility_requirements' => ['Add direct answer blocks.'],
                    'quality_requirements' => ['Avoid claims without evidence.'],
                    'status' => ProgrammaticBriefBlueprint::STATUS_CONVERTED,
                    'metadata' => ['demo' => true],
                ],
            );

            $brief = Brief::query()->firstOrCreate(
                ['client_site_id' => $site->id, 'title' => 'How should teams measure AI visibility?'],
                [
                    'content_destination_id' => $destination->id,
                    'created_by_user_id' => $owner?->id,
                    'status' => 'ready',
                    'source' => 'programmatic_demo',
                    'language' => 'en',
                    'content_type' => 'article',
                    'intent' => 'informational',
                    'primary_keyword' => 'measure AI visibility',
                    'secondary_keywords' => ['AI visibility reporting'],
                    'audience' => 'B2B marketing and content teams',
                    'notes' => 'Safe demo brief created for internal beta testing.',
                    'client_refs' => ['programmatic_brief_blueprint_id' => (string) $blueprint->id, 'demo' => true],
                ],
            );

            $draftRequest = ProgrammaticDraftRequest::query()->firstOrCreate(
                ['brief_id' => $brief->id],
                [
                    'workspace_id' => $workspace->id,
                    'growth_program_id' => $program->id,
                    'programmatic_brief_blueprint_id' => $blueprint->id,
                    'programmatic_cluster_id' => $cluster->id,
                    'programmatic_cluster_item_id' => $items->first()->id,
                    'growth_asset_type' => GrowthAssetType::FAQ_PAGE->value,
                    'title' => $brief->title,
                    'slug' => 'measure-ai-visibility',
                    'priority_score' => 70,
                    'estimated_cost' => 0.08,
                    'estimated_tokens' => 2200,
                    'status' => ProgrammaticDraftRequest::STATUS_GENERATED,
                    'generation_mode' => ProgrammaticDraftRequest::MODE_MANUAL,
                    'metadata' => ['demo' => true, 'requires_manual_approval' => true],
                ],
            );

            $draft = Draft::query()->firstOrCreate(
                ['brief_id' => $brief->id, 'title' => $brief->title],
                [
                    'client_site_id' => $site->id,
                    'content_destination_id' => $destination->id,
                    'status' => Draft::STATUS_READY_FOR_REVIEW,
                    'language' => 'en',
                    'content_html' => '<h2>What to measure</h2><p>Track answer visibility, citation quality, and evidence freshness.</p>',
                    'meta' => ['demo' => true, 'programmatic_draft_request_id' => (string) $draftRequest->id],
                ],
            );

            $draftRequest->forceFill([
                'metadata' => array_merge($draftRequest->metadata ?? [], ['generated_draft_id' => (string) $draft->id]),
            ])->save();

            $review = ProgrammaticDraftReview::query()->firstOrCreate(
                ['programmatic_draft_request_id' => $draftRequest->id],
                [
                    'workspace_id' => $workspace->id,
                    'growth_program_id' => $program->id,
                    'draft_id' => $draft->id,
                    'brief_id' => $brief->id,
                    'programmatic_cluster_id' => $cluster->id,
                    'programmatic_cluster_item_id' => $items->first()->id,
                    'growth_asset_type' => GrowthAssetType::FAQ_PAGE->value,
                    'status' => ProgrammaticDraftReview::STATUS_APPROVED,
                    'overall_score' => 84,
                    'seo_score' => 78,
                    'ai_visibility_score' => 82,
                    'duplication_score' => 10,
                    'brand_fit_score' => 80,
                    'completeness_score' => 86,
                    'schema_readiness_score' => 76,
                    'internal_linking_score' => 72,
                    'risk_score' => 14,
                    'checks' => ['safe_demo_content' => true],
                    'recommendations' => ['Keep final review manual.'],
                    'blocking_issues' => [],
                    'reviewer_id' => $owner?->id,
                    'reviewed_at' => now()->subDay(),
                    'metadata' => ['demo' => true],
                ],
            );

            $content = Content::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'title' => $brief->title],
                [
                    'client_site_id' => $site->id,
                    'content_destination_id' => $destination->id,
                    'status' => 'review',
                    'publish_status' => 'draft',
                    'language' => 'en',
                    'content_html' => $draft->content_html,
                    'meta' => ['demo' => true, 'programmatic_draft_review_id' => (string) $review->id],
                ],
            );

            $readiness = ProgrammaticPublicationReadiness::query()->firstOrCreate(
                ['content_id' => $content->id],
                [
                    'workspace_id' => $workspace->id,
                    'growth_program_id' => $program->id,
                    'programmatic_draft_review_id' => $review->id,
                    'programmatic_draft_request_id' => $draftRequest->id,
                    'programmatic_cluster_id' => $cluster->id,
                    'programmatic_cluster_item_id' => $items->first()->id,
                    'growth_asset_type' => GrowthAssetType::FAQ_PAGE->value,
                    'status' => ProgrammaticPublicationReadiness::STATUS_APPROVED,
                    'readiness_score' => 86,
                    'seo_score' => 80,
                    'schema_score' => 78,
                    'internal_linking_score' => 74,
                    'publication_risk_score' => 12,
                    'destination_readiness_score' => 90,
                    'checks' => ['destination_configured' => true, 'manual_publish_required' => true],
                    'missing_requirements' => [],
                    'recommendations' => ['Confirm final publish date manually.'],
                    'approved_by' => $owner?->id,
                    'approved_at' => now(),
                    'metadata' => ['demo' => true, 'publishing_disabled' => true],
                ],
            );

            $plan = ProgrammaticPublicationPlan::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => 'Demo Programmatic Publication Plan'],
                [
                    'growth_program_id' => $program->id,
                    'description' => 'Safe publication plan for beta testing. Creates no live publish.',
                    'status' => ProgrammaticPublicationPlan::STATUS_APPROVED,
                    'planned_start_at' => now()->addDays(3),
                    'planned_end_at' => now()->addDays(3),
                    'cadence' => 'manual',
                    'destination_id' => $destination->id,
                    'total_items' => 1,
                    'approved_items' => 1,
                    'scheduled_items' => 1,
                    'published_items' => 0,
                    'metadata' => ['demo' => true, 'publishing_disabled' => true],
                ],
            );

            $publication = ContentPublication::query()->firstOrCreate(
                ['content_id' => $content->id, 'destination_id' => $destination->id],
                [
                    'client_site_id' => $site->id,
                    'provider' => ContentPublication::PROVIDER_API,
                    'remote_type' => 'article',
                    'remote_status' => ContentPublication::REMOTE_SCHEDULED,
                    'delivery_status' => ContentPublication::STATUS_PENDING,
                    'scheduled_publish_at' => now()->addDays(3),
                    'meta' => ['demo' => true, 'not_live_published' => true],
                ],
            );

            ProgrammaticPublicationPlanItem::query()->firstOrCreate(
                [
                    'programmatic_publication_plan_id' => $plan->id,
                    'content_id' => $content->id,
                ],
                [
                    'workspace_id' => $workspace->id,
                    'publication_readiness_id' => $readiness->id,
                    'content_publication_id' => $publication->id,
                    'growth_asset_type' => GrowthAssetType::FAQ_PAGE->value,
                    'title' => $content->title,
                    'slug' => 'measure-ai-visibility',
                    'destination_id' => $destination->id,
                    'planned_publish_at' => now()->addDays(3),
                    'status' => ProgrammaticPublicationPlanItem::STATUS_SCHEDULED,
                    'priority_score' => 70,
                    'publication_risk_score' => 12,
                    'metadata' => ['demo' => true],
                ],
            );

            $orchestrator = app(GrowthProgramOrchestrator::class);
            foreach ([$opportunity, $cluster, $blueprint, $brief, $draftRequest, $draft, $review, $content, $readiness, $plan, $publication] as $asset) {
                $orchestrator->linkAsset($program, $asset);
            }

            return $orchestrator->refreshMetrics($program->refresh());
        });
    }
}
