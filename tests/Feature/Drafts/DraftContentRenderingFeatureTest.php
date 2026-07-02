<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HumanContent\HumanContentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders draft detail with content preview tabs and article wrapper', function () {
    [$user, $draft] = createDraftRenderingContext('# My heading' . "\n\n" . '- Item one');
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $this->actingAs($user)
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertSee('Preview')
        ->assertSee('Edit')
        ->assertSee('Split')
        ->assertSee('pl-content-prose', false)
        ->assertSee('<h1>My heading</h1>', false)
        ->assertSee('<li>Item one</li>', false);
});

it('sanitizes unsafe draft html in preview output', function () {
    [$user, $draft] = createDraftRenderingContext('<script>alert(1)</script><p>Safe paragraph</p><a href="javascript:alert(2)">Bad link</a>');
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $response = $this->actingAs($user)->get(route('app.drafts.show', $draft));

    $response->assertOk()
        ->assertSee('Safe paragraph')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('href="javascript:alert(2)"', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('surfaces the publish action on linked draft detail pages', function () {
    [$user, $draft] = createDraftRenderingContext('<p>Ready to publish.</p>');
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $content = Content::query()->create([
        'workspace_id' => (string) $draft->clientSite->workspace_id,
        'client_site_id' => (string) $draft->client_site_id,
        'title' => 'Ready to publish',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);
    $draft->update(['content_id' => (string) $content->id]);
    $draft->brief?->update(['content_id' => (string) $content->id]);

    $this->actingAs($user)
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertSee('Publish article')
        ->assertSee('Publishing')
        ->assertSee(route('app.content.publish-now', $content), false)
        ->assertSee('name="locale" value="en"', false);
});

it('requires an explicit human content gate override on blocked draft publish actions', function () {
    [$user, $draft] = createDraftRenderingContext('<p>Needs a human review before publishing.</p>');
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $content = Content::query()->create([
        'workspace_id' => (string) $draft->clientSite->workspace_id,
        'client_site_id' => (string) $draft->client_site_id,
        'title' => 'Blocked publish draft',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW,
        'publish_error' => 'Human Content publishing gate blocked auto-publication: Human content score is below 70.',
        'delivery_status' => 'pending',
    ]);
    $draft->update([
        'content_id' => (string) $content->id,
        'meta' => [
            'publish_gate_status' => HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW,
            'human_content_gate' => [
                'passed' => false,
                'status' => HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW,
                'reasons' => ['Human content score is below 70.'],
            ],
        ],
    ]);
    $draft->brief?->update(['content_id' => (string) $content->id]);

    $this->actingAs($user)
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertSee('name="human_content_override"', false)
        ->assertSee('required', false)
        ->assertSee('Override and publish')
        ->assertSee('Human Content publishing gate blocked auto-publication');
});

/**
 * @return array{0:User,1:Draft}
 */
function createDraftRenderingContext(string $contentHtml): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Rendering Org',
        'slug' => 'draft-rendering-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Rendering Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Draft Rendering Site',
        'site_url' => 'https://draft-rendering.example.test',
        'allowed_domains' => ['draft-rendering.example.test'],
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Draft Rendering User',
        'email' => 'draft-rendering-' . Str::random(6) . '@example.com',
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
        'title' => 'Draft rendering brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => (string) $brief->id,
        'client_site_id' => (string) $site->id,
        'status' => 'generated',
        'title' => 'Draft rendering test',
        'output_type' => 'kb_article',
        'content_html' => $contentHtml,
    ]);

    return [$user, $draft];
}
