<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DraftComparison\DraftComparisonProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftComparisonProgressContext(string $prefix = 'draft-compare-progress'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Progress Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Progress Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Progress Site',
        'site_url' => 'https://progress.example.com',
        'allowed_domains' => ['progress.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Progress User',
        'email' => $prefix . '+' . Str::random(5) . '@example.com',
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
        'title' => 'Progress brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    return [$organization, $workspace, $site, $user, $brief];
}

it('marks comparison item generated and syncs comparison counters', function () {
    [, , $site, , $brief] = makeDraftComparisonProgressContext();

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'mode' => 'single',
        'status' => 'running',
        'items_total' => 1,
        'estimated_credits' => 10,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Generated Draft',
        'output_type' => 'kb_article',
        'content_html' => '<h2>Intro</h2><p>Book a demo today with clear guidance and structure.</p><h2>Steps</h2><p>Step one.</p>',
        'credit_cost' => 10,
        'meta' => [
            'generation' => [
                'charged_credits' => 10,
                'input_tokens' => 100,
                'output_tokens' => 200,
                'tokens' => 300,
                'model_used' => 'gpt-4.1-mini',
            ],
            'draft_compare' => [
                'comparison_id' => (string) $comparison->id,
                'item_id' => null,
                'is_hybrid' => false,
            ],
        ],
    ]);

    $item = DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draft->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'generating',
        'credit_cost' => 10,
    ]);

    $draft->meta = array_replace_recursive((array) $draft->meta, [
        'draft_compare' => [
            'item_id' => (string) $item->id,
        ],
    ]);
    $draft->save();

    app(DraftComparisonProgressService::class)->markDraftGenerated($draft->fresh());

    $item->refresh();
    $comparison->refresh();

    expect((string) $item->status)->toBe('generated');
    expect((int) $item->charged_credits)->toBe(10);
    expect((int) $item->total_tokens)->toBe(300);
    expect((int) data_get($item->metrics, 'word_count', 0))->toBeGreaterThan(0);

    expect((string) $comparison->status)->toBe('completed');
    expect((int) $comparison->items_done)->toBe(1);
    expect((int) $comparison->items_failed)->toBe(0);
    expect((int) $comparison->credits_used)->toBe(10);
});

it('marks comparison item failed and transitions comparison to failed', function () {
    [, , $site, , $brief] = makeDraftComparisonProgressContext('draft-compare-progress-failed');

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'mode' => 'single',
        'status' => 'running',
        'items_total' => 1,
        'estimated_credits' => 10,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'failed',
        'title' => 'Failed Draft',
        'output_type' => 'kb_article',
        'meta' => [
            'draft_compare' => [
                'comparison_id' => (string) $comparison->id,
                'is_hybrid' => false,
            ],
        ],
    ]);

    $item = DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draft->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'generating',
        'credit_cost' => 10,
    ]);

    $draft->meta = array_replace_recursive((array) $draft->meta, [
        'draft_compare' => [
            'item_id' => (string) $item->id,
        ],
    ]);
    $draft->save();

    app(DraftComparisonProgressService::class)->markDraftFailed($draft->fresh(), 'Provider rejected request', false);

    $item->refresh();
    $comparison->refresh();

    expect((string) $item->status)->toBe('failed');
    expect((string) $item->error_message)->toContain('Provider rejected request');

    expect((string) $comparison->status)->toBe('failed');
    expect((int) $comparison->items_done)->toBe(0);
    expect((int) $comparison->items_failed)->toBe(1);
    expect((string) $comparison->last_error)->toContain('Provider rejected request');
});
