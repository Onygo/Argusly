<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HumanContent\HumanContentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('loads the human content dashboard with score data', function (): void {
    [$user, $workspace, $site] = makeHumanContentDashboardContext();

    makeHumanContentDashboardDraft($workspace, $site, [
        'title' => 'Most human editorial article',
        'human_content_score' => 86,
        'editorial_quality_score' => 82,
        'originality_score' => 91,
        'ai_fingerprint_score' => 18,
        'narrative_flow_score' => 80,
        'human_voice_score' => 84,
    ]);

    makeHumanContentDashboardDraft($workspace, $site, [
        'title' => 'Useful but repetitive article',
        'human_content_score' => 66,
        'editorial_quality_score' => 64,
        'originality_score' => 58,
        'ai_fingerprint_score' => 42,
        'narrative_flow_score' => 62,
        'human_voice_score' => 60,
        'corpus_diversity_risk_score' => 78,
    ]);

    $this->actingAs($user)
        ->get(route('app.insights.human-content.index'))
        ->assertOk()
        ->assertSee('Human Content Dashboard')
        ->assertSee('76')
        ->assertSee('Most human editorial article')
        ->assertSee('Useful but repetitive article')
        ->assertSee('Most Repetitive Articles');
});

it('handles an empty human content dashboard state', function (): void {
    [$user] = makeHumanContentDashboardContext();

    $this->actingAs($user)
        ->get(route('app.insights.human-content.index'))
        ->assertOk()
        ->assertSee('No Human Content scores yet')
        ->assertSee('0 scored drafts');
});

it('shows articles blocked by the human content gate', function (): void {
    [$user, $workspace, $site] = makeHumanContentDashboardContext();

    makeHumanContentDashboardDraft($workspace, $site, [
        'title' => 'Blocked editorial draft',
        'human_content_score' => 48,
        'editorial_quality_score' => 44,
        'originality_score' => 52,
        'ai_fingerprint_score' => 71,
        'publish_gate_status' => HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW,
        'gate_reasons' => ['Human content score is below 70.', 'AI fingerprint score is above 45.'],
    ]);

    $this->actingAs($user)
        ->get(route('app.insights.human-content.index'))
        ->assertOk()
        ->assertSee('Blocked By Human Content Gate')
        ->assertSee('Blocked editorial draft')
        ->assertSee('Human content score is below 70.');
});

it('summarizes common AI fingerprint findings', function (): void {
    [$user, $workspace, $site] = makeHumanContentDashboardContext();

    makeHumanContentDashboardDraft($workspace, $site, [
        'title' => 'Generic heading article',
        'fingerprint_findings' => [
            ['type' => 'generic_headings', 'message' => 'Generic headings found.', 'evidence' => 'Introduction'],
            ['type' => 'uniform_paragraph_lengths', 'message' => 'Uniform rhythm found.'],
        ],
    ]);
    makeHumanContentDashboardDraft($workspace, $site, [
        'title' => 'Another generic heading article',
        'fingerprint_findings' => [
            ['type' => 'generic_headings', 'message' => 'Generic headings found.', 'evidence' => 'Conclusion'],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('app.insights.human-content.index'))
        ->assertOk()
        ->assertSee('Common AI Fingerprints')
        ->assertSee('Generic Headings')
        ->assertSee('Uniform Paragraph Lengths');
});

function makeHumanContentDashboardContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Human Content Dashboard Org',
        'slug' => 'human-content-dashboard-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Human Content Dashboard BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Human Content Workspace',
        'display_name' => 'Human Content Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Human Content Site',
        'site_url' => 'https://human-content.example.test',
        'allowed_domains' => ['human-content.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'human-content-dashboard-plan'],
        [
            'name' => 'Human Content Dashboard Plan',
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
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
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
        'name' => 'Human Content Editor',
        'email' => 'human-content-dashboard-' . Str::lower(Str::random(6)) . '@example.test',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$user, $workspace, $site, $organization];
}

function makeHumanContentDashboardDraft(Workspace $workspace, ClientSite $site, array $overrides = []): Draft
{
    $title = (string) ($overrides['title'] ?? 'Human content scored article');
    $content = Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => $title,
        'language' => (string) ($overrides['locale'] ?? 'en'),
        'type' => (string) ($overrides['content_type'] ?? 'article'),
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => $title,
        'language' => (string) ($overrides['locale'] ?? 'en'),
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $human = (int) ($overrides['human_content_score'] ?? 74);
    $editorial = (int) ($overrides['editorial_quality_score'] ?? 72);
    $originality = (int) ($overrides['originality_score'] ?? 76);
    $fingerprint = (int) ($overrides['ai_fingerprint_score'] ?? 28);
    $flow = (int) ($overrides['narrative_flow_score'] ?? 70);
    $voice = (int) ($overrides['human_voice_score'] ?? 73);
    $risk = (int) ($overrides['corpus_diversity_risk_score'] ?? max(0, 100 - $originality));

    $meta = [
        'human_content_score_after' => $human,
        'ai_fingerprint_score_after' => $fingerprint,
        'publish_gate_status' => (string) ($overrides['publish_gate_status'] ?? HumanContentGate::STATUS_PASSED),
        'fingerprint_findings' => (array) ($overrides['fingerprint_findings'] ?? []),
        'human_content_gate' => [
            'status' => (string) ($overrides['publish_gate_status'] ?? HumanContentGate::STATUS_PASSED),
            'reasons' => (array) ($overrides['gate_reasons'] ?? []),
        ],
        'human_content' => [
            'after' => [
                'status' => $human >= 70 ? 'pass' : 'fail',
                'passed' => $human >= 70,
                'human_content_score' => $human,
                'editorial_quality_score' => $editorial,
                'originality_score' => $originality,
                'ai_fingerprint_score' => $fingerprint,
                'narrative_flow_score' => $flow,
                'human_voice_score' => $voice,
                'uniqueness_score' => (int) ($overrides['uniqueness_score'] ?? $originality),
                'corpus_diversity_risk_score' => $risk,
            ],
        ],
    ];

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready',
        'title' => $title,
        'language' => (string) ($overrides['locale'] ?? 'en'),
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<h1>' . e($title) . '</h1><p>Stored scored article.</p>',
        'meta' => $meta,
        'updated_at' => $overrides['updated_at'] ?? now(),
    ]);
}
