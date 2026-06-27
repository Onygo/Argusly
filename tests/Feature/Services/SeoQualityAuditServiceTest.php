<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Seo\SeoQualityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('includes duplicate and similar title risks in the seo quality audit', function () {
    $organization = Organization::query()->create([
        'name' => 'SEO Audit Org',
        'slug' => 'seo-audit-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'SEO Audit Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'SEO Audit Site',
        'site_url' => 'https://seo-audit.example.test',
        'base_url' => 'https://seo-audit.example.test',
        'allowed_domains' => ['seo-audit.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical GEO Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    $result = app(SeoQualityAuditService::class)->auditContent($content);

    expect($result['duplicate_title_matches'])->not->toBeEmpty()
        ->and(collect($result['issues'])->implode("\n"))->toContain('Very similar title detected');
});

it('keeps seo quality audit queries scoped to a workspace when provided', function () {
    $organization = Organization::query()->create([
        'name' => 'Scoped SEO Audit Org',
        'slug' => 'scoped-seo-audit-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Scoped SEO Audit Workspace',
        'organization_id' => $organization->id,
    ]);

    $otherWorkspace = Workspace::query()->create([
        'name' => 'Other Scoped SEO Audit Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Scoped SEO Audit Site',
        'site_url' => 'https://scoped-seo-audit.example.test',
        'base_url' => 'https://scoped-seo-audit.example.test',
        'allowed_domains' => ['scoped-seo-audit.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $otherSite = ClientSite::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'type' => 'wordpress',
        'name' => 'Other Scoped SEO Audit Site',
        'site_url' => 'https://other-scoped-seo-audit.example.test',
        'base_url' => 'https://other-scoped-seo-audit.example.test',
        'allowed_domains' => ['other-scoped-seo-audit.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Scoped AI Visibility Article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $otherWorkspace->id,
        'client_site_id' => (string) $otherSite->id,
        'title' => 'Other Workspace Article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    $result = app(SeoQualityAuditService::class)->auditWorkspace($workspace, publishedOnly: true, limit: 50);

    expect($result['summary']['audited'])->toBe(1)
        ->and(collect($result['items'])->pluck('title')->all())->toContain('Scoped AI Visibility Article')
        ->and(collect($result['items'])->pluck('title')->all())->not->toContain('Other Workspace Article');
});

it('includes AI fingerprint findings in the seo quality audit', function () {
    $organization = Organization::query()->create([
        'name' => 'SEO AI Fingerprint Org',
        'slug' => 'seo-ai-fingerprint-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'SEO AI Fingerprint Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'SEO AI Fingerprint Site',
        'site_url' => 'https://seo-ai-fingerprint.example.test',
        'base_url' => 'https://seo-ai-fingerprint.example.test',
        'allowed_domains' => ['seo-ai-fingerprint.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Generic AI article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    $version = ContentVersion::query()->create([
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'source' => ContentVersion::SOURCE_ARGUSLY,
        'body' => '<h1>Introduction</h1><p>In today\'s digital landscape, it is important to note that teams can unlock the power of a robust solution.</p><h2>Main Section</h2><p>Moreover, this game changer helps businesses stay ahead of the curve.</p><h2>Conclusion</h2><p>In conclusion, get started today.</p>',
        'meta' => [],
    ]);
    $content->update(['current_version_id' => (string) $version->id]);

    $result = app(SeoQualityAuditService::class)->auditContent($content->fresh('currentVersion'));

    expect($result['issue_types'])->toContain('ai_readiness')
        ->and($result['issues'])->toContain('Headings are too generic.')
        ->and(data_get($result, 'ai_fingerprint.score'))->toBeGreaterThanOrEqual(45)
        ->and(collect(data_get($result, 'ai_fingerprint.findings'))->pluck('type')->all())->toContain('generic_headings');
});
