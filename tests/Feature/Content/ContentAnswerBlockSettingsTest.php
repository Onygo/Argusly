<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware();
    config(['domains.base' => 'publishlayer.local']);
});

it('registers the answer block settings route', function () {
    expect(Route::has('app.content.answer-blocks.settings'))->toBeTrue()
        ->and(route('app.content.answer-blocks.settings', ['content' => 'content-id'], false))
        ->toContain('/content/content-id/answer-blocks/settings');
});

it('allows an authenticated owner to update answer block settings', function () {
    [, $owner, $content] = makeAnswerBlockSettingsContext();

    $response = appSubdomainRequest($this, $owner)->post(
        route('app.content.answer-blocks.settings', $content),
        [
            'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_BOTTOM,
            'answer_block_visibility' => Content::ANSWER_BLOCK_VISIBILITY_VISIBLE,
            'answer_block_position' => Content::ANSWER_BLOCK_POSITION_BOTTOM,
            'answer_block_max_visible' => 4,
        ]
    );

    $response->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'answers']))
        ->assertSessionHas('success', 'Answer block settings updated.');

    $fresh = $content->fresh();

    expect($fresh->answer_block_render_mode)->toBe(Content::ANSWER_BLOCK_RENDER_MODE_BOTTOM)
        ->and($fresh->answer_block_visibility)->toBe(Content::ANSWER_BLOCK_VISIBILITY_VISIBLE)
        ->and($fresh->answer_block_position)->toBe(Content::ANSWER_BLOCK_POSITION_BOTTOM)
        ->and($fresh->answer_block_max_visible)->toBe(4);
});

it('forbids unauthorized users from updating answer block settings', function () {
    [$workspace, , $content] = makeAnswerBlockSettingsContext();
    $viewer = makeAnswerBlockSettingsUser($workspace->organization_id, 'viewer');

    appSubdomainRequest($this, $viewer)->post(
        route('app.content.answer-blocks.settings', $content),
        [
            'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_INLINE,
            'answer_block_visibility' => Content::ANSWER_BLOCK_VISIBILITY_VISIBLE,
            'answer_block_position' => Content::ANSWER_BLOCK_POSITION_INLINE,
        ]
    )->assertForbidden();
});

it('rejects invalid answer block setting values', function () {
    [, $owner, $content] = makeAnswerBlockSettingsContext();

    $response = appSubdomainRequest($this, $owner)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'answers']))
        ->post(route('app.content.answer-blocks.settings', $content), [
            'answer_block_render_mode' => 'invalid-mode',
            'answer_block_visibility' => 'invalid-visibility',
            'answer_block_position' => 'invalid-position',
            'answer_block_max_visible' => 99,
        ]);

    $response->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'answers']))
        ->assertSessionHasErrors([
            'answer_block_render_mode',
            'answer_block_visibility',
            'answer_block_position',
            'answer_block_max_visible',
        ]);
});

it('persists valid values in the database', function () {
    [, $owner, $content] = makeAnswerBlockSettingsContext();

    appSubdomainRequest($this, $owner)->post(route('app.content.answer-blocks.settings', $content), [
        'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_DISABLED,
        'answer_block_visibility' => Content::ANSWER_BLOCK_VISIBILITY_HIDDEN,
        'answer_block_position' => Content::ANSWER_BLOCK_POSITION_AI_OPTIMIZED,
        'answer_block_max_visible' => 2,
    ])->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'answers']));

    $this->assertDatabaseHas('contents', [
        'id' => (string) $content->id,
        'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_DISABLED,
        'answer_block_visibility' => Content::ANSWER_BLOCK_VISIBILITY_HIDDEN,
        'answer_block_position' => Content::ANSWER_BLOCK_POSITION_AI_OPTIMIZED,
        'answer_block_max_visible' => 2,
    ]);
});

it('supports json responses for later client-side usage', function () {
    [, $owner, $content] = makeAnswerBlockSettingsContext();

    $response = appSubdomainRequest($this, $owner)->postJson(
        route('app.content.answer-blocks.settings', $content),
        [
            'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_INLINE,
            'answer_block_visibility' => Content::ANSWER_BLOCK_VISIBILITY_VISIBLE,
            'answer_block_position' => Content::ANSWER_BLOCK_POSITION_INLINE,
            'answer_block_max_visible' => 5,
        ]
    );

    $response->assertOk()
        ->assertJsonPath('message', 'Answer block settings updated.')
        ->assertJsonPath('settings.answer_block_render_mode', Content::ANSWER_BLOCK_RENDER_MODE_INLINE)
        ->assertJsonPath('settings.answer_block_visibility', Content::ANSWER_BLOCK_VISIBILITY_VISIBLE)
        ->assertJsonPath('settings.answer_block_position', Content::ANSWER_BLOCK_POSITION_INLINE)
        ->assertJsonPath('settings.answer_block_max_visible', 5);
});

function makeAnswerBlockSettingsContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Answer Block Settings Org ' . Str::random(4),
        'slug' => 'answer-block-settings-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Answer Block Settings Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en'],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Answer Block Settings Site',
        'site_url' => 'https://answer-block-settings.example.com',
        'allowed_domains' => ['answer-block-settings.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $owner = makeAnswerBlockSettingsUser($workspace->organization_id, 'owner');

    $content = Content::withoutEvents(fn () => Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Answer Block Settings Content',
        'language' => 'en',
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'publish_status' => 'draft',
        'created_by' => (int) $owner->id,
        'updated_by' => (int) $owner->id,
    ]));

    return [$workspace, $owner, $content];
}

function makeAnswerBlockSettingsUser(int $organizationId, string $role): User
{
    return User::query()->create([
        'name' => 'Answer Block Settings ' . ucfirst($role),
        'organization_id' => $organizationId,
        'password' => bcrypt('secret'),
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
        'email' => 'answer-block-settings-' . $role . '-' . Str::lower(Str::random(6)) . '@example.com',
    ]);
}

function appSubdomainRequest(object $testCase, User $user)
{
    return $testCase
        ->withHeaders(['Host' => 'app.publishlayer.local'])
        ->actingAs($user);
}
