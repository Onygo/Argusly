<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CompanyProfile;
use App\Models\Content;
use App\Models\Organization;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Editorial\EditorialPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds an editorial plan from brief, research, company, audience, and existing content context', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Editorial Planning Org',
        'slug' => 'editorial-planning-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Editorial Workspace',
        'organization_id' => $organization->id,
    ]);

    CompanyProfile::query()->create([
        'workspace_id' => (string) $workspace->id,
        'company_name' => 'Argusly',
        'industry' => 'B2B SaaS',
        'key_services' => "AI visibility monitoring\nContent operations",
        'proof_points' => "Tracks citation patterns\nConnects publishing workflows",
        'target_audience' => 'Marketing leaders',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Editorial Site',
        'site_url' => 'https://editorial.example.test',
        'allowed_domains' => ['editorial.example.test'],
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Editor',
        'email' => 'editor-' . Str::random(6) . '@example.test',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'AI visibility operations checklist',
        'primary_keyword' => 'AI visibility operations',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
    ]);

    $content = Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'AI visibility strategy',
        'primary_keyword' => 'AI visibility strategy',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'AI visibility strategy for B2B teams',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'AI visibility strategy',
        'target_audience' => 'B2B marketing leaders',
        'search_intent' => 'commercial',
        'funnel_stage' => 'decision',
        'unique_angle' => 'AI visibility only matters when it changes editorial decisions',
        'key_points' => ['Citation patterns should guide content refreshes'],
    ]);

    $research = ResearchProject::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'brief_id' => (string) $brief->id,
        'created_by' => $user->id,
        'name' => 'AI visibility research',
        'status' => 'completed',
        'summary' => [
            'highlights' => [
                'insights' => ['Teams with answer blocks see stronger citation consistency.'],
                'statistics' => ['Three recent tracking runs showed citation lift.'],
            ],
        ],
    ]);

    ResearchFinding::query()->create([
        'research_project_id' => (string) $research->id,
        'finding_type' => 'insight',
        'finding_text' => 'LLM tracking detected repeat citation patterns around operational answer blocks.',
        'confidence_score' => 0.92,
        'is_selected' => true,
    ]);

    $plan = app(EditorialPlanningService::class)->createForBrief($brief->fresh(), [
        'preferred_length' => 'long',
    ]);

    expect($plan['version'])->toBe(EditorialPlanningService::VERSION)
        ->and($plan['central_thesis'])->toContain('AI visibility')
        ->and($plan['unique_angle'])->toBe('AI visibility only matters when it changes editorial decisions')
        ->and(data_get($plan, 'primary_pattern.name'))->toBe('Field Observation')
        ->and(data_get($plan, 'primary_pattern.article_movement'))->not->toBeEmpty()
        ->and($plan['evidence_plan'])->toContain('Use selected research findings as the first evidence source; cite the observation as an observed pattern, not as article copy.')
        ->and(data_get($plan, 'context_snapshot.company.name'))->toBe('Argusly')
        ->and(data_get($plan, 'context_snapshot.research_insights.0'))->toContain('LLM tracking')
        ->and(data_get($plan, 'context_snapshot.previous_related_articles.0.title'))->toBe('AI visibility operations checklist')
        ->and(data_get($plan, 'corpus_diversity_guidance.status'))->toBe('available')
        ->and(data_get($plan, 'corpus_diversity_guidance.avoid_repeating.0'))->toContain('AI visibility operations checklist')
        ->and($plan['things_to_avoid'])->toContain('Vary opening frame, section rhythm, examples, argument order, and CTA language against recent workspace content.')
        ->and($plan['section_intentions'])->toBeArray()
        ->and($plan['section_intentions'])->toHaveCount(6);
});

it('formats an editorial plan as prompt guidance without article copy instructions', function (): void {
    $plan = [
        'central_thesis' => 'The thesis',
        'primary_pattern' => [
            'name' => 'Question Driven',
            'article_movement' => 'Answer the core question, then progress through useful follow-up questions.',
            'heading_guidance' => 'Use real question headings.',
        ],
        'section_intentions' => [
            ['intention' => 'Answer directly', 'job' => 'Clarify the decision.'],
        ],
        'corpus_diversity_guidance' => [
            'recommendations' => ['Use fresh section movement against recent workspace content.'],
        ],
        'things_to_avoid' => ['Avoid generic sections.'],
    ];

    $prompt = app(EditorialPlanningService::class)->toPromptSection($plan);

    expect($prompt)->toContain('EDITORIAL PLAN')
        ->and($prompt)->toContain('Primary editorial pattern')
        ->and($prompt)->toContain('Question Driven')
        ->and($prompt)->toContain('Corpus diversity guidance')
        ->and($prompt)->toContain('Use fresh section movement')
        ->and($prompt)->toContain('Do not treat section intentions as fixed headings')
        ->and($prompt)->not->toContain('Requested structure')
        ->and($prompt)->not->toContain('Opening');
});
