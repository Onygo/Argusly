<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('argusly.launch.soft_launch_mode', false);
    config()->set('argusly_connector.public_blog.use_connector', false);
    config()->set('argusly_connector.public_blog.fallback_to_local', true);
    config()->set('marketing.blog_source.mode', 'workspace');

    [$this->workspace, $this->site] = makeRssFeedContext();
    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function makeRssFeedContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'RSS Feed Org',
        'slug' => 'rss-feed-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'RSS Feed Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'RSS Feed Site',
        'site_url' => 'https://rss-feed.example.com',
        'base_url' => 'https://rss-feed.example.com',
        'allowed_domains' => ['rss-feed.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}

function makeRssArticle(Workspace $workspace, ClientSite $site, array $attributes = []): Content
{
    $locale = (string) ($attributes['language'] ?? 'nl');
    $title = (string) ($attributes['title'] ?? 'RSS artikel');
    $slug = (string) ($attributes['slug'] ?? Str::slug($title));
    $publishedAt = $attributes['published_at'] ?? now()->subHour();
    $publishedAtValue = $publishedAt instanceof \Carbon\CarbonInterface
        ? $publishedAt->toIso8601String()
        : (string) $publishedAt;

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => $title,
        'language' => $locale,
        'type' => 'article',
        'status' => (string) ($attributes['status'] ?? 'published'),
        'publish_status' => (string) ($attributes['publish_status'] ?? 'published'),
        'delivery_status' => 'delivered',
        'publish_url_key' => $slug,
        'published_url' => 'https://rss-feed.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slug], $locale, false),
        'source' => 'manual',
        'is_source_locale' => (bool) ($attributes['is_source_locale'] ?? true),
        'translation_source_content_id' => $attributes['translation_source_content_id'] ?? null,
        'translation_source_version_id' => $attributes['translation_source_version_id'] ?? null,
        'translation_source_locale' => $attributes['translation_source_locale'] ?? null,
        'scheduled_publish_at' => $attributes['scheduled_publish_at'] ?? null,
        'updated_at' => $attributes['updated_at'] ?? now(),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_REVISION,
        'body' => (string) ($attributes['body'] ?? '<p>RSS body</p>'),
        'meta' => [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => (string) ($attributes['excerpt'] ?? 'RSS excerpt'),
            'published_at' => $publishedAtValue,
        ],
        'source' => ContentVersion::SOURCE_ARGUSLY,
    ]);

    $content->forceFill(['current_version_id' => (string) $version->id])->save();

    return $content->fresh(['currentVersion']);
}

function rssXml(string $content): SimpleXMLElement
{
    return simplexml_load_string($content);
}

it('returns valid rss xml with the correct content type', function () {
    makeRssArticle($this->workspace, $this->site, ['title' => 'Feed artikel', 'slug' => 'feed-artikel']);

    $response = $this->get(LocalizedMarketingUrl::route('public.blog.rss', [], 'nl', false));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    $xml = rssXml($response->getContent());

    expect($xml->getName())->toBe('rss')
        ->and((string) $xml->channel->item[0]->link)->toContain('/nl/blog/feed-artikel');
});

it('filters rss items by locale', function () {
    makeRssArticle($this->workspace, $this->site, ['title' => 'Nederlands artikel', 'slug' => 'nederlands-artikel', 'language' => 'nl']);
    makeRssArticle($this->workspace, $this->site, ['title' => 'English article', 'slug' => 'english-article', 'language' => 'en']);

    $response = $this->get(LocalizedMarketingUrl::route('public.blog.rss', [], 'nl', false));
    $items = collect(rssXml($response->getContent())->channel->item ?? [])
        ->map(fn ($item): string => (string) $item->link)
        ->all();

    expect(implode("\n", $items))->toContain('/nl/blog/nederlands-artikel')
        ->and(implode("\n", $items))->not->toContain('/en/blog/english-article');
});

it('excludes drafts and future posts from rss feeds', function () {
    makeRssArticle($this->workspace, $this->site, ['title' => 'Live article', 'slug' => 'live-article']);
    makeRssArticle($this->workspace, $this->site, [
        'title' => 'Draft article',
        'slug' => 'draft-article',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    makeRssArticle($this->workspace, $this->site, [
        'title' => 'Future article',
        'slug' => 'future-article',
        'published_at' => now()->addDay(),
    ]);

    $response = $this->get(LocalizedMarketingUrl::route('public.blog.rss', [], 'nl', false));

    $response->assertOk()
        ->assertSee('live-article', false)
        ->assertDontSee('draft-article', false)
        ->assertDontSee('future-article', false);
});

it('escapes titles and descriptions without breaking xml', function () {
    makeRssArticle($this->workspace, $this->site, [
        'title' => 'Fish & "Chips"',
        'slug' => 'fish-chips',
        'excerpt' => '<strong>Alpha & Beta</strong> "quotes"',
    ]);

    $response = $this->get(LocalizedMarketingUrl::route('public.blog.rss', [], 'nl', false));
    $xml = rssXml($response->getContent());

    expect((string) $xml->channel->item[0]->title)->toBe('Fish & "Chips"')
        ->and((string) $xml->channel->item[0]->description)->toContain('Alpha & Beta');
});

it('invalidates cached rss output after a published title update', function () {
    $content = makeRssArticle($this->workspace, $this->site, [
        'title' => 'Oude feed titel',
        'slug' => 'oude-feed-titel',
    ]);

    $route = LocalizedMarketingUrl::route('public.blog.rss', [], 'nl', false);

    $this->get($route)
        ->assertOk()
        ->assertSee('Oude feed titel');

    $meta = $content->currentVersion->meta ?? [];
    $meta['title'] = 'Nieuwe feed titel';
    $content->currentVersion->update(['meta' => $meta]);

    $this->get($route)
        ->assertOk()
        ->assertSee('Nieuwe feed titel')
        ->assertDontSee('Oude feed titel');
});
