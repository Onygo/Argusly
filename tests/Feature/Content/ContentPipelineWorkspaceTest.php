<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function contentPipelineContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Pipeline '.$slug,
        'slug' => 'content-pipeline-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Content Pipeline Workspace '.$slug,
        'display_name' => 'Content Pipeline Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Content Pipeline Site '.$slug,
        'site_url' => 'https://'.$slug.'.content-pipeline.test',
        'base_url' => 'https://'.$slug.'.content-pipeline.test',
        'allowed_domains' => [$slug.'.content-pipeline.test'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function contentPipelineContent(Workspace $workspace, ClientSite $site, string $title, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => $title,
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
    ], $overrides));
}

function contentPipelineBrief(ClientSite $site, string $title, array $overrides = []): Brief
{
    return Brief::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'title' => $title,
        'status' => 'draft',
        'source' => 'manual',
        'progress' => 0,
        'language' => 'en',
        'content_type' => 'blog',
        'intent' => 'inform',
        'output_type' => 'article',
    ], $overrides));
}

function contentPipelineDraft(ClientSite $site, string $title, array $overrides = []): Draft
{
    $briefId = $overrides['brief_id'] ?? null;

    if (! $briefId) {
        $brief = contentPipelineBrief($site, $title.' Brief', [
            'content_id' => $overrides['content_id'] ?? null,
        ]);
        $briefId = $brief->id;
    }

    return Draft::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'brief_id' => $briefId,
        'client_site_id' => $site->id,
        'title' => $title,
        'status' => Draft::STATUS_DRAFT,
        'output_type' => 'article',
        'language' => 'en',
        'content_html' => '<p>Pipeline draft.</p>',
        'meta' => [],
        'links' => [],
    ], $overrides));
}

it('renders existing content work as a five stage user-facing pipeline', function (): void {
    $context = contentPipelineContext('lanes');

    contentPipelineBrief($context['site'], 'Idea for AI visibility guide');

    $inProgress = contentPipelineContent($context['workspace'], $context['site'], 'Drafting AI visibility guide');
    contentPipelineDraft($context['site'], 'Drafting AI visibility guide', [
        'content_id' => $inProgress->id,
        'status' => Draft::STATUS_DRAFT,
    ]);

    $review = contentPipelineContent($context['workspace'], $context['site'], 'Review AI visibility guide', [
        'status' => 'review',
    ]);
    contentPipelineDraft($context['site'], 'Review AI visibility guide', [
        'content_id' => $review->id,
        'status' => Draft::STATUS_READY_FOR_REVIEW,
    ]);

    $ready = contentPipelineContent($context['workspace'], $context['site'], 'Ready AI visibility guide', [
        'status' => 'approved',
        'publish_status' => 'scheduled',
    ]);
    contentPipelineDraft($context['site'], 'Ready AI visibility guide', [
        'content_id' => $ready->id,
        'status' => Draft::STATUS_APPROVED_FOR_PUBLISHING,
    ]);

    contentPipelineContent($context['workspace'], $context['site'], 'Published AI visibility guide', [
        'status' => 'published',
        'publish_status' => 'published',
        'first_published_at' => now(),
        'published_url' => 'https://lanes.content-pipeline.test/published-ai-visibility-guide',
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.content.pipeline.index'))
        ->assertOk()
        ->assertSee('Content Pipeline')
        ->assertSee('Ideas')
        ->assertSee('In Progress')
        ->assertSee('Review')
        ->assertSee('Ready')
        ->assertSee('Published')
        ->assertSee('Idea for AI visibility guide')
        ->assertSee('Drafting AI visibility guide')
        ->assertSee('Review AI visibility guide')
        ->assertSee('Ready AI visibility guide')
        ->assertSee('Published AI visibility guide')
        ->assertSee('Advanced')
        ->assertDontSee('Briefs')
        ->assertDontSee('Drafts');
});

it('filters the pipeline by a user-facing lane and keeps existing content routes working', function (): void {
    $context = contentPipelineContext('filter');
    $published = contentPipelineContent($context['workspace'], $context['site'], 'Only Published Piece', [
        'status' => 'published',
        'publish_status' => 'published',
        'first_published_at' => now(),
    ]);
    contentPipelineContent($context['workspace'], $context['site'], 'Hidden Draft Piece');

    $this->actingAs($context['user'])
        ->get(route('app.content.pipeline.index', ['lane' => 'published']))
        ->assertOk()
        ->assertSee('Only Published Piece')
        ->assertDontSee('Hidden Draft Piece')
        ->assertSee('Show all stages');

    $this->actingAs($context['user'])
        ->get(route('app.content.show', $published))
        ->assertOk()
        ->assertSee('Only Published Piece');
});

it('does not duplicate content when its brief and draft are also in progress', function (): void {
    $context = contentPipelineContext('dedupe');
    $content = contentPipelineContent($context['workspace'], $context['site'], 'Legal and Ethical Content Guide', [
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    $brief = contentPipelineBrief($context['site'], 'Legal and Ethical Content Guide');
    contentPipelineDraft($context['site'], 'Legal and Ethical Content Guide', [
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'status' => Draft::STATUS_DRAFT,
    ]);

    $response = $this->actingAs($context['user'])
        ->get(route('app.content.pipeline.index'))
        ->assertOk()
        ->assertSee('In Progress')
        ->assertSee('Legal and Ethical Content Guide');

    expect(substr_count($response->getContent(), 'Legal and Ethical Content Guide'))->toBe(1);
});

it('shows a generated draft and its content workspace as one pipeline item', function (): void {
    $context = contentPipelineContext('generated-draft-dedupe');
    $brief = contentPipelineBrief($context['site'], 'Legal and Ethical Considerations of Web Crawling');
    contentPipelineDraft($context['site'], 'Legal and Ethical Considerations of Web Crawling', [
        'brief_id' => $brief->id,
        'status' => 'generated',
        'delivery_status' => 'published',
        'delivered_at' => now(),
    ]);

    $response = $this->actingAs($context['user'])
        ->get(route('app.content.pipeline.index'))
        ->assertOk()
        ->assertSee('Published')
        ->assertSee('Legal and Ethical Considerations of Web Crawling');

    expect(substr_count($response->getContent(), 'Legal and Ethical Considerations of Web Crawling'))->toBe(1);
});
