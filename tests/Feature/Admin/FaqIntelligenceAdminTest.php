<?php

use App\Models\User;
use App\Models\FaqOpportunityAudit;
use App\Models\FaqQuestion;
use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Enums\FaqWorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an admin to analyze FAQ opportunities from the dashboard', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.faq-intelligence.analyze'), [
            'page_title' => 'AI Visibility',
            'page_slug' => 'ai-visibility',
            'page_type' => 'solution',
            'locale' => 'en',
            'meta_title' => 'AI Visibility | Argusly',
            'meta_description' => 'Measure how AI systems understand your brand.',
            'h1' => 'See how AI systems understand your brand',
            'h2s' => "Prompt monitoring\nAnswer coverage",
            'content' => 'Argusly helps B2B teams with opportunity intelligence and autonomous marketing workflows.',
            'sector' => 'IT Services & SaaS',
            'solution_type' => 'AI Visibility',
            'internal_links' => "Contact\nPlatform",
        ])
        ->assertOk()
        ->assertSee('FAQ analysis')
        ->assertSee('Generated FAQ');
});

it('allows an admin to accept a generated FAQ proposal', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.faq-intelligence.accept'), [
            'page_title' => 'AI Visibility',
            'page_slug' => 'ai-visibility',
            'page_type' => 'solution',
            'locale' => 'en',
            'generated_faq' => json_encode([
                'question' => 'How does Argusly improve AI Visibility for B2B teams?',
                'answer' => 'Argusly improves AI Visibility by identifying missing buyer questions and turning them into answer-ready FAQ content.',
                'faq_type' => FaqType::SOLUTION->value,
                'search_intent' => FaqSearchIntent::COMMERCIAL->value,
                'funnel_stage' => FaqFunnelStage::CONSIDERATION->value,
                'priority' => 90,
            ]),
        ])
        ->assertRedirect();

    expect(FaqQuestion::query()->count())->toBe(1)
        ->and(FaqQuestion::query()->first()->assignments()->count())->toBe(1);
});

it('shows dashboard breakdowns and duplicate risk widgets', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'admin',
        'email_verified_at' => now(),
    ]);

    FaqOpportunityAudit::query()->create([
        'page_type' => 'market',
        'page_slug' => 'saas',
        'locale' => 'en',
        'page_title' => 'SaaS',
        'sector' => 'SaaS',
        'solution_type' => 'AI Visibility',
        'status' => FaqWorkflowStatus::REVIEW_REQUIRED,
        'faq_coverage_score' => 42,
        'faq_opportunity_score' => 88,
        'ai_visibility_impact_score' => 91,
        'seo_impact_score' => 84,
        'conversion_impact_score' => 80,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.faq-intelligence.index'))
        ->assertOk()
        ->assertSee('Coverage per page type')
        ->assertSee('Top FAQ opportunities')
        ->assertSee('Top duplicate risks');
});

it('allows publishing and unlinking FAQ assignments', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $faq = FaqQuestion::query()->create([
        'question' => 'How does Argusly handle governance?',
        'answer' => 'Argusly keeps review and publication under human control.',
        'language' => 'en',
        'faq_type' => FaqType::GOVERNANCE,
        'search_intent' => FaqSearchIntent::COMMERCIAL,
        'funnel_stage' => FaqFunnelStage::DECISION,
        'status' => FaqStatus::REVIEW,
    ]);
    $assignment = $faq->assignments()->create([
        'page_type' => 'security',
        'page_slug' => 'legal.security',
        'locale' => 'en',
        'weight' => 80,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.faq-intelligence.questions.publish', $faq))
        ->assertRedirect();

    $this->actingAs($admin)
        ->delete(route('admin.faq-intelligence.assignments.unlink', $assignment))
        ->assertRedirect();

    expect($faq->fresh()->status)->toBe(FaqStatus::PUBLISHED)
        ->and($faq->fresh()->assignments()->count())->toBe(0);
});
