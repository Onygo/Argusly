<?php

use App\Jobs\GenerateContentImprovementJob;
use App\Models\Content;
use App\Models\ContentImprovementEvent;
use App\Models\ContentImprovementRun;
use App\Models\ContentVersion;
use App\Models\CreditWallet;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\ClientSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeContentImprovementContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Improvement Workflow Org',
        'slug' => 'improvement-workflow-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'PublishLayer BV',
        'billing_address_line1' => 'Straat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Improvement Workflow Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Improvement Workflow Site',
        'site_url' => 'https://improvements.example.com',
        'base_url' => 'https://improvements.example.com',
        'allowed_domains' => ['improvements.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-improvement-workflow-plan'],
        [
            'name' => 'Content Improvement Workflow Plan',
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
        'name' => 'Improvement Workflow User',
        'email' => 'improvement-workflow-' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    CreditWallet::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'workspace_id' => (string) $workspace->id,
        'balance_cached' => 50,
        'reserved_cached' => 0,
        'used_cached' => 0,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'AI Content Operations Guide',
        'language' => 'en',
        'translation_source_locale' => 'en',
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'primary_keyword' => 'content operations',
        'aeo_breakdown' => [
            'improvements' => [
                'Add direct answer under H1',
                'Shorten sentences and improve scanability',
            ],
        ],
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_REVISION,
        'body' => '<p>Original body copy about content operations.</p>',
        'source' => ContentVersion::SOURCE_PUBLISHLAYER,
        'created_by' => (int) $user->id,
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    return [$workspace, $site, $user, $content->fresh(['currentVersion'])];
}

it('queues a content improvement run and dispatches the generation job', function () {
    Queue::fake();

    [, , $user, $content] = makeContentImprovementContext();

    $response = $this->actingAs($user)->postJson(route('app.content.improvements.queue', $content), [
        'type' => 'readability',
        'recommendation' => 'Shorten sentences and improve scanability',
    ]);

    $response->assertOk()
        ->assertJson([
            'queued' => true,
            'toast' => 'AI improvement queued.',
        ]);

    $run = ContentImprovementRun::query()->where('content_id', (string) $content->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(ContentImprovementRun::STATUS_QUEUED)
        ->and($run->type)->toBe('readability');

    Queue::assertPushed(GenerateContentImprovementJob::class, function (GenerateContentImprovementJob $job) use ($run): bool {
        return $job->runId === (string) $run->id && $job->queue === 'generation';
    });

    expect(ContentImprovementEvent::query()
        ->where('content_improvement_run_id', (string) $run->id)
        ->where('event_type', 'QUEUED')
        ->exists())->toBeTrue();
});

it('prevents duplicate active improvement jobs of the same type', function () {
    Queue::fake();

    [, , $user, $content] = makeContentImprovementContext();

    $run = ContentImprovementRun::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'organization_id' => $content->workspace->organization_id,
        'type' => 'readability',
        'recommendation_label' => 'Shorten sentences and improve scanability',
        'status' => ContentImprovementRun::STATUS_RUNNING,
        'progress_percentage' => 42,
        'created_by' => (int) $user->id,
    ]);

    $response = $this->actingAs($user)->postJson(route('app.content.improvements.queue', $content), [
        'type' => 'readability',
        'recommendation' => 'Shorten sentences and improve scanability',
    ]);

    $response->assertOk()
        ->assertJson([
            'queued' => true,
            'run_id' => (string) $run->id,
            'toast' => 'An improvement of this type is already queued or running.',
        ]);

    expect(ContentImprovementRun::query()
        ->where('content_id', (string) $content->id)
        ->where('type', 'readability')
        ->count())->toBe(1);

    Queue::assertNotPushed(GenerateContentImprovementJob::class);
});

it('applies a completed improvement payload to an editable draft', function () {
    [, , $user, $content] = makeContentImprovementContext();

    $run = ContentImprovementRun::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'organization_id' => $content->workspace->organization_id,
        'type' => 'readability',
        'recommendation_label' => 'Shorten sentences and improve scanability',
        'status' => ContentImprovementRun::STATUS_COMPLETED,
        'progress_percentage' => 100,
        'created_by' => (int) $user->id,
        'completed_at' => now(),
        'result_payload' => [
            'content_html' => '<p>Improved body copy with tighter readability.</p>',
            'title' => 'Improved AI Content Operations Guide',
            'seo_title' => 'Improved SEO Title',
            'seo_meta_description' => 'Sharper summary for discovery.',
            'seo_h1' => 'Improved content operations guide',
            'change_summary' => 'Shortened the introduction and tightened sentence flow.',
            'inserted_text' => 'Improved body copy',
            'removed_text' => 'Original body copy',
        ],
        'diagnostics' => [
            'queue_name' => 'generation',
            'retry_count' => 1,
        ],
    ]);

    $response = $this->actingAs($user)->postJson(route('app.content.improvements.accept', [$content, $run]));

    $response->assertOk()
        ->assertJson([
            'applied' => true,
            'toast' => 'Generated improvement applied to draft.',
        ]);

    $draft = Draft::query()->where('content_id', (string) $content->id)->latest('created_at')->first();
    $run->refresh();

    expect($draft)->not->toBeNull()
        ->and($draft->content_html)->toBe('<p>Improved body copy with tighter readability.</p>')
        ->and($draft->title)->toBe('Improved AI Content Operations Guide')
        ->and($draft->seo_title)->toBe('Improved SEO Title')
        ->and((string) data_get($draft->meta, 'content_improvements.latest_run_id'))->toBe((string) $run->id)
        ->and($run->applied_at)->not->toBeNull();

    expect(ContentImprovementEvent::query()
        ->where('content_improvement_run_id', (string) $run->id)
        ->where('event_type', 'APPLIED')
        ->exists())->toBeTrue();
});

