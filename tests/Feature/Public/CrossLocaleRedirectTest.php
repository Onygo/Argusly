<?php

use App\Events\Agents\ContentPublished;
use App\Listeners\Marketing\InvalidateCrossLocaleRedirectsOnPublish;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Publication\ContentPublicationStateService;
use App\Services\PublicBlog\PublicBlogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('argusly_connector.public_blog.use_connector', false);
    config()->set('argusly_connector.public_blog.fallback_to_local', true);

    $this->organization = Organization::query()->create([
        'name' => 'Cross Locale Test Org',
        'slug' => 'cross-locale-test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $this->workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Cross Locale Test Workspace',
        'organization_id' => $this->organization->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function createNlSourceContent(Workspace $workspace, string $slug = 'test-nl-article'): Content
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'NL Test Article',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'publish_url_key' => $slug,
        'published_url' => url('/nl/blog/' . $slug),
        'seo_canonical' => url('/nl/blog/' . $slug),
        'is_source_locale' => true,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'published_snapshot',
        'body' => '<p>Dit is een Nederlandse test artikel.</p>',
        'meta' => [
            'excerpt' => 'NL test excerpt',
            'slug' => $slug,
            'published_at' => now()->subDay()->toIso8601String(),
        ],
        'source' => 'pl',
    ]);

    $content->update(['current_version_id' => (string) $version->id]);

    return $content->fresh(['currentVersion']);
}

function createEnTranslationContent(Content $source, string $enSlug = 'test-en-article', bool $published = false): Content
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'title' => 'EN Test Article',
        'language' => 'en',
        'type' => 'article',
        'status' => $published ? 'published' : 'draft',
        'publish_status' => $published ? 'published' : 'draft',
        'source' => 'translation',
        'publish_url_key' => $enSlug,
        'published_url' => $published ? url('/en/blog/' . $enSlug) : null,
        'seo_canonical' => $published ? url('/en/blog/' . $enSlug) : null,
        'is_source_locale' => false,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'nl',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => $published ? 'published_snapshot' : 'draft',
        'body' => '<p>This is an English test article.</p>',
        'meta' => [
            'excerpt' => 'EN test excerpt',
            'slug' => $enSlug,
            'published_at' => $published ? now()->subDay()->toIso8601String() : null,
        ],
        'source' => 'pl',
    ]);

    $content->update(['current_version_id' => (string) $version->id]);

    if ($published) {
        ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'locale' => 'en',
            'provider' => 'laravel',
            'remote_id' => (string) Str::uuid(),
            'remote_url' => url('/en/blog/' . $enSlug),
            'remote_status' => 'published',
            'delivery_status' => 'delivered',
            'last_delivered_at' => now(),
        ]);
    }

    return $content->fresh(['currentVersion', 'publications']);
}

function createCrossLocaleRedirect(Content $sourceContent, string $enSlug, string $nlSlug): MarketingBlogRedirect
{
    return MarketingBlogRedirect::query()->create([
        'id' => (string) Str::uuid(),
        'source_path' => '/en/blog/' . $enSlug,
        'source_locale' => 'en',
        'source_slug' => $enSlug,
        'target_path' => '/nl/blog/' . $nlSlug,
        'target_locale' => 'nl',
        'target_slug' => $nlSlug,
        'target_content_id' => (string) $sourceContent->id,
        'redirect_kind' => 'legacy_locale_mismatch',
        'is_active' => true,
        'meta' => ['reason' => 'test'],
    ]);
}

function createSameLocaleRedirect(Content $content, string $oldSlug, string $newSlug): MarketingBlogRedirect
{
    $locale = $content->localeCode();

    return MarketingBlogRedirect::query()->create([
        'id' => (string) Str::uuid(),
        'source_path' => '/' . $locale . '/blog/' . $oldSlug,
        'source_locale' => $locale,
        'source_slug' => $oldSlug,
        'target_path' => '/' . $locale . '/blog/' . $newSlug,
        'target_locale' => $locale,
        'target_slug' => $newSlug,
        'target_content_id' => (string) $content->id,
        'redirect_kind' => 'legacy_locale_mismatch',
        'is_active' => true,
        'meta' => ['reason' => 'slug_change'],
    ]);
}

describe('PublicBlogService::legacyRedirectUrlForSlug', function () {
    it('returns cross-locale redirect URL when no published translation exists', function () {
        $nlContent = createNlSourceContent($this->workspace, 'nl-test-slug');
        createCrossLocaleRedirect($nlContent, 'en-legacy-slug', 'nl-test-slug');

        $service = app(PublicBlogService::class);
        $result = $service->legacyRedirectUrlForSlug('en-legacy-slug', 'en');

        expect($result)->toBe('/nl/blog/nl-test-slug');
    });

    it('returns null for cross-locale redirect when published translation exists', function () {
        $nlContent = createNlSourceContent($this->workspace, 'nl-test-slug');
        createEnTranslationContent($nlContent, 'en-published-slug', published: true);
        createCrossLocaleRedirect($nlContent, 'en-published-slug', 'nl-test-slug');

        $service = app(PublicBlogService::class);

        // Clear cache to ensure fresh lookup
        cache()->forget(sprintf('redirect_locale_check.%s.%s', $nlContent->id, 'en'));

        $result = $service->legacyRedirectUrlForSlug('en-published-slug', 'en');

        expect($result)->toBeNull();
    });

    it('always returns same-locale redirect URL regardless of publication status', function () {
        $nlContent = createNlSourceContent($this->workspace, 'new-nl-slug');
        createSameLocaleRedirect($nlContent, 'old-nl-slug', 'new-nl-slug');

        $service = app(PublicBlogService::class);
        $result = $service->legacyRedirectUrlForSlug('old-nl-slug', 'nl');

        expect($result)->toBe('/nl/blog/new-nl-slug');
    });
});

