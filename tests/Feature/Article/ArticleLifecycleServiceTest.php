<?php

use App\Jobs\PublishToWordPressJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Article\ArticleLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeArticleLifecycleTestContext(string $siteType = 'wordpress'): array
{
    $organization = Organization::query()->create([
        'name' => 'Lifecycle Test Org',
        'slug' => 'lifecycle-test-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Lifecycle Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'Lifecycle Test Site',
        'site_url' => 'https://lifecycle-test.example.com',
        'base_url' => 'https://lifecycle-test.example.com',
        'allowed_domains' => ['lifecycle-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Lifecycle Test Article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Lifecycle Test Brief',
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
        'title' => 'Lifecycle Test Draft',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Test Content</h1>',
    ]);

    return compact('organization', 'workspace', 'site', 'content', 'brief', 'draft');
}

describe('ArticleLifecycleService Scheduling', function () {
    it('schedules an article for publishing', function () {
        $ctx = makeArticleLifecycleTestContext();
        $publishAt = now()->addDay();

        $result = app(ArticleLifecycleService::class)->schedule($ctx['content'], $publishAt);

        expect($result['scheduled'])->toBeTrue()
            ->and($result['publish_at'])->not->toBeNull();

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('scheduled')
            ->and($ctx['content']->scheduled_publish_at->format('Y-m-d'))->toBe($publishAt->format('Y-m-d'));
    });

    it('cancels a scheduled publish', function () {
        $ctx = makeArticleLifecycleTestContext();
        $ctx['content']->update([
            'publish_status' => 'scheduled',
            'scheduled_publish_at' => now()->addDay(),
        ]);

        $result = app(ArticleLifecycleService::class)->cancelSchedule($ctx['content']);

        expect($result['cancelled'])->toBeTrue();

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('draft')
            ->and($ctx['content']->scheduled_publish_at)->toBeNull();
    });

    it('returns false when cancelling non-scheduled article', function () {
        $ctx = makeArticleLifecycleTestContext();

        $result = app(ArticleLifecycleService::class)->cancelSchedule($ctx['content']);

        expect($result['cancelled'])->toBeFalse()
            ->and($result['message'])->toContain('not scheduled');
    });
});

describe('ArticleLifecycleService WordPress Publishing', function () {
    it('queues a WordPress publish job', function () {
        Queue::fake();

        $ctx = makeArticleLifecycleTestContext('wordpress');

        $result = app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        expect($result['queued'])->toBeTrue()
            ->and($result['publication_id'])->not->toBeNull();

        Queue::assertPushed(PublishToWordPressJob::class, function ($job) use ($ctx) {
            return $job->contentId === (string) $ctx['content']->id
                && filled($job->publicationId);
        });

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('publishing');
    });

    it('creates a publication record when publishing', function () {
        Queue::fake();

        $ctx = makeArticleLifecycleTestContext('wordpress');

        app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        $publication = ContentPublication::query()
            ->where('content_id', $ctx['content']->id)
            ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
            ->first();

        expect($publication)->not->toBeNull();
    });

    it('fails gracefully when no draft exists', function () {
        Queue::fake();

        $ctx = makeArticleLifecycleTestContext('wordpress');
        $ctx['draft']->forceDelete();

        $result = app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        expect($result['published'])->toBeFalse()
            ->and($result['queued'])->toBeFalse()
            ->and($result['message'])->toContain('No draft found');

        Queue::assertNotPushed(PublishToWordPressJob::class);
    });
});

describe('ArticleLifecycleService Laravel Publishing', function () {
    it('publishes immediately to Laravel connector', function () {
        $ctx = makeArticleLifecycleTestContext('laravel');

        $result = app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        expect($result['published'])->toBeTrue()
            ->and($result['queued'])->toBeFalse();

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('published')
            ->and($ctx['content']->status)->toBe('published')
            ->and($ctx['content']->delivery_status)->toBe('delivered');
    });

    it('creates a ContentPublishTarget for Laravel', function () {
        $ctx = makeArticleLifecycleTestContext('laravel');

        app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        $target = ContentPublishTarget::query()
            ->where('content_id', $ctx['content']->id)
            ->first();

        expect($target)->not->toBeNull()
            ->and($target->target_type)->toContain('laravel');
    });
});

describe('ArticleLifecycleService canPublish', function () {
    it('returns true when article can be published', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeTrue()
            ->and($result['reason'])->toBeNull();
    });

    it('returns false when no draft exists', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');
        $ctx['draft']->delete();

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeFalse()
            ->and($result['reason'])->toContain('No draft found');
    });

    it('returns false when article is already publishing', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');
        $ctx['content']->update(['publish_status' => 'publishing']);

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeFalse()
            ->and($result['reason'])->toContain('already being published');
    });

    it('returns false when no site is associated', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');
        $ctx['content']->update(['client_site_id' => null]);
        $ctx['content']->unsetRelation('clientSite');

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeFalse()
            ->and($result['reason'])->toContain('No site associated');
    });
});

describe('ArticleLifecycleService Status Transitions', function () {
    it('marks article as published locally', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');
        $publishedUrl = 'https://example.com/published-article';

        $result = app(ArticleLifecycleService::class)->markPublished($ctx['content'], $publishedUrl);

        expect($result['published'])->toBeTrue();

        $ctx['content']->refresh();
        expect($ctx['content']->status)->toBe('published')
            ->and($ctx['content']->publish_status)->toBe('published')
            ->and($ctx['content']->delivery_status)->toBe('delivered')
            ->and($ctx['content']->published_url)->toBe($publishedUrl);
    });

    it('reverts article to draft status', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');
        $ctx['content']->update([
            'publish_status' => 'published',
            'scheduled_publish_at' => now(),
        ]);

        $result = app(ArticleLifecycleService::class)->revertToDraft($ctx['content']);

        expect($result['reverted'])->toBeTrue();

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('draft')
            ->and($ctx['content']->scheduled_publish_at)->toBeNull();
    });
});

describe('ArticleLifecycleService getCanonicalPublication', function () {
    it('returns the most recent delivered publication', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');

        $olderPublication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now()->subDay(),
        ]);

        $newerPublication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now(),
        ]);

        $result = app(ArticleLifecycleService::class)->getCanonicalPublication($ctx['content']);

        expect((string) $result->id)->toBe((string) $newerPublication->id);
    });

    it('returns null when no publications exist', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');

        $result = app(ArticleLifecycleService::class)->getCanonicalPublication($ctx['content']);

        expect($result)->toBeNull();
    });

    it('prefers delivered over pending publications', function () {
        $ctx = makeArticleLifecycleTestContext('wordpress');

        $pendingPublication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_PENDING,
            'created_at' => now(),
        ]);

        $deliveredPublication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);

        $result = app(ArticleLifecycleService::class)->getCanonicalPublication($ctx['content']);

        expect((string) $result->id)->toBe((string) $deliveredPublication->id);
    });
});
