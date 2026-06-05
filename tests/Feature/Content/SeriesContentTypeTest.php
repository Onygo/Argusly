<?php

use App\Enums\WordPressPostType;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createTestOrganization(): Organization
{
    return Organization::query()->create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Test Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);
}

function createTestWorkspace(Organization $organization): Workspace
{
    return Workspace::query()->create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);
}

function createTestSite(Workspace $workspace): ClientSite
{
    return ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);
}

describe('ContentSeries content_type field', function () {
    describe('content_type defaults', function () {
        it('defaults to post when content_type is not specified', function () {
            $organization = createTestOrganization();
            $workspace = createTestWorkspace($organization);
            $site = createTestSite($workspace);

            $series = ContentSeries::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Test Series',
                'main_topic' => 'Testing',
                'primary_keyword' => 'test',
                'status' => 'draft',
            ]);

            expect($series->wordPressPostType())->toBe(WordPressPostType::POST);
        });

        it('stores post content_type correctly', function () {
            $organization = createTestOrganization();
            $workspace = createTestWorkspace($organization);
            $site = createTestSite($workspace);

            $series = ContentSeries::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Test Blog Series',
                'main_topic' => 'Testing',
                'primary_keyword' => 'test',
                'content_type' => 'post',
                'status' => 'draft',
            ]);

            expect($series->wordPressPostType())->toBe(WordPressPostType::POST);
        });

        it('stores knowledge_base content_type correctly', function () {
            $organization = createTestOrganization();
            $workspace = createTestWorkspace($organization);
            $site = createTestSite($workspace);

            $series = ContentSeries::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Test KB Series',
                'main_topic' => 'Testing',
                'primary_keyword' => 'test',
                'content_type' => 'knowledge_base',
                'status' => 'draft',
            ]);

            expect($series->wordPressPostType())->toBe(WordPressPostType::KNOWLEDGE_BASE);
        });
    });

    describe('wordPressPostType helper', function () {
        it('returns correct type from enum cast', function () {
            $organization = createTestOrganization();
            $workspace = createTestWorkspace($organization);
            $site = createTestSite($workspace);

            $series = ContentSeries::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Test KB Series',
                'main_topic' => 'Testing',
                'primary_keyword' => 'test',
                'content_type' => 'knowledge_base',
                'status' => 'draft',
            ]);

            expect($series->content_type)->toBe(WordPressPostType::KNOWLEDGE_BASE);
            expect($series->wordPressPostType())->toBe(WordPressPostType::KNOWLEDGE_BASE);
        });
    });
});

describe('Content wordPressPostType resolution', function () {
    it('inherits post type from series', function () {
        $organization = createTestOrganization();
        $workspace = createTestWorkspace($organization);
        $site = createTestSite($workspace);

        $series = ContentSeries::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'KB Series',
            'main_topic' => 'Testing',
            'primary_keyword' => 'test',
            'content_type' => 'knowledge_base',
            'status' => 'draft',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'series_id' => $series->id,
            'title' => 'Test Article',
            'type' => 'article', // Content type is article but series is knowledge_base
            'status' => 'draft',
        ]);

        // Series content_type should take precedence
        expect($content->wordPressPostType())->toBe(WordPressPostType::KNOWLEDGE_BASE);
    });

    it('falls back to content type when not in series', function () {
        $organization = createTestOrganization();
        $workspace = createTestWorkspace($organization);
        $site = createTestSite($workspace);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'series_id' => null,
            'title' => 'Test KB Article',
            'type' => 'knowledge_base',
            'status' => 'draft',
        ]);

        expect($content->wordPressPostType())->toBe(WordPressPostType::KNOWLEDGE_BASE);
    });

    it('defaults to POST when content has no series and is article type', function () {
        $organization = createTestOrganization();
        $workspace = createTestWorkspace($organization);
        $site = createTestSite($workspace);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'series_id' => null,
            'title' => 'Test Article',
            'type' => 'article',
            'status' => 'draft',
        ]);

        expect($content->wordPressPostType())->toBe(WordPressPostType::POST);
    });
});

describe('Series URL generation with content type', function () {
    it('generates blog URL for post type series', function () {
        $organization = createTestOrganization();
        $workspace = createTestWorkspace($organization);
        $site = createTestSite($workspace);

        $series = ContentSeries::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'Blog Series',
            'main_topic' => 'Testing',
            'primary_keyword' => 'test',
            'content_type' => 'post',
            'status' => 'draft',
        ]);

        $postType = $series->wordPressPostType();
        $url = $postType->buildPlannedUrl('https://example.com', 'my-article');

        expect($url)->toBe('https://example.com/blog/my-article');
    });

    it('generates knowledge-base URL for knowledge_base type series', function () {
        $organization = createTestOrganization();
        $workspace = createTestWorkspace($organization);
        $site = createTestSite($workspace);

        $series = ContentSeries::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'site_id' => $site->id,
            'name' => 'KB Series',
            'main_topic' => 'Testing',
            'primary_keyword' => 'test',
            'content_type' => 'knowledge_base',
            'status' => 'draft',
        ]);

        $postType = $series->wordPressPostType();
        $url = $postType->buildPlannedUrl('https://example.com', 'my-guide');

        expect($url)->toBe('https://example.com/knowledge-base/my-guide');
    });
});
