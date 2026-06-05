<?php

namespace Database\Seeders;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonScore;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DemoDraftComparisonSeeder extends Seeder
{
    private bool $draftComparisonLinkColumnsAvailable = false;

    private bool $scoreSourceTypeColumnAvailable = false;

    /**
     * @var array<string,array{label:string,group:string,source_type:string}>
     */
    private const METRIC_DEFINITIONS = [
        'word_count' => ['label' => 'Word Count', 'group' => 'content', 'source_type' => 'derived'],
        'reading_time' => ['label' => 'Reading Time', 'group' => 'content', 'source_type' => 'derived'],
        'seo_score' => ['label' => 'SEO Score', 'group' => 'seo', 'source_type' => 'heuristic'],
        'ai_seo_score' => ['label' => 'AI SEO Score', 'group' => 'seo', 'source_type' => 'heuristic'],
        'readability_score' => ['label' => 'Readability Score', 'group' => 'content', 'source_type' => 'heuristic'],
        'brand_voice_match' => ['label' => 'Brand Voice Match', 'group' => 'brand', 'source_type' => 'heuristic'],
        'cta_strength' => ['label' => 'CTA Strength', 'group' => 'conversion', 'source_type' => 'heuristic'],
        'structure_quality' => ['label' => 'Structure Quality', 'group' => 'content', 'source_type' => 'heuristic'],
        'topical_coverage' => ['label' => 'Topical Coverage', 'group' => 'seo', 'source_type' => 'heuristic'],
        'entity_coverage' => ['label' => 'Entity Coverage', 'group' => 'quality', 'source_type' => 'heuristic'],
        'factual_confidence' => ['label' => 'Factual Confidence', 'group' => 'quality', 'source_type' => 'heuristic'],
        'conversion_focus' => ['label' => 'Conversion Focus', 'group' => 'conversion', 'source_type' => 'heuristic'],
    ];

    public function run(): void
    {
        if (
            ! Schema::hasTable('draft_comparisons')
            || ! Schema::hasTable('draft_comparison_items')
            || ! Schema::hasTable('draft_comparison_variants')
            || ! Schema::hasTable('draft_comparison_scores')
        ) {
            return;
        }

        if (
            ! Schema::hasColumn('draft_comparisons', 'requested_models_json')
            || ! Schema::hasColumn('draft_comparison_variants', 'provider_key')
            || ! Schema::hasColumn('draft_comparison_scores', 'metric_key')
        ) {
            return;
        }

        $this->draftComparisonLinkColumnsAvailable = Schema::hasColumn('drafts', 'draft_comparison_id')
            && Schema::hasColumn('drafts', 'draft_comparison_variant_id');
        $this->scoreSourceTypeColumnAvailable = Schema::hasColumn('draft_comparison_scores', 'source_type');

        $organization = Organization::query()->where('slug', 'demo-org')->first();
        if (! $organization) {
            return;
        }

        $workspace = Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->first();
        if (! $workspace) {
            return;
        }

        $site = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->first();
        if (! $site) {
            return;
        }

        $author = User::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->first();
        if (! $author) {
            return;
        }

        $sampleOneTime = now()->subDays(3)->setTime(9, 30);
        $sampleTwoTime = now()->subDays(2)->setTime(11, 0);
        $sampleThreeTime = now()->subDay()->setTime(14, 45);
        $sampleFourTime = now()->subHours(6)->setTime(16, 15);

        $this->seedSampleOneCompletedTwoModels($workspace, $site, $author, $sampleOneTime);
        $this->seedSampleTwoPartiallyFailed($workspace, $site, $author, $sampleTwoTime);
        $this->seedSampleThreeCompletedMultiWithHybrid($workspace, $site, $author, $sampleThreeTime);
        $this->seedSampleFourFailedHybrid($workspace, $site, $author, $sampleFourTime);
    }

    private function seedSampleOneCompletedTwoModels(
        Workspace $workspace,
        ClientSite $site,
        User $author,
        \Illuminate\Support\Carbon $startedAt,
    ): void {
        $content = $this->upsertContent(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5711001',
            workspace: $workspace,
            site: $site,
            author: $author,
            externalKey: 'demo-draft-compare-sample-1',
            title: 'Draft Compare Demo 1: AI Governance Foundations',
            primaryKeyword: 'AI content governance framework',
        );

        $brief = $this->upsertBrief(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5711002',
            site: $site,
            author: $author,
            content: $content,
            title: 'Compare AI Drafts: Governance Foundations',
            primaryKeyword: 'AI content governance framework',
            status: 'done',
        );

        $completedAt = $startedAt->copy()->addMinutes(27);
        $comparison = DraftComparison::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5711003'],
            [
                'workspace_id' => $workspace->id,
                'brief_id' => $brief->id,
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'created_by_user_id' => $author->id,
                'mode' => 'compare_two',
                'title' => 'Demo Compare 1 - Completed (2 successful)',
                'status' => DraftComparison::STATUS_COMPLETED,
                'requested_models_json' => [
                    ['key' => 'openai:gpt-4.1-mini', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'label' => 'OpenAI - gpt-4.1-mini'],
                    ['key' => 'anthropic:claude-3-5-sonnet-latest', 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest', 'label' => 'Anthropic - claude-3-5-sonnet-latest'],
                ],
                'requested_model_count' => 2,
                'estimated_input_tokens' => 900,
                'estimated_output_tokens' => 3100,
                'estimated_credit_cost' => 26,
                'reserved_credit_amount' => 26,
                'final_credit_cost' => 25,
                'estimated_credits' => 26,
                'credits_used' => 25,
                'items_total' => 2,
                'items_done' => 2,
                'items_failed' => 0,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'failed_at' => null,
                'created_at' => $startedAt,
                'updated_at' => $completedAt,
                'comparison_summary_json' => [
                    'seeded' => true,
                    'sample' => 'sample_1_completed_two_successful_models',
                ],
                'meta' => [
                    'seeded' => true,
                    'generation_type' => 'article',
                    'scoring_enabled' => true,
                ],
            ]
        );

        $draftA = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5711004',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5711006',
            title: 'Governance Foundations - OpenAI',
            html: '<h1>AI Content Governance Foundations</h1><h2>Policy and workflow</h2><p>Define policy guardrails, editorial ownership, and review gates before scaling AI-assisted content operations.</p><h2>Execution model</h2><p>Use a measurable workflow with clear handoffs and CTA to request governance rollout support.</p>',
            provider: 'openai',
            model: 'gpt-4.1-mini',
            creditCost: 12,
            generatedAt: $startedAt->copy()->addMinutes(18),
        );