describe('InvalidateCrossLocaleRedirectsOnPublish listener', function () {
    it('deactivates cross-locale redirects when EN translation is published', function () {
        $nlContent = createNlSourceContent($this->workspace, 'nl-test-slug');
        $redirect = createCrossLocaleRedirect($nlContent, 'en-legacy-slug', 'nl-test-slug');
        $enContent = createEnTranslationContent($nlContent, 'en-new-slug', published: true);

        // Manually trigger the listener
        $event = new ContentPublished(
            contentId: (string) $enContent->id,
            draftId: null,
            source: 'test'
        );

        $listener = app(InvalidateCrossLocaleRedirectsOnPublish::class);
        $listener->handle($event);

        $redirect->refresh();

        expect($redirect->is_active)->toBeFalse()
            ->and($redirect->meta['superseded_reason'])->toBe('published_locale_translation')
            ->and($redirect->meta['superseded_by_content_id'])->toBe((string) $enContent->id);
    });

    it('does not deactivate same-locale redirects', function () {
        $nlContent = createNlSourceContent($this->workspace, 'new-nl-slug');
        $redirect = createSameLocaleRedirect($nlContent, 'old-nl-slug', 'new-nl-slug');

        $event = new ContentPublished(
            contentId: (string) $nlContent->id,
            draftId: null,
            source: 'test'
        );

        $listener = app(InvalidateCrossLocaleRedirectsOnPublish::class);
        $listener->handle($event);

        $redirect->refresh();

        expect($redirect->is_active)->toBeTrue();
    });
});

describe('RepairLocalizedCanonicalsCommand', function () {
    it('reports invalid cross-locale redirects in dry-run mode', function () {
        $nlContent = createNlSourceContent($this->workspace, 'nl-test-slug');
        createEnTranslationContent($nlContent, 'en-slug', published: true);
        createCrossLocaleRedirect($nlContent, 'en-slug', 'nl-test-slug');

        // Clear cache
        cache()->forget(sprintf('redirect_locale_check.%s.%s', $nlContent->id, 'en'));

        $this->artisan('content:repair-localized-canonicals', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutput('Found 1 cross-locale redirects to scan.')
            ->expectsOutputToContain('Dry run only');

        // Redirect should still be active
        $this->assertDatabaseHas('marketing_blog_redirects', [
            'source_slug' => 'en-slug',
            'is_active' => true,
        ]);
    });

    it('deactivates invalid cross-locale redirects with --fix flag', function () {
        $nlContent = createNlSourceContent($this->workspace, 'nl-test-slug');
        createEnTranslationContent($nlContent, 'en-slug', published: true);
        $redirect = createCrossLocaleRedirect($nlContent, 'en-slug', 'nl-test-slug');

        // Clear cache
        cache()->forget(sprintf('redirect_locale_check.%s.%s', $nlContent->id, 'en'));

        $this->artisan('content:repair-localized-canonicals', ['--fix' => true])
            ->assertSuccessful();

        $redirect->refresh();

        expect($redirect->is_active)->toBeFalse()
            ->and($redirect->meta['superseded_reason'])->toBe('repair_command');
    });

    it('filters by content-id when specified', function () {
        $nlContent1 = createNlSourceContent($this->workspace, 'nl-slug-1');
        $nlContent2 = createNlSourceContent($this->workspace, 'nl-slug-2');

        createEnTranslationContent($nlContent1, 'en-slug-1', published: true);
        createEnTranslationContent($nlContent2, 'en-slug-2', published: true);

        $redirect1 = createCrossLocaleRedirect($nlContent1, 'en-slug-1', 'nl-slug-1');
        $redirect2 = createCrossLocaleRedirect($nlContent2, 'en-slug-2', 'nl-slug-2');

        // Clear caches
        cache()->forget(sprintf('redirect_locale_check.%s.%s', $nlContent1->id, 'en'));
        cache()->forget(sprintf('redirect_locale_check.%s.%s', $nlContent2->id, 'en'));

        $this->artisan('content:repair-localized-canonicals', [
            '--fix' => true,
            '--content-id' => (string) $nlContent1->id,
        ])->assertSuccessful();

        $redirect1->refresh();
        $redirect2->refresh();

        // Only redirect1 should be deactivated
        expect($redirect1->is_active)->toBeFalse()
            ->and($redirect2->is_active)->toBeTrue();
    });

    it('skips valid redirects where no published translation exists', function () {
        $nlContent = createNlSourceContent($this->workspace, 'nl-test-slug');
        // Create unpublished EN translation
        createEnTranslationContent($nlContent, 'en-slug', published: false);
        $redirect = createCrossLocaleRedirect($nlContent, 'en-slug', 'nl-test-slug');

        // Clear cache
        cache()->forget(sprintf('redirect_locale_check.%s.%s', $nlContent->id, 'en'));

        $this->artisan('content:repair-localized-canonicals', ['--fix' => true])
            ->assertSuccessful();

        $redirect->refresh();

        // Redirect should still be active because EN is not published
        expect($redirect->is_active)->toBeTrue();
    });
});

describe('UI cross-locale redirect display', function () {
    it('does not show cross-locale redirect when source_locale has published variant', function () {
        // This test would require setting up a full user/auth context
        // Marking as pending for now
    })->skip('Requires full user authentication context');
});
