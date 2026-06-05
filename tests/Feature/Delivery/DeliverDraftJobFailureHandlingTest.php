<?php

use App\Jobs\DeliverDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Publication\ContentPublicationService;
use App\Support\Connectors\Results\PublicationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('marks delivery as failed when the draft no longer has a supported site relation', function () {
    [, $workspace, $site, $content, $draft] = makeDeliverDraftJobContext();

    $site->delete();

    Bus::dispatchSync(new DeliverDraftJob((string) $draft->id));

    $draft->refresh();

    expect($draft->delivery_status)->toBe('failed')
        ->and((string) $draft->delivery_last_error)->toBe('Delivery is not enabled for this site type.');
});

it('marks delivery failed immediately when publication service throws', function () {
    [, $workspace, , $content, $draft] = makeDeliverDraftJobContext();
    $destination = new ContentDestination(['id' => (string) Str::uuid(), 'type' => 'wordpress']);

    $publicationService = \Mockery::mock(ContentPublicationService::class);
    $publicationService->shouldReceive('resolveDestinationForContent')
        ->once()
        ->andReturn($destination);
    $publicationService->shouldReceive('markPublishing')->once();
    $publicationService->shouldReceive('publish')
        ->once()
        ->andReturn(PublicationResult::failure(
            errorCode: 'CONNECTOR_EXCEPTION',
            errorMessage: 'Connector timeout',
            retryable: true,
        ));
    $this->app->instance(ContentPublicationService::class, $publicationService);

    expect(fn () => Bus::dispatchSync(new DeliverDraftJob((string) $draft->id)))
        ->toThrow(RuntimeException::class, 'Connector timeout');
});

it('falls back to failed handling when the job crashes', function () {
    [, $workspace, , $content, $draft] = makeDeliverDraftJobContext();
    $destination = new ContentDestination(['id' => (string) Str::uuid(), 'type' => 'wordpress']);

    $publicationService = \Mockery::mock(ContentPublicationService::class);
    $publicationService->shouldReceive('resolveDestinationForContent')
        ->once()
        ->andReturn($destination);
    $publicationService->shouldReceive('markPublishing')
        ->once()
        ->andThrow(new RuntimeException('Service unavailable'));
    $this->app->instance(ContentPublicationService::class, $publicationService);

    $job = new DeliverDraftJob((string) $draft->id);

    try {
        $job->handle(
            app(ContentPublicationService::class),
        );
        $this->fail('Expected the delivery to fail.');
    } catch (RuntimeException $exception) {
        $job->failed($exception);
    }

    $draft->refresh();
    $content->refresh();

    expect($draft->delivery_status)->toBe('failed')
        ->and((string) $draft->delivery_last_error)->toBe('Service unavailable')
        ->and($content->publish_status)->toBe('failed');
});

function makeDeliverDraftJobContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Deliver Draft Org',
        'slug' => 'deliver-draft-org-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Deliver Draft Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Deliver Draft Site',
        'site_url' => 'https://deliver-draft.example.com',
        'base_url' => 'https://deliver-draft.example.com',
        'allowed_domains' => ['deliver-draft.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Deliver Draft Content',
        'primary_keyword' => 'deliver draft keyword',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wordpress',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Deliver Draft Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Deliver Draft',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Hello</h1>',
    ]);

    return [$organization, $workspace, $site, $content, $draft];
}
