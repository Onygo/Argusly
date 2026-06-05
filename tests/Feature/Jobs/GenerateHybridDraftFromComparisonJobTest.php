<?php

use App\Jobs\DraftComparison\GenerateHybridDraftFromComparisonJob;
use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonScore;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeHybridJobContext(string $prefix = 'draft-compare-hybrid-job'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Hybrid Job Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Hybrid Job Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Hybrid Job Site',
        'site_url' => 'https://draft-compare-hybrid-job.example.com',
        'allowed_domains' => ['draft-compare-hybrid-job.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Hybrid Job User',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Hybrid generation brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'hybrid content strategy',
    ]);

    return [$organization, $workspace, $site, $user, $brief];
}

it('builds and queues a hybrid draft from successful comparison variants', function () {
    Queue::fake();

    [, $workspace, $site, $user, $brief] = makeHybridJobContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Hybrid content',
        'primary_keyword' => 'hybrid content strategy',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $brief->content_id = $content->id;
    $brief->save();

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_COMPLETED,
        'hybrid_status' => 'queued',
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Variant A',
        'output_type' => 'kb_article',
        'content_html' => '<h2>A</h2><p>Book a demo and improve SEO structure with measurable steps.</p>',
        'meta' => [
            'generation' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini'],
        ],
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Variant B',
        'output_type' => 'kb_article',
        'content_html' => '<h2>B</h2><p>Clear conversion focus with stronger CTA and readability.</p>',
        'meta' => [
            'generation' => ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest'],
        ],
    ]);

    $variantA = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftA->id,
    ]);

    $variantB = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftB->id,
    ]);

    foreach ([
        ['variant' => $variantA, 'metrics' => ['seo_score' => 88, 'cta_strength' => 71, 'structure_quality' => 83, 'conversion_focus' => 75]],
        ['variant' => $variantB, 'metrics' => ['seo_score' => 80, 'cta_strength' => 93, 'structure_quality' => 76, 'conversion_focus' => 91]],
    ] as $row) {
        foreach ($row['metrics'] as $metric => $score) {
            DraftComparisonScore::query()->create([
                'draft_comparison_variant_id' => $row['variant']->id,
                'metric_key' => $metric,
                'metric_label' => Str::headline($metric),
                'metric_group' => 'test',
                'numeric_score' => $score,
                'explanation' => 'metric',
            ]);
        }
    }

    $job = new GenerateHybridDraftFromComparisonJob((string) $comparison->id);
    $job->handle(app(\App\Services\DraftComparison\DraftComparisonService::class));
    $job->handle(app(\App\Services\DraftComparison\DraftComparisonService::class));

    $comparison->refresh();
    $hybridDraft = Draft::query()->findOrFail($comparison->hybrid_draft_id);

    expect((string) $comparison->hybrid_status)->toBe('queued')
        ->and((string) $hybridDraft->draft_comparison_id)->toBe((string) $comparison->id)
        ->and($hybridDraft->draft_comparison_variant_id)->toBeNull()
        ->and((bool) data_get($hybridDraft->meta, 'draft_compare.is_hybrid'))->toBeTrue()
        ->and((bool) data_get($hybridDraft->meta, 'draft_compare.comparison_credit_managed'))->toBeFalse()
        ->and((string) data_get($hybridDraft->meta, 'draft_compare.comparison_id'))->toBe((string) $comparison->id)
        ->and((array) data_get($hybridDraft->meta, 'draft_compare.source_draft_ids'))->toContain((string) $draftA->id, (string) $draftB->id)
        ->and((array) data_get($hybridDraft->meta, 'draft_compare.source_variant_ids'))->toContain((string) $variantA->id, (string) $variantB->id)
        ->and(trim((string) data_get($hybridDraft->meta, 'generation_provider_override')))->not->toBe('')
        ->and(trim((string) data_get($hybridDraft->meta, 'generation_model_override')))->not->toBe('')
        ->and(trim((string) data_get($hybridDraft->meta, 'generation_custom_user_prompt')))->toContain('Strengths:')
        ->and((int) Draft::query()
            ->where('brief_id', $brief->id)
            ->where('title', 'like', '%(Hybrid)')
            ->count())->toBe(1);

    Queue::assertPushed(GenerateDraftJob::class, function (GenerateDraftJob $job) use ($hybridDraft): bool {
        return (string) $job->draftId === (string) $hybridDraft->id;
    });
    Queue::assertPushed(GenerateDraftJob::class, 1);
});

it('fails hybrid generation when fewer than two variants completed successfully', function () {
    Queue::fake();

    [, $workspace, $site, $user, $brief] = makeHybridJobContext('draft-compare-hybrid-job-failed');
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Hybrid content failed',
        'primary_keyword' => 'hybrid failed',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $brief->content_id = $content->id;
    $brief->save();

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_COMPLETED,
        'hybrid_status' => 'queued',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Single variant',
        'output_type' => 'kb_article',
        'content_html' => '<p>Only one variant available.</p>',
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draft->id,
    ]);

    $job = new GenerateHybridDraftFromComparisonJob((string) $comparison->id);
    $job->handle(app(\App\Services\DraftComparison\DraftComparisonService::class));

    $comparison->refresh();

    expect((string) $comparison->hybrid_status)->toBe('failed')
        ->and((string) $comparison->hybrid_last_error)->toContain('at least two successful drafts');

    Queue::assertNotPushed(GenerateDraftJob::class);
});
