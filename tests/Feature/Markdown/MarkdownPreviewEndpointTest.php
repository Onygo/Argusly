<?php

use App\Models\User;
use App\Models\StructuredAnswerBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the admin markdown preview for accessible content', function () {
    $this->withoutMiddleware();

    [$workspace, , $content] = makeMarkdownCommandContent();

    $user = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'owner',
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('app.content.markdown-preview', $content));

    $response->assertOk()
        ->assertSee('Markdown preview')
        ->assertSee('# Markdown Command Content', false)
        ->assertSee('Command body');
});

it('renders answer blocks and faq schema in the article preview', function () {
    $this->withoutMiddleware();

    [$workspace, , $content] = makeMarkdownCommandContent();
    $content->update([
        'answer_block_render_mode' => \App\Models\Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
        'answer_block_max_visible' => 2,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is Argusly?',
        'answer' => 'Argusly is a publishing workflow for structured AI content.',
        'entities' => ['Argusly'],
        'order' => 0,
    ]);

    $user = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'owner',
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('app.content.markdown-preview', $content))
        ->assertOk()
        ->assertSee('Rendered article preview')
        ->assertSee('data-answer-block="true"', false)
        ->assertSee('FAQPage', false);
});

it('serves the app markdown document route for accessible content', function () {
    $this->withoutMiddleware();

    [$workspace, , $content] = makeMarkdownCommandContent();

    $user = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'owner',
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('app.content.markdown', $content))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# Markdown Command Content', false);
});

it('serves the app answers document route for accessible content', function () {
    $this->withoutMiddleware();

    [$workspace, , $content] = makeMarkdownCommandContent();

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is Argusly?',
        'answer' => 'Argusly is a publishing workflow for structured AI content.',
        'entities' => ['Argusly'],
        'order' => 0,
    ]);

    $user = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'owner',
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson(route('app.content.answers', $content))
        ->assertOk()
        ->assertJsonPath('answers.0.question', 'What is Argusly?')
        ->assertJsonPath('answers.0.entities.0', 'Argusly');
});

function makeMarkdownCommandContent(): array
{
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Markdown Command Org ' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
        'slug' => 'markdown-command-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = \App\Models\Workspace::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Command Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en'],
    ]);

    $site = \App\Models\ClientSite::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Command Site',
        'site_url' => 'https://command.test',
        'allowed_domains' => ['command.test'],
        'is_active' => true,
    ]);

    $content = \App\Models\Content::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Markdown Command Content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'publish_status' => 'published',
        'delivery_status' => 'pending',
    ]);

    $version = \App\Models\ContentVersion::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => '<p>Command body</p>',
        'source' => 'pl',
    ]);

    $revision = \App\Models\ContentRevision::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>Command body</p>',
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    return [$workspace, $site, $content];
}
