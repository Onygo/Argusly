<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

if (! function_exists('makeContentIntelligenceContext')) {
    function makeContentIntelligenceContext(string $prefix): array
    {
        $organization = Organization::query()->create([
            'name' => Str::headline($prefix) . ' Org',
            'slug' => $prefix . '-org-' . Str::lower(Str::random(6)),
            'status' => 'active',
        ]);

        $workspace = Workspace::query()->create([
            'name' => Str::headline($prefix) . ' Workspace',
            'organization_id' => $organization->id,
        ]);

        $site = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => Str::headline($prefix) . ' Site',
            'site_url' => 'https://' . $prefix . '.example.com',
            'base_url' => 'https://' . $prefix . '.example.com',
            'allowed_domains' => [$prefix . '.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        return [$workspace, $site];
    }
}

if (! function_exists('makeContentVariant')) {
    function makeContentVariant(Workspace $workspace, ClientSite $site, string $title, string $locale, array $overrides = []): Content
    {
        $content = Content::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'external_key' => (string) Str::uuid(),
            'title' => $title,
            'language' => $locale,
            'type' => 'article',
            'status' => 'draft',
            'publish_status' => 'draft',
            'source' => 'manual',
            'is_source_locale' => ($overrides['translation_source_content_id'] ?? null) ? false : true,
        ], $overrides));

        if ((string) ($content->status ?? '') === 'published' && (string) ($content->publish_status ?? '') === 'published') {
            $remoteUrl = trim((string) ($content->published_url ?? ''));
            if ($remoteUrl === '') {
                $remoteUrl = rtrim((string) $site->site_url, '/') . '/blog/' . Str::slug($title);
                $content->forceFill(['published_url' => $remoteUrl])->save();
            }

            ContentPublication::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'locale' => $locale,
                'provider' => $site->isLaravel() ? ContentPublication::PROVIDER_LARAVEL : ContentPublication::PROVIDER_WORDPRESS,
                'remote_id' => (string) Str::random(8),
                'remote_type' => 'post',
                'remote_url' => $remoteUrl,
                'remote_status' => ContentPublication::REMOTE_PUBLISHED,
                'delivery_status' => ContentPublication::STATUS_DELIVERED,
                'last_verified_at' => now(),
                'last_delivered_at' => now(),
                'meta' => [],
            ]);
        }

        return $content;
    }
}

if (! function_exists('makeCurrentVersion')) {
    function makeCurrentVersion(Content $content, string $body, CarbonInterface $updatedAt): Content
    {
        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_REVISION,
            'body' => $body,
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        ContentVersion::query()->whereKey($version->id)->update([
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        Content::query()->whereKey($content->id)->update([
            'current_version_id' => $version->id,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $content->fresh(['currentVersion']);
    }
}

if (! function_exists('makeSeriesArticle')) {
    function makeSeriesArticle(ContentSeries $series, Content $content, int $articleNumber, bool $isPillar): ContentSeriesArticle
    {
        return ContentSeriesArticle::query()->create([
            'id' => (string) Str::uuid(),
            'series_id' => $series->id,
            'content_id' => $content->id,
            'article_number' => $articleNumber,
            'title' => $content->title,
            'primary_keyword' => Str::lower($content->title),
            'secondary_keywords' => [],
            'internal_links_to' => [],
            'planned_url' => null,
            'is_pillar' => $isPillar,
            'meta' => [],
        ]);
    }
}