        $draftB = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5711005',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5711007',
            title: 'Governance Foundations - Anthropic',
            html: '<h1>Build AI Governance for B2B Content Teams</h1><h2>Control layer</h2><p>Align brand voice controls, legal review, and taxonomy standards to reduce publishing risk.</p><h2>Adoption layer</h2><p>Map ownership by team and add a practical CTA for implementation planning.</p>',
            provider: 'anthropic',
            model: 'claude-3-5-sonnet-latest',
            creditCost: 13,
            generatedAt: $startedAt->copy()->addMinutes(24),
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5711006'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'openai',
                'model_key' => 'gpt-4.1-mini',
                'display_name' => 'OpenAI - gpt-4.1-mini',
                'sort_order' => 1,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftA->id,
                'input_tokens' => 420,
                'output_tokens' => 1500,
                'credit_cost' => 12,
                'latency_ms' => 6400,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(1),
                'completed_at' => $startedAt->copy()->addMinutes(18),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(18),
            ]
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5711007'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'anthropic',
                'model_key' => 'claude-3-5-sonnet-latest',
                'display_name' => 'Anthropic - claude-3-5-sonnet-latest',
                'sort_order' => 2,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftB->id,
                'input_tokens' => 480,
                'output_tokens' => 1580,
                'credit_cost' => 13,
                'latency_ms' => 7200,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(2),
                'completed_at' => $startedAt->copy()->addMinutes(24),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(24),
            ]
        );

        $this->syncDraftVariantLink($draftA, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5711006');
        $this->syncDraftVariantLink($draftB, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5711007');

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5711008',
            comparison: $comparison,
            draft: $draftA,
            provider: 'openai',
            model: 'gpt-4.1-mini',
            sortOrder: 1,
            status: 'generated',
            creditCost: 12,
            chargedCredits: 12,
            inputTokens: 420,
            outputTokens: 1500,
            startedAt: $startedAt->copy()->addMinutes(1),
            completedAt: $startedAt->copy()->addMinutes(18),
        );

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5711009',
            comparison: $comparison,
            draft: $draftB,
            provider: 'anthropic',
            model: 'claude-3-5-sonnet-latest',
            sortOrder: 2,
            status: 'generated',
            creditCost: 13,
            chargedCredits: 13,
            inputTokens: 480,
            outputTokens: 1580,
            startedAt: $startedAt->copy()->addMinutes(2),
            completedAt: $startedAt->copy()->addMinutes(24),
        );

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5711006', [
            'word_count' => 1320,
            'reading_time' => 6,
            'seo_score' => 89,
            'ai_seo_score' => 84,
            'readability_score' => 74,
            'brand_voice_match' => 86,
            'cta_strength' => 78,
            'structure_quality' => 82,
            'topical_coverage' => 85,
            'entity_coverage' => 71,
            'factual_confidence' => 80,
            'conversion_focus' => 76,
        ], 'Sample 1 OpenAI');

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5711007', [
            'word_count' => 1250,
            'reading_time' => 6,
            'seo_score' => 83,
            'ai_seo_score' => 81,
            'readability_score' => 77,
            'brand_voice_match' => 79,
            'cta_strength' => 84,
            'structure_quality' => 79,
            'topical_coverage' => 80,
            'entity_coverage' => 68,
            'factual_confidence' => 77,
            'conversion_focus' => 82,
        ], 'Sample 1 Anthropic');

        $comparison->winner_draft_id = $draftA->id;
        $comparison->save();
    }

    private function seedSampleTwoPartiallyFailed(
        Workspace $workspace,
        ClientSite $site,
        User $author,
        \Illuminate\Support\Carbon $startedAt,
    ): void {
        $content = $this->upsertContent(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5712001',
            workspace: $workspace,
            site: $site,
            author: $author,
            externalKey: 'demo-draft-compare-sample-2',
            title: 'Draft Compare Demo 2: Partial Provider Failure',
            primaryKeyword: 'provider failure fallback strategy',
        );

        $brief = $this->upsertBrief(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5712002',
            site: $site,
            author: $author,
            content: $content,
            title: 'Compare AI Drafts: Partial Failure Scenario',
            primaryKeyword: 'provider failure fallback strategy',
            status: 'done',
        );

        $comparison = DraftComparison::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5712003'],
            [
                'workspace_id' => $workspace->id,
                'brief_id' => $brief->id,
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'created_by_user_id' => $author->id,
                'mode' => 'compare_two',
                'title' => 'Demo Compare 2 - Partially Failed (1 success, 1 failed)',
                'status' => DraftComparison::STATUS_PARTIALLY_FAILED,
                'requested_models_json' => [
                    ['key' => 'openai:gpt-4.1-mini', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'label' => 'OpenAI - gpt-4.1-mini'],
                    ['key' => 'anthropic:claude-3-5-sonnet-latest', 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest', 'label' => 'Anthropic - claude-3-5-sonnet-latest'],
                ],
                'requested_model_count' => 2,
                'estimated_input_tokens' => 760,
                'estimated_output_tokens' => 2800,
                'estimated_credit_cost' => 22,
                'reserved_credit_amount' => 22,
                'final_credit_cost' => 10,
                'estimated_credits' => 22,
                'credits_used' => 10,
                'items_total' => 2,
                'items_done' => 1,
                'items_failed' => 1,
                'started_at' => $startedAt,
                'completed_at' => null,
                'failed_at' => null,
                'last_error' => 'Anthropic request timed out after retries.',
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(34),
                'comparison_summary_json' => [
                    'seeded' => true,
                    'sample' => 'sample_2_partially_failed_one_success_one_failure',
                ],
                'meta' => [
                    'seeded' => true,
                    'generation_type' => 'article',
                    'scoring_enabled' => true,
                ],
            ]
        );

        $draftA = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5712004',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5712006',
            title: 'Fallback Strategy - OpenAI Success',
            html: '<h1>Fallback Strategy for LLM Provider Failures</h1><h2>Detect and isolate failure</h2><p>Route failures into retry windows and fallback model pools before user-facing deadlines are missed.</p><h2>Operational response</h2><p>Track failed attempts and keep successful outputs editable for review and publishing.</p>',
            provider: 'openai',
            model: 'gpt-4.1-mini',
            creditCost: 10,
            generatedAt: $startedAt->copy()->addMinutes(17),
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5712006'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'openai',
                'model_key' => 'gpt-4.1-mini',
                'display_name' => 'OpenAI - gpt-4.1-mini',
                'sort_order' => 1,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftA->id,
                'input_tokens' => 380,
                'output_tokens' => 1220,
                'credit_cost' => 10,
                'latency_ms' => 5100,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(1),
                'completed_at' => $startedAt->copy()->addMinutes(17),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(17),
            ]
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5712007'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'anthropic',
                'model_key' => 'claude-3-5-sonnet-latest',
                'display_name' => 'Anthropic - claude-3-5-sonnet-latest',
                'sort_order' => 2,
                'status' => DraftComparisonVariant::STATUS_FAILED,
                'draft_id' => null,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'credit_cost' => 0,
                'latency_ms' => 0,
                'error_message' => 'Provider timed out while generating response.',
                'started_at' => $startedAt->copy()->addMinutes(2),
                'completed_at' => $startedAt->copy()->addMinutes(34),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(34),
            ]
        );

        $this->syncDraftVariantLink($draftA, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5712006');

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5712008',
            comparison: $comparison,
            draft: $draftA,
            provider: 'openai',
            model: 'gpt-4.1-mini',
            sortOrder: 1,
            status: 'generated',
            creditCost: 10,
            chargedCredits: 10,
            inputTokens: 380,
            outputTokens: 1220,
            startedAt: $startedAt->copy()->addMinutes(1),
            completedAt: $startedAt->copy()->addMinutes(17),
        );

        DraftComparisonItem::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5712009'],
            [
                'draft_comparison_id' => $comparison->id,
                'draft_id' => null,
                'sort_order' => 2,
                'provider' => 'anthropic',
                'model' => 'claude-3-5-sonnet-latest',
                'status' => 'failed',
                'credit_cost' => 12,
                'charged_credits' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'generation_started_at' => $startedAt->copy()->addMinutes(2),
                'generation_completed_at' => $startedAt->copy()->addMinutes(34),
                'error_message' => 'Provider timed out while generating response.',
                'metrics' => null,
                'meta' => ['seeded' => true, 'sample' => 'sample_2'],
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(34),
            ]
        );

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5712006', [
            'word_count' => 980,
            'reading_time' => 5,
            'seo_score' => 76,
            'ai_seo_score' => 70,
            'readability_score' => 73,
            'brand_voice_match' => 81,
            'cta_strength' => 68,
            'structure_quality' => 74,
            'topical_coverage' => 72,
            'entity_coverage' => 63,
            'factual_confidence' => 71,
            'conversion_focus' => 69,
        ], 'Sample 2 OpenAI');
    }

    private function seedSampleThreeCompletedMultiWithHybrid(
        Workspace $workspace,
        ClientSite $site,
        User $author,
        \Illuminate\Support\Carbon $startedAt,
    ): void {
        $content = $this->upsertContent(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713001',
            workspace: $workspace,
            site: $site,
            author: $author,
            externalKey: 'demo-draft-compare-sample-3',
            title: 'Draft Compare Demo 3: Multi-model With Hybrid',
            primaryKeyword: 'hybrid draft synthesis workflow',
        );

        $brief = $this->upsertBrief(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713002',
            site: $site,
            author: $author,
            content: $content,
            title: 'Compare AI Drafts: Multi-model + Hybrid',
            primaryKeyword: 'hybrid draft synthesis workflow',
            status: 'done',
        );

        $completedAt = $startedAt->copy()->addMinutes(46);
        $comparison = DraftComparison::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5713003'],
            [
                'workspace_id' => $workspace->id,
                'brief_id' => $brief->id,
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'created_by_user_id' => $author->id,
                'mode' => 'compare_multi',
                'title' => 'Demo Compare 3 - Completed (multi + hybrid)',
                'status' => DraftComparison::STATUS_COMPLETED,
                'requested_models_json' => [
                    ['key' => 'openai:gpt-4.1-mini', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'label' => 'OpenAI - gpt-4.1-mini'],
                    ['key' => 'anthropic:claude-3-5-sonnet-latest', 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest', 'label' => 'Anthropic - claude-3-5-sonnet-latest'],
                    ['key' => 'openrouter:meta-llama/llama-3.1-70b-instruct', 'provider' => 'openrouter', 'model' => 'meta-llama/llama-3.1-70b-instruct', 'label' => 'OpenRouter - llama-3.1-70b'],
                ],
                'requested_model_count' => 3,
                'estimated_input_tokens' => 1350,
                'estimated_output_tokens' => 4900,
                'estimated_credit_cost' => 34,
                'reserved_credit_amount' => 34,
                'final_credit_cost' => 30,
                'estimated_credits' => 34,
                'credits_used' => 30,
                'items_total' => 3,
                'items_done' => 3,
                'items_failed' => 0,
                'hybrid_status' => 'generated',
                'hybrid_last_error' => null,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'failed_at' => null,
                'hybrid_started_at' => $completedAt->copy()->addMinutes(2),
                'hybrid_completed_at' => $completedAt->copy()->addMinutes(9),
                'created_at' => $startedAt,
                'updated_at' => $completedAt->copy()->addMinutes(9),
                'comparison_summary_json' => [
                    'seeded' => true,
                    'sample' => 'sample_3_completed_multi_with_hybrid',
                    'hybrid' => [
                        'status' => 'generated',
                        'source_variant_count' => 3,
                    ],
                ],
                'meta' => [
                    'seeded' => true,
                    'generation_type' => 'article',
                    'scoring_enabled' => true,
                    'hybrid_enabled' => true,
                ],
            ]
        );

        $draftA = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713004',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5713007',
            title: 'Hybrid Workflow - OpenAI',
            html: '<h1>Hybrid Draft Workflow for Editorial Teams</h1><h2>Comparison phase</h2><p>Generate multiple drafts from one brief and score each model output against consistent criteria.</p><h2>Synthesis phase</h2><p>Select strengths and create one actionable hybrid draft for editor review.</p>',
            provider: 'openai',
            model: 'gpt-4.1-mini',
            creditCost: 10,
            generatedAt: $startedAt->copy()->addMinutes(22),
        );

        $draftB = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713005',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5713008',
            title: 'Hybrid Workflow - Anthropic',
            html: '<h1>Multi-model Draft Comparison and Selection</h1><h2>Scoring transparency</h2><p>Expose metric-by-metric explanations so users can trust recommendation logic.</p><h2>Editorial control</h2><p>Allow winner selection and hybrid generation without data loss.</p>',
            provider: 'anthropic',
            model: 'claude-3-5-sonnet-latest',
            creditCost: 10,
            generatedAt: $startedAt->copy()->addMinutes(30),
        );

        $draftC = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713006',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5713009',
            title: 'Hybrid Workflow - OpenRouter',
            html: '<h1>Draft Compare in Production Content Ops</h1><h2>Status orchestration</h2><p>Track pending, processing, completed, and failed variant states with idempotent jobs.</p><h2>Cost controls</h2><p>Reserve, settle, and refund credits safely across multi-model runs.</p>',
            provider: 'openrouter',
            model: 'meta-llama/llama-3.1-70b-instruct',
            creditCost: 10,
            generatedAt: $startedAt->copy()->addMinutes(39),
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5713007'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'openai',
                'model_key' => 'gpt-4.1-mini',
                'display_name' => 'OpenAI - gpt-4.1-mini',
                'sort_order' => 1,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftA->id,
                'input_tokens' => 430,
                'output_tokens' => 1380,
                'credit_cost' => 10,
                'latency_ms' => 5900,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(2),
                'completed_at' => $startedAt->copy()->addMinutes(22),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(22),
            ]
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5713008'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'anthropic',
                'model_key' => 'claude-3-5-sonnet-latest',
                'display_name' => 'Anthropic - claude-3-5-sonnet-latest',
                'sort_order' => 2,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftB->id,
                'input_tokens' => 465,
                'output_tokens' => 1460,
                'credit_cost' => 10,
                'latency_ms' => 6800,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(3),
                'completed_at' => $startedAt->copy()->addMinutes(30),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(30),
            ]
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5713009'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'openrouter',
                'model_key' => 'meta-llama/llama-3.1-70b-instruct',
                'display_name' => 'OpenRouter - llama-3.1-70b',
                'sort_order' => 3,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftC->id,
                'input_tokens' => 455,
                'output_tokens' => 1520,
                'credit_cost' => 10,
                'latency_ms' => 7300,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(4),
                'completed_at' => $startedAt->copy()->addMinutes(39),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(39),
            ]
        );

        $this->syncDraftVariantLink($draftA, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5713007');
        $this->syncDraftVariantLink($draftB, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5713008');
        $this->syncDraftVariantLink($draftC, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5713009');

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713010',
            comparison: $comparison,
            draft: $draftA,
            provider: 'openai',
            model: 'gpt-4.1-mini',
            sortOrder: 1,
            status: 'generated',
            creditCost: 10,
            chargedCredits: 10,
            inputTokens: 430,
            outputTokens: 1380,
            startedAt: $startedAt->copy()->addMinutes(2),
            completedAt: $startedAt->copy()->addMinutes(22),
        );

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713011',
            comparison: $comparison,
            draft: $draftB,
            provider: 'anthropic',
            model: 'claude-3-5-sonnet-latest',
            sortOrder: 2,
            status: 'generated',
            creditCost: 10,
            chargedCredits: 10,
            inputTokens: 465,
            outputTokens: 1460,
            startedAt: $startedAt->copy()->addMinutes(3),
            completedAt: $startedAt->copy()->addMinutes(30),
        );

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5713012',
            comparison: $comparison,
            draft: $draftC,
            provider: 'openrouter',
            model: 'meta-llama/llama-3.1-70b-instruct',
            sortOrder: 3,
            status: 'generated',
            creditCost: 10,
            chargedCredits: 10,
            inputTokens: 455,
            outputTokens: 1520,
            startedAt: $startedAt->copy()->addMinutes(4),
            completedAt: $startedAt->copy()->addMinutes(39),
        );

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5713007', [
            'word_count' => 1180,
            'reading_time' => 6,
            'seo_score' => 88,
            'ai_seo_score' => 86,
            'readability_score' => 75,
            'brand_voice_match' => 82,
            'cta_strength' => 80,
            'structure_quality' => 84,
            'topical_coverage' => 83,
            'entity_coverage' => 69,
            'factual_confidence' => 79,
            'conversion_focus' => 81,
        ], 'Sample 3 OpenAI');

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5713008', [
            'word_count' => 1260,
            'reading_time' => 6,
            'seo_score' => 84,
            'ai_seo_score' => 83,
            'readability_score' => 79,
            'brand_voice_match' => 90,
            'cta_strength' => 86,
            'structure_quality' => 80,
            'topical_coverage' => 78,
            'entity_coverage' => 72,
            'factual_confidence' => 82,
            'conversion_focus' => 85,
        ], 'Sample 3 Anthropic', ['ai_seo_score' => 'existing_signal']);

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5713009', [
            'word_count' => 1215,
            'reading_time' => 6,
            'seo_score' => 82,
            'ai_seo_score' => 79,
            'readability_score' => 76,
            'brand_voice_match' => 84,
            'cta_strength' => 88,
            'structure_quality' => 81,
            'topical_coverage' => 80,
            'entity_coverage' => 74,
            'factual_confidence' => 77,
            'conversion_focus' => 89,
        ], 'Sample 3 OpenRouter');

        $hybridDraftAttributes = [
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'status' => 'generated',
            'attempts' => 1,
            'title' => 'Hybrid Workflow - Final Synthesized Draft',
            'seo_title' => 'Hybrid Draft Workflow for Reliable Multi-model Content Delivery',
            'seo_meta_description' => 'Combine multi-model drafts into one trusted final article using transparent scoring, credit-safe orchestration, and editor controls.',
            'seo_h1' => 'Hybrid Draft Workflow for Reliable Multi-model Delivery',
            'seo_canonical' => 'https://publishlayer-demo.local/hybrid-draft-workflow',
            'output_type' => 'kb_article',
            'content_html' => '<h1>Hybrid Draft Workflow for Reliable Multi-model Delivery</h1><h2>Generate and compare</h2><p>Create multiple drafts from one brief, then compare model outputs with explainable scoring.</p><h2>Select and synthesize</h2><p>Use winner recommendations, generate a best-of-all hybrid, and continue editing in the normal draft flow.</p>',
            'meta' => [
                'generation_provider_override' => 'anthropic',
                'generation_model_override' => 'claude-3-5-sonnet-latest',
                'draft_compare' => [
                    'comparison_id' => (string) $comparison->id,
                    'is_hybrid' => true,
                    'provider' => 'anthropic',
                    'model' => 'claude-3-5-sonnet-latest',
                    'source_variant_ids' => [
                        '8a2901d2-6f95-4bb4-96fb-b80ea5713007',
                        '8a2901d2-6f95-4bb4-96fb-b80ea5713008',
                        '8a2901d2-6f95-4bb4-96fb-b80ea5713009',
                    ],
                    'source_draft_ids' => [
                        (string) $draftA->id,
                        (string) $draftB->id,
                        (string) $draftC->id,
                    ],
                ],
                'generation' => [
                    'provider' => 'anthropic',
                    'model' => 'claude-3-5-sonnet-latest',
                    'model_used' => 'claude-3-5-sonnet-latest',
                    'input_tokens' => 640,
                    'output_tokens' => 1660,
                    'tokens' => 2300,
                    'charged_credits' => 11,
                ],
            ],
            'credit_cost' => 11,
            'created_at' => $completedAt->copy()->addMinutes(2),
            'updated_at' => $completedAt->copy()->addMinutes(9),
        ];

        if ($this->draftComparisonLinkColumnsAvailable) {
            $hybridDraftAttributes['draft_comparison_id'] = $comparison->id;
            $hybridDraftAttributes['draft_comparison_variant_id'] = null;
        }

        $hybridDraft = Draft::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5713013'],
            $hybridDraftAttributes
        );

        $comparison->winner_draft_id = $draftB->id;
        $comparison->hybrid_draft_id = $hybridDraft->id;
        $comparison->save();
    }

    private function seedSampleFourFailedHybrid(
        Workspace $workspace,
        ClientSite $site,
        User $author,
        \Illuminate\Support\Carbon $startedAt,
    ): void {
        $content = $this->upsertContent(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5714001',
            workspace: $workspace,
            site: $site,
            author: $author,
            externalKey: 'demo-draft-compare-sample-4',
            title: 'Draft Compare Demo 4: Failed Hybrid Generation',
            primaryKeyword: 'hybrid generation failure recovery',
        );

        $brief = $this->upsertBrief(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5714002',
            site: $site,
            author: $author,
            content: $content,
            title: 'Compare AI Drafts: Failed Hybrid Scenario',
            primaryKeyword: 'hybrid generation failure recovery',
            status: 'done',
        );

        $completedAt = $startedAt->copy()->addMinutes(28);
        $comparison = DraftComparison::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5714003'],
            [
                'workspace_id' => $workspace->id,
                'brief_id' => $brief->id,
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'created_by_user_id' => $author->id,
                'mode' => 'compare_two',
                'title' => 'Demo Compare 4 - Failed Hybrid (can retry)',
                'status' => DraftComparison::STATUS_COMPLETED,
                'requested_models_json' => [
                    ['key' => 'openai:gpt-4.1-mini', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'label' => 'OpenAI - gpt-4.1-mini'],
                    ['key' => 'anthropic:claude-3-5-sonnet-latest', 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest', 'label' => 'Anthropic - claude-3-5-sonnet-latest'],
                ],
                'requested_model_count' => 2,
                'estimated_input_tokens' => 880,
                'estimated_output_tokens' => 3000,
                'estimated_credit_cost' => 24,
                'reserved_credit_amount' => 24,
                'final_credit_cost' => 22,
                'estimated_credits' => 24,
                'credits_used' => 22,
                'items_total' => 2,
                'items_done' => 2,
                'items_failed' => 0,
                'hybrid_status' => 'failed',
                'hybrid_last_error' => 'Provider rate limit exceeded during hybrid synthesis. Please retry in a few minutes.',
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'failed_at' => null,
                'hybrid_started_at' => $completedAt->copy()->addMinutes(3),
                'hybrid_completed_at' => $completedAt->copy()->addMinutes(7),
                'created_at' => $startedAt,
                'updated_at' => $completedAt->copy()->addMinutes(7),
                'comparison_summary_json' => [
                    'seeded' => true,
                    'sample' => 'sample_4_completed_with_failed_hybrid',
                    'hybrid' => [
                        'status' => 'failed',
                        'last_error' => 'Provider rate limit exceeded during hybrid synthesis.',
                        'failed_at' => $completedAt->copy()->addMinutes(7)->toIso8601String(),
                        'source_variant_count' => 2,
                    ],
                ],
                'meta' => [
                    'seeded' => true,
                    'generation_type' => 'article',
                    'scoring_enabled' => true,
                    'hybrid_enabled' => true,
                ],
            ]
        );

        $draftA = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5714004',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5714006',
            title: 'Failure Recovery - OpenAI',
            html: '<h1>Handling Hybrid Generation Failures</h1><h2>Detect synthesis errors</h2><p>Monitor provider response codes and timeout signals to surface clear failure messages.</p><h2>Recovery path</h2><p>Allow retry from the same comparison without regenerating successful variants.</p>',
            provider: 'openai',
            model: 'gpt-4.1-mini',
            creditCost: 11,
            generatedAt: $startedAt->copy()->addMinutes(18),
        );

        $draftB = $this->upsertVariantDraft(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5714005',
            brief: $brief,
            content: $content,
            site: $site,
            comparison: $comparison,
            variantId: '8a2901d2-6f95-4bb4-96fb-b80ea5714007',
            title: 'Failure Recovery - Anthropic',
            html: '<h1>Reliable Hybrid Draft Operations</h1><h2>Transient failure handling</h2><p>Implement backoff and retry logic for temporary provider issues during synthesis.</p><h2>User experience</h2><p>Show actionable error messages and enable one-click retry for failed hybrid requests.</p>',
            provider: 'anthropic',
            model: 'claude-3-5-sonnet-latest',
            creditCost: 11,
            generatedAt: $startedAt->copy()->addMinutes(24),
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5714006'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'openai',
                'model_key' => 'gpt-4.1-mini',
                'display_name' => 'OpenAI - gpt-4.1-mini',
                'sort_order' => 1,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftA->id,
                'input_tokens' => 410,
                'output_tokens' => 1450,
                'credit_cost' => 11,
                'latency_ms' => 6100,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(1),
                'completed_at' => $startedAt->copy()->addMinutes(18),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(18),
            ]
        );

        DraftComparisonVariant::query()->updateOrCreate(
            ['id' => '8a2901d2-6f95-4bb4-96fb-b80ea5714007'],
            [
                'draft_comparison_id' => $comparison->id,
                'provider_key' => 'anthropic',
                'model_key' => 'claude-3-5-sonnet-latest',
                'display_name' => 'Anthropic - claude-3-5-sonnet-latest',
                'sort_order' => 2,
                'status' => DraftComparisonVariant::STATUS_COMPLETED,
                'draft_id' => $draftB->id,
                'input_tokens' => 470,
                'output_tokens' => 1530,
                'credit_cost' => 11,
                'latency_ms' => 6900,
                'error_message' => null,
                'started_at' => $startedAt->copy()->addMinutes(2),
                'completed_at' => $startedAt->copy()->addMinutes(24),
                'created_at' => $startedAt,
                'updated_at' => $startedAt->copy()->addMinutes(24),
            ]
        );

        $this->syncDraftVariantLink($draftA, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5714006');
        $this->syncDraftVariantLink($draftB, $comparison, '8a2901d2-6f95-4bb4-96fb-b80ea5714007');

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5714008',
            comparison: $comparison,
            draft: $draftA,
            provider: 'openai',
            model: 'gpt-4.1-mini',
            sortOrder: 1,
            status: 'generated',
            creditCost: 11,
            chargedCredits: 11,
            inputTokens: 410,
            outputTokens: 1450,
            startedAt: $startedAt->copy()->addMinutes(1),
            completedAt: $startedAt->copy()->addMinutes(18),
        );

        $this->upsertComparisonItem(
            id: '8a2901d2-6f95-4bb4-96fb-b80ea5714009',
            comparison: $comparison,
            draft: $draftB,
            provider: 'anthropic',
            model: 'claude-3-5-sonnet-latest',
            sortOrder: 2,
            status: 'generated',
            creditCost: 11,
            chargedCredits: 11,
            inputTokens: 470,
            outputTokens: 1530,
            startedAt: $startedAt->copy()->addMinutes(2),
            completedAt: $startedAt->copy()->addMinutes(24),
        );

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5714006', [
            'word_count' => 1150,
            'reading_time' => 5,
            'seo_score' => 85,
            'ai_seo_score' => 82,
            'readability_score' => 76,
            'brand_voice_match' => 83,
            'cta_strength' => 79,
            'structure_quality' => 80,
            'topical_coverage' => 81,
            'entity_coverage' => 70,
            'factual_confidence' => 78,
            'conversion_focus' => 77,
        ], 'Sample 4 OpenAI');

        $this->upsertScores('8a2901d2-6f95-4bb4-96fb-b80ea5714007', [
            'word_count' => 1210,
            'reading_time' => 6,
            'seo_score' => 82,
            'ai_seo_score' => 80,
            'readability_score' => 78,
            'brand_voice_match' => 87,
            'cta_strength' => 83,
            'structure_quality' => 78,
            'topical_coverage' => 79,
            'entity_coverage' => 71,
            'factual_confidence' => 80,
            'conversion_focus' => 84,
        ], 'Sample 4 Anthropic');

        $comparison->winner_draft_id = $draftB->id;
        $comparison->save();
    }

    private function upsertContent(
        string $id,
        Workspace $workspace,
        ClientSite $site,
        User $author,
        string $externalKey,
        string $title,
        string $primaryKeyword,
    ): Content {
        return Content::query()->updateOrCreate(
            ['id' => $id],
            [
                'workspace_id' => $workspace->id,
                'client_site_id' => $site->id,
                'title' => $title,
                'primary_keyword' => $primaryKeyword,
                'type' => 'article',
                'status' => 'draft',
                'source' => 'manual',
                'external_key' => $externalKey,
                'delivery_status' => 'pending',
                'generation_mode' => 'balanced',
                'preferred_length' => 'long',
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]
        );
    }

    private function upsertBrief(
        string $id,
        ClientSite $site,
        User $author,
        Content $content,
        string $title,
        string $primaryKeyword,
        string $status,
    ): Brief {
        return Brief::query()->updateOrCreate(
            ['id' => $id],
            [
                'client_site_id' => $site->id,
                'created_by_user_id' => $author->id,
                'content_id' => $content->id,
                'status' => $status,
                'source' => 'client_ui',
                'progress' => 1,
                'title' => $title,
                'language' => 'en',
                'content_type' => 'blog',
                'output_type' => 'kb_article',
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => [
                    'draft compare',
                    'llm comparison',
                    'hybrid draft',
                ],
                'target_audience' => 'Content marketers and SEO teams',
                'funnel_stage' => 'consideration',
                'search_intent' => 'informational',
                'tone_of_voice' => 'clear, practical, product-focused',
                'key_points' => [
                    'multi-model generation',
                    'transparent scoring',
                    'credit-safe orchestration',
                ],
                'call_to_action' => 'Request a demo of the Draft Compare workflow.',
                'desired_length_min' => 1100,
                'desired_length_max' => 1500,
                'client_refs' => [
                    'seeded' => true,
                    'seed_group' => 'draft_compare_demo',
                ],
            ]
        );
    }

    private function upsertVariantDraft(
        string $id,
        Brief $brief,
        Content $content,
        ClientSite $site,
        DraftComparison $comparison,
        string $variantId,
        string $title,
        string $html,
        string $provider,
        string $model,
        int $creditCost,
        \Illuminate\Support\Carbon $generatedAt,
    ): Draft {
        $attributes = [
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'status' => 'generated',
            'attempts' => 1,
            'title' => $title,
            'seo_title' => $title,
            'seo_meta_description' => 'Seeded demo draft from comparison variant.',
            'seo_h1' => $title,
            'seo_canonical' => 'https://publishlayer-demo.local/' . str($title)->slug('-'),
            'output_type' => 'kb_article',
            'content_html' => $html,
            'meta' => [
                'language' => 'en',
                'primary_keyword' => $brief->primary_keyword,
                'secondary_keywords' => (array) $brief->secondary_keywords,
                'generation_provider_override' => $provider,
                'generation_model_override' => $model,
                'draft_compare' => [
                    'comparison_id' => (string) $comparison->id,
                    'variant_id' => $variantId,
                    'provider' => $provider,
                    'model' => $model,
                    'is_hybrid' => false,
                ],
                'generation' => [
                    'provider' => $provider,
                    'model' => $model,
                    'model_used' => $model,
                    'input_tokens' => $this->seededTokenCount($provider . ':' . $model, 340, 520),
                    'output_tokens' => $this->seededTokenCount($provider . ':' . $model . ':output', 1180, 1680),
                    'tokens' => $this->seededTokenCount($provider . ':' . $model . ':total', 1650, 2200),
                    'charged_credits' => $creditCost,
                ],
            ],
            'credit_cost' => $creditCost,
            'created_at' => $generatedAt->copy()->subMinutes(2),
            'updated_at' => $generatedAt,
        ];

        if ($this->draftComparisonLinkColumnsAvailable) {
            $attributes['draft_comparison_id'] = $comparison->id;
            $attributes['draft_comparison_variant_id'] = null;
        }

        return Draft::query()->updateOrCreate(
            ['id' => $id],
            $attributes
        );
    }

    private function syncDraftVariantLink(Draft $draft, DraftComparison $comparison, string $variantId): void
    {
        if (! $this->draftComparisonLinkColumnsAvailable) {
            return;
        }

        if ((string) $draft->draft_comparison_id === (string) $comparison->id
            && (string) $draft->draft_comparison_variant_id === $variantId) {
            return;
        }

        $draft->draft_comparison_id = (string) $comparison->id;
        $draft->draft_comparison_variant_id = $variantId;
        $draft->save();
    }

    private function seededTokenCount(string $seed, int $min, int $max): int
    {
        $spread = max(0, $max - $min);
        if ($spread === 0) {
            return $min;
        }

        $offset = abs(crc32($seed)) % ($spread + 1);

        return $min + $offset;
    }

    private function upsertComparisonItem(
        string $id,
        DraftComparison $comparison,
        Draft $draft,
        string $provider,
        string $model,
        int $sortOrder,
        string $status,
        int $creditCost,
        int $chargedCredits,
        int $inputTokens,
        int $outputTokens,
        \Illuminate\Support\Carbon $startedAt,
        \Illuminate\Support\Carbon $completedAt,
    ): void {
        DraftComparisonItem::query()->updateOrCreate(
            ['id' => $id],
            [
                'draft_comparison_id' => $comparison->id,
                'draft_id' => $draft->id,
                'sort_order' => $sortOrder,
                'provider' => $provider,
                'model' => $model,
                'status' => $status,
                'credit_cost' => $creditCost,
                'charged_credits' => $chargedCredits,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'generation_started_at' => $startedAt,
                'generation_completed_at' => $completedAt,
                'error_message' => null,
                'metrics' => null,
                'meta' => [
                    'seeded' => true,
                    'seed_group' => 'draft_compare_demo',
                ],
                'created_at' => $startedAt,
                'updated_at' => $completedAt,
            ]
        );
    }

    /**
     * @param array<string,int|float> $metricValues
     * @param array<string,string> $sourceTypeOverrides
     */
    private function upsertScores(
        string $variantId,
        array $metricValues,
        string $labelPrefix,
        array $sourceTypeOverrides = [],
    ): void {
        foreach (self::METRIC_DEFINITIONS as $metricKey => $definition) {
            if (! array_key_exists($metricKey, $metricValues)) {
                continue;
            }

            $value = $metricValues[$metricKey];

            DraftComparisonScore::query()->updateOrCreate(
                [
                    'draft_comparison_variant_id' => $variantId,
                    'metric_key' => $metricKey,
                ],
                array_filter([
                    'metric_label' => $definition['label'],
                    'metric_group' => $definition['group'],
                    'source_type' => $this->scoreSourceTypeColumnAvailable
                        ? ($sourceTypeOverrides[$metricKey] ?? $definition['source_type'])
                        : null,
                    'numeric_score' => round((float) $value, 3),
                    'text_score' => null,
                    'explanation' => sprintf('%s metric %s scored %s.', $labelPrefix, $metricKey, (string) $value),
                ], static fn (mixed $value): bool => $value !== null)
            );
        }
    }
}
