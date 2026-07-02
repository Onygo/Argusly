<?php

use App\Actions\Briefs\UpdateBriefAction;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Briefs\BriefPromptBuilder;
use App\Services\OpportunityIntelligence\ExecutionPlanBriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function executionPlanBriefEnrichmentContext(string $slug = 'enrichment'): array
{
    $organization = Organization::query()->create([
        'name' => 'Brief Enrichment '.$slug,
        'slug' => 'brief-enrichment-'.$slug.'-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Brief Enrichment Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Brief Enrichment Site',
        'site_url' => 'https://brief-enrichment.example.com',
        'allowed_domains' => ['brief-enrichment.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $signal = OpportunitySignal::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'source' => OpportunitySignalSource::AI_CITATION_TRACKING->value,
        'category' => OpportunityCategory::BRAND_VISIBILITY->value,
        'topic' => 'AI SEO tools',
        'entity' => 'ChatGPT',
        'signal_strength' => 88,
        'confidence' => 84,
        'observed_at' => now(),
        'metrics' => ['ai_visibility_score' => 42],
        'evidence' => [
            'summary' => 'Brands are missing from AI answers for AI SEO tools.',
            'evidence_summary' => ['missing_mentions' => ['ChatGPT', 'Gemini']],
        ],
        'metadata' => [
            'signal_detection_id' => (string) Str::uuid(),
            'keywords' => ['AI search visibility', 'Generative Engine Optimization'],
            'linked_signal_event_ids' => [(string) Str::uuid()],
        ],
        'dedupe_hash' => hash('sha256', 'signal-'.$slug.Str::random(8)),
    ]);

    $opportunity = Opportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::BRAND_VISIBILITY->value,
        'status' => 'approved',
        'title' => 'Brand visibility gap for AI SEO tools',
        'topic' => 'AI SEO tools',
        'summary' => 'Argusly is underrepresented in ChatGPT and Gemini answers for AI SEO tools.',
        'priority_score' => 91,
        'confidence_score' => 84,
        'impact_score' => 88,
        'urgency_score' => 76,
        'effort_score' => 54,
        'score_breakdown' => ['ai_visibility_gap' => 88],
        'recommended_actions' => ['Create comparison content', 'Add entity-led answer blocks'],
        'evidence' => [
            'keywords' => ['LLM Optimization', 'Answer Engine Optimization'],
            'entities' => ['OpenAI', 'Gemini'],
        ],
        'source_signal_summary' => ['signal_count' => 1],
        'metadata' => [
            'secondary_keywords' => ['AI search visibility', 'Semantic SEO'],
            'entities' => ['Claude'],
        ],
        'dedupe_hash' => hash('sha256', 'opportunity-'.$slug.Str::random(8)),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $opportunity->signals()->attach($signal->id, [
        'id' => (string) Str::uuid(),
        'weight' => 1,
        'contribution' => json_encode(['score' => 88]),
    ]);

    Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI Visibility Knowledge Hub',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'primary_keyword' => 'AI Visibility',
        'publish_url_key' => 'ai-visibility-knowledge-hub',
    ]);

    $plan = OpportunityExecutionPlan::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'opportunity_id' => $opportunity->id,
        'status' => OpportunityExecutionPlan::STATUS_APPROVED,
        'title' => 'Execution plan: Brand visibility gap for AI SEO tools',
        'summary' => 'Create an editorial asset explaining the AI visibility opportunity.',
        'objective' => 'Improve AI visibility and citation coverage for AI SEO tools.',
        'recommended_channel' => 'owned_content',
        'recommended_format' => 'comparison_content_and_social_draft',
        'priority_score' => 91,
        'estimated_effort' => 58,
        'expected_impact' => 88,
        'planned_steps' => [
            ['title' => 'Audit AI citations', 'description' => 'Review ChatGPT, Gemini and Claude answers.', 'status' => 'pending'],
            ['title' => 'Create comparison content', 'description' => 'Explain tool selection and AI visibility criteria.', 'status' => 'pending'],
            ['title' => 'Add answer block', 'description' => 'Make the answer extractable for AI search.', 'status' => 'pending'],
        ],
        'source_evidence' => [
            'summary' => 'AI citation evidence shows visibility gaps.',
            'signals' => [[
                'id' => (string) $signal->id,
                'source' => OpportunitySignalSource::AI_CITATION_TRACKING->value,
                'category' => OpportunityCategory::BRAND_VISIBILITY->value,
                'topic' => 'AI SEO tools',
                'entity' => 'ChatGPT',
                'signal_strength' => 88,
                'confidence' => 84,
                'signal_detection_id' => (string) data_get($signal->metadata, 'signal_detection_id'),
                'evidence_summary' => ['AI citations missing'],
            ]],
        ],
        'metadata' => ['source_signal_count' => 1],
        'created_by' => $user->id,
    ]);

    return compact('organization', 'workspace', 'site', 'user', 'signal', 'opportunity', 'plan');
}

