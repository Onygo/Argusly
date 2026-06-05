<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsContentResolver;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves content id by publish_url_key for a site', function () {
    $organization = Organization::query()->create([
        'name' => 'Resolver Org',
        'slug' => 'resolver-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Resolver Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Resolver Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Mapped Content',
        'published_url' => 'https://example.com/Blog/My-Post/?utm_source=test#top',
    ]);

    $resolver = app(AnalyticsContentResolver::class);
    $resolvedId = $resolver->resolve(
        (string) $site->id,
        AnalyticsUrlKey::fromUrl('https://EXAMPLE.com/Blog/My-Post')
    );

    expect($resolvedId)->toBe((string) $content->id);
});