it('rejects a generated improvement and exposes it in status payloads', function () {
    [, , $user, $content] = makeContentImprovementContext();

    $run = ContentImprovementRun::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'organization_id' => $content->workspace->organization_id,
        'type' => 'seo',
        'recommendation_label' => 'Add direct answer under H1',
        'status' => ContentImprovementRun::STATUS_COMPLETED,
        'progress_percentage' => 100,
        'created_by' => (int) $user->id,
        'completed_at' => now(),
        'result_payload' => [
            'content_html' => '<p>Generated answer-led introduction.</p>',
            'change_summary' => 'Added a direct answer to the opening.',
            'diff_preview_html' => '<ins>Generated</ins>',
        ],
    ]);

    $rejectResponse = $this->actingAs($user)->postJson(route('app.content.improvements.reject', [$content, $run]));

    $rejectResponse->assertOk()
        ->assertJson([
            'rejected' => true,
            'toast' => 'Generated improvement rejected.',
        ]);

    $run->refresh();

    expect($run->status)->toBe(ContentImprovementRun::STATUS_CANCELLED);

    $statusResponse = $this->actingAs($user)->getJson(route('app.content.improvements.status', $content));

    $statusResponse->assertOk()
        ->assertJsonStructure([
            'actions_html',
            'monitor_html',
            'generated_html',
            'events',
            'latest_event_id',
        ])
        ->assertSee('Generated improvements', false);

    expect(collect($statusResponse->json('events'))->pluck('event_type')->all())->toContain('CANCELLED');
});

it('shows a generated result card and review draft state for a completed improvement on the current editable revision', function () {
    [, $site, $user, $content] = makeContentImprovementContext();

    $targetDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => Draft::query()->where('content_id', (string) $content->id)->value('brief_id') ?: \App\Models\Brief::query()->create([
            'client_site_id' => $site->id,
            'content_id' => $content->id,
            'created_by_user_id' => $user->id,
            'status' => 'draft',
            'source' => 'client_ui',
            'title' => 'Improvement workflow brief',
            'language' => 'en',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'progress' => 0,
        ])->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'generated',
        'title' => 'Improved AI Content Operations Guide',
        'output_type' => 'kb_article',
        'language' => 'en',
        'content_html' => '<p>Improved body copy with tighter readability.</p>',
        'meta' => [],
    ]);

    $currentHash = sha1('Improved body copy with tighter readability.');

    ContentImprovementRun::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'organization_id' => $content->workspace->organization_id,
        'type' => 'readability',
        'recommendation_label' => 'Shorten sentences and improve scanability',
        'recommendation_key' => 'readability:shorten-sentences-and-improve-scanability',
        'status' => ContentImprovementRun::STATUS_COMPLETED,
        'progress_percentage' => 100,
        'created_by' => (int) $user->id,
        'completed_at' => now(),
        'source_revision_hash' => sha1('Original body copy about content operations.'),
        'target_draft_id' => (string) $targetDraft->id,
        'draft_id' => (string) $targetDraft->id,
        'output_revision_hash' => $currentHash,
        'generated_summary' => 'Shortened the introduction and tightened sentence flow.',
        'diff_summary' => 'Added 6 words, removed 5 words.',
        'before_score' => 42,
        'after_score' => 58,
        'result_payload' => [
            'content_html' => '<p>Improved body copy with tighter readability.</p>',
            'change_summary' => 'Shortened the introduction and tightened sentence flow.',
            'inserted_text' => 'Improved body copy with tighter readability.',
            'removed_text' => 'Original body copy about content operations.',
            'diff_preview_html' => '<ins>Improved</ins> <del>Original</del>',
        ],
    ]);

    $response = $this->actingAs($user)->getJson(route('app.content.improvements.status', $content));

    $response->assertOk();

    expect($response->json('generated_html'))->toContain('Generated improvements')
        ->toContain('Shortened the introduction and tightened sentence flow.')
        ->toContain(route('app.drafts.show', ['draft' => $targetDraft]));
});

it('renders no useful changes generated for no_changes results', function () {
    [, , $user, $content] = makeContentImprovementContext();

    ContentImprovementRun::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'organization_id' => $content->workspace->organization_id,
        'type' => 'readability',
        'recommendation_label' => 'Shorten sentences and improve scanability',
        'recommendation_key' => 'readability:shorten-sentences-and-improve-scanability',
        'status' => ContentImprovementRun::STATUS_NO_CHANGES,
        'progress_percentage' => 100,
        'created_by' => (int) $user->id,
        'completed_at' => now(),
        'error_message' => 'The generated output matched the current source content.',
        'result_payload' => [
            'content_html' => '<p>Original body copy about content operations.</p>',
            'change_summary' => 'No useful changes generated.',
        ],
        'diagnostics' => [
            'no_changes_reason' => 'The generated output matched the current source content.',
        ],
    ]);

    $response = $this->actingAs($user)->getJson(route('app.content.improvements.status', $content));

    $response->assertOk();

    expect($response->json('generated_html'))->toContain('No useful changes generated')
        ->toContain('The generated output matched the current source content.')
        ->not->toContain('>Completed<');
});