it('enriches a brief from opportunity intelligence before drafting', function (): void {
    $context = executionPlanBriefEnrichmentContext('full');

    $brief = app(ExecutionPlanBriefService::class)->createBrief($context['plan'], $context['user']);

    expect($brief)->toBeInstanceOf(Brief::class)
        ->and($brief->title)->toBe('Best AI SEO Tools for AI Search Visibility in 2026')
        ->and($brief->title)->not->toContain('Execution plan:')
        ->and($brief->primary_keyword)->toBe('AI SEO tools')
        ->and($brief->secondary_keywords)->toContain('AI search visibility')
        ->and($brief->secondary_keywords)->toContain('Generative Engine Optimization')
        ->and($brief->secondary_keywords)->toContain('GEO')
        ->and(count($brief->secondary_keywords))->toBe(count(array_unique(array_map('strtolower', $brief->secondary_keywords))))
        ->and($brief->search_intent)->toBe('commercial')
        ->and($brief->funnel_stage)->toBe('consideration')
        ->and($brief->target_audience)->toContain('CMOs')
        ->and($brief->target_audience)->toContain('SEO Specialists')
        ->and($brief->tone_of_voice)->toBe('Authoritative, evidence-based, practical, vendor-neutral.')
        ->and($brief->unique_angle)->toContain('traditional SEO')
        ->and($brief->key_points[0])->toStartWith('Problem:')
        ->and($brief->key_points[1])->toStartWith('Evidence:')
        ->and($brief->call_to_action)->toBe('Book an AI Visibility Audit')
        ->and(data_get($brief->client_refs, 'key_questions'))->toContain('What is AI Search Visibility?')
        ->and(data_get($brief->client_refs, 'entity_coverage'))->toContain('OpenAI')
        ->and(data_get($brief->client_refs, 'entity_coverage'))->toContain('ChatGPT')
        ->and(data_get($brief->client_refs, 'schema_recommendations'))->toContain('Article')
        ->and(data_get($brief->client_refs, 'schema_recommendations'))->toContain('FAQ')
        ->and(data_get($brief->client_refs, 'recommended_internal_links.0.title'))->toBe('AI Visibility Knowledge Hub')
        ->and(data_get($brief->client_refs, 'recommended_external_references.1.name'))->toBe('OpenAI')
        ->and(data_get($brief->client_refs, 'success_metrics'))->toContain('AI citations')
        ->and(data_get($brief->client_refs, 'humanization_notes'))->toContain('Avoid AI clichés and generic setup paragraphs.');
});

it('passes enriched opportunity brief fields into draft prompt metadata', function (): void {
    $context = executionPlanBriefEnrichmentContext('prompt');
    $brief = app(ExecutionPlanBriefService::class)->createBrief($context['plan'], $context['user']);

    $meta = app(BriefPromptBuilder::class)->buildDraftMeta($brief);

    expect($meta['primary_keyword'])->toBe('AI SEO tools')
        ->and($meta['expected_entities'])->toContain('OpenAI')
        ->and($meta['faq_questions'])->toContain('How does Argusly solve this?')
        ->and($meta['schema_recommendations'])->toContain('SoftwareApplication')
        ->and($meta['recommended_internal_links'][0]['anchor_text'])->toBe('AI Visibility')
        ->and($meta['notes'])->toContain('Key questions:')
        ->and($meta['notes'])->toContain('Recommended external references:')
        ->and($meta['notes'])->toContain('Humanization notes:');
});

it('keeps enriched execution plan briefs editable after creation', function (): void {
    $context = executionPlanBriefEnrichmentContext('editable');
    $brief = app(ExecutionPlanBriefService::class)->createBrief($context['plan'], $context['user']);

    $updated = app(UpdateBriefAction::class)->execute($brief, [
        'title' => 'Manual editorial title',
        'primary_keyword' => 'manual keyword',
        'secondary_keywords' => ['manual secondary'],
        'target_audience' => 'Edited audience',
        'search_intent' => 'informational',
        'key_points' => ['Manual outline point'],
    ]);

    expect($updated->title)->toBe('Manual editorial title')
        ->and($updated->primary_keyword)->toBe('manual keyword')
        ->and($updated->secondary_keywords)->toBe(['manual secondary'])
        ->and($updated->target_audience)->toBe('Edited audience')
        ->and($updated->search_intent)->toBe('informational')
        ->and($updated->key_points)->toBe(['Manual outline point']);
});
