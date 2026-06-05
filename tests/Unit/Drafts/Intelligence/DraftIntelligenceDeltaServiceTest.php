<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Models\DraftImprovementResult;
use App\Models\DraftIntelligenceDelta;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Drafts\Intelligence\DraftIntelligenceDeltaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates metric deltas with before after and explanation fields', function () {
    $organization = Organization::query()->create([
        'name' => 'Delta Service Org',
        'slug' => 'delta-service-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Delta Service BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Delta Service Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Delta Service Site',
        'site_url' => 'https://delta-service.example.com',
        'allowed_domains' => ['delta-service.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'delta-service-plan'],
        [
            'name' => 'Delta Service Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Delta Service User',
        'email' => 'delta-service+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Delta service brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'draft intelligence',
        'call_to_action' => 'Book a demo',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Draft intelligence article',
        'output_type' => 'kb_article',
        'seo_title' => 'Draft intelligence article',
        'seo_meta_description' => 'Draft intelligence summary.',
        'seo_h1' => 'Draft intelligence article',
        'content_html' => '<h1>Draft intelligence</h1><p>This article explains SEO, readability, and CTA improvements.</p>',
    ]);

    $before = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'seo_score' => 68,
        'readability_score' => 80,
        'cta_score' => 40,
        'headings_score' => 59,
        'suggestions' => [
            'sections' => [
                'seo' => ['score' => 68, 'explanation' => 'SEO needs work.', 'improvements' => ['Tighten SEO.']],
                'readability' => ['score' => 80, 'explanation' => 'Readable.', 'improvements' => ['Keep it tight.']],
                'cta' => ['score' => 40, 'explanation' => 'Weak CTA.', 'improvements' => ['Add CTA.']],
                'structure' => ['score' => 59, 'explanation' => 'Weak headings.', 'improvements' => ['Improve headings.']],
                'entities' => ['score' => 70, 'explanation' => 'Okay.', 'improvements' => ['Keep examples.']],
            ],
        ],
        'normalized_payload' => [
            'sections' => [
                'seo' => ['score' => 68, 'explanation' => 'SEO needs work.', 'improvements' => ['Tighten SEO.']],
                'readability' => ['score' => 80, 'explanation' => 'Readable.', 'improvements' => ['Keep it tight.']],
                'cta' => ['score' => 40, 'explanation' => 'Weak CTA.', 'improvements' => ['Add CTA.']],
                'structure' => ['score' => 59, 'explanation' => 'Weak headings.', 'improvements' => ['Improve headings.']],
                'entities' => ['score' => 70, 'explanation' => 'Okay.', 'improvements' => ['Keep examples.']],
            ],
        ],
    ]);

    $after = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'seo_score' => 75,
        'readability_score' => 84,
        'cta_score' => 63,
        'headings_score' => 71,
        'suggestions' => [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'SEO improved with stronger keyword placement.', 'improvements' => ['Keep it natural.']],
                'readability' => ['score' => 84, 'explanation' => 'Readability improved with shorter sentences.', 'improvements' => ['Keep transitions tight.']],
                'cta' => ['score' => 63, 'explanation' => 'CTA improved with a clearer closing next step.', 'improvements' => ['Add more specificity if needed.']],
                'structure' => ['score' => 71, 'explanation' => 'Headings improved with better specificity.', 'improvements' => ['Keep hierarchy consistent.']],
                'entities' => ['score' => 70, 'explanation' => 'Okay.', 'improvements' => ['Keep examples.']],
            ],
        ],
        'normalized_payload' => [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'SEO improved with stronger keyword placement.', 'improvements' => ['Keep it natural.']],
                'readability' => ['score' => 84, 'explanation' => 'Readability improved with shorter sentences.', 'improvements' => ['Keep transitions tight.']],
                'cta' => ['score' => 63, 'explanation' => 'CTA improved with a clearer closing next step.', 'improvements' => ['Add more specificity if needed.']],
                'structure' => ['score' => 71, 'explanation' => 'Headings improved with better specificity.', 'improvements' => ['Keep hierarchy consistent.']],
                'entities' => ['score' => 70, 'explanation' => 'Okay.', 'improvements' => ['Keep examples.']],
            ],
        ],
    ]);

    $improvementResult = DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'before_analysis_id' => (string) $before->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'requested_by_user_id' => (string) $user->id,
    ]);

    $snapshot = app(DraftIntelligenceDeltaService::class)->storeForImprovement($improvementResult, $before, $after);

    expect(data_get($snapshot, 'cta.score_before'))->toBe(40)
        ->and(data_get($snapshot, 'cta.score_after'))->toBe(63)
        ->and(data_get($snapshot, 'cta.delta_value'))->toBe(23)
        ->and(data_get($snapshot, 'cta.delta'))->toBe(23)
        ->and((string) data_get($snapshot, 'cta.explanation'))->toContain('CTA improved from 40 to 63 (+23)');
});

it('keeps partial metric comparisons null safe when an after score is unavailable', function () {
    [$user, $draft] = makeDraftIntelligenceContext('delta-service-null-safe');

    $before = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'cta_score' => 35,
        'normalized_payload' => [
            'sections' => [
                'cta' => ['score' => 35, 'explanation' => 'CTA is weak.', 'improvements' => ['Add a CTA.']],
            ],
        ],
    ]);

    $after = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_PARTIAL,
        'normalized_payload' => [
            'sections' => [
                'cta' => ['score' => null, 'explanation' => 'CTA section was not available in this scan.', 'improvements' => []],
            ],
        ],
    ]);

    $improvementResult = DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'before_analysis_id' => (string) $before->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'requested_by_user_id' => (string) $user->id,
    ]);

    $snapshot = app(DraftIntelligenceDeltaService::class)->storeForImprovement($improvementResult, $before, $after);
    $ctaDelta = DraftIntelligenceDelta::query()
        ->where('draft_improvement_result_id', (string) $improvementResult->id)
        ->where('metric_key', 'cta')
        ->firstOrFail();

    expect(data_get($snapshot, 'cta.score_before'))->toBe(35)
        ->and(data_get($snapshot, 'cta.score_after'))->toBeNull()
        ->and(data_get($snapshot, 'cta.delta_value'))->toBeNull()
        ->and($ctaDelta->getRawOriginal('score_after'))->toBeNull()
        ->and($ctaDelta->getRawOriginal('delta'))->toBeNull()
        ->and((string) data_get($snapshot, 'cta.explanation'))->toContain('did not produce a new score yet');
});
