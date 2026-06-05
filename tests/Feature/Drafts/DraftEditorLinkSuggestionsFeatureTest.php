<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('does not render link suggestions in draft editor when feature is disabled', function () {
    config(['features.draft_link_suggestions' => false]);

    [$user, $draft] = createDraftEditorContext();
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $this->actingAs($user)
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertDontSee('Link Suggestions')
        ->assertDontSee('Regenerate');
});

it('does not query link suggestions when opening draft editor with feature disabled', function () {
    config(['features.draft_link_suggestions' => false]);

    [$user, $draft] = createDraftEditorContext();
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('app.drafts.show', ['draft' => $draft, 'debug_links' => 1]))
        ->assertOk();

    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn ($query) => strtolower((string) $query));

    expect($queries->contains(fn (string $query): bool => str_contains($query, 'link_suggestions')))->toBeFalse();
});

it('shows compact seo sync capability summary in draft metadata', function () {
    config(['features.draft_link_suggestions' => false]);

    [$user, $draft] = createDraftEditorContext();
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $this->actingAs($user)
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertSee('SEO metadata')
        ->assertSee('No SEO plugin detected')
        ->assertSee('syncable')
        ->assertSee('plugin-only')
        ->assertSee('SEO title:')
        ->assertDontSee('Twitter title:');
});

it('does not offer draft translation when the source draft is not ready', function () {
    config(['features.draft_link_suggestions' => false]);

    [$user, $draft] = createDraftEditorContext(['enabled_content_languages' => ['en', 'nl']]);
    $draft->forceFill([
        'status' => 'generated',
        'content_html' => '<p>Generated, but not ready yet.</p>',
    ])->save();
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $this->actingAs($user)
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertSee('Translation unavailable')
        ->assertSee('Source content must be available as a ready draft or a delivered/published version')
        ->assertDontSee('Queue translation');
});

/**
 * @return array{0:User,1:Draft}
 */
function createDraftEditorContext(array $workspaceOverrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Editor Org',
        'slug' => 'draft-editor-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create(array_merge([
        'name' => 'Draft Editor Workspace',
        'organization_id' => $organization->id,
    ], $workspaceOverrides));

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Draft Editor Site',
        'site_url' => 'https://draft-editor.example.test',
        'allowed_domains' => ['draft-editor.example.test'],
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Draft Editor User',
        'email' => 'draft-editor-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'created_by_user_id' => (int) $user->id,
        'status' => 'ready',
        'progress' => 1,
        'title' => 'Draft editor brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => (string) $brief->id,
        'client_site_id' => (string) $site->id,
        'status' => 'generated',
        'title' => 'Draft editor test',
        'output_type' => 'kb_article',
        'content_html' => '<p>Draft content.</p>',
    ]);

    return [$user, $draft];
}
