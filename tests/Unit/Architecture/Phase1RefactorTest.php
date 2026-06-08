<?php

/**
 * Phase 1 Architecture Refactor Tests
 *
 * Tests for the incremental architecture refactor focused on:
 * - SEO consolidation (Content as single source of truth)
 * - Remote ID consolidation (ContentPublication as canonical)
 * - Delivery status clarification (ContentPublication as authoritative)
 * - Revision/Version semantic clarity
 */

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\SeoMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// =========================================================================
// Test Helpers
// =========================================================================

function createTestWorkspaceWithSite(): array
{
    $organization = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://test-' . Str::random(6) . '.example.com',
        'allowed_domains' => ['test.example.com'],
        'is_active' => true,
    ]);

    return [$organization, $workspace, $site];
}

// =========================================================================
// SEO Consolidation Tests
// =========================================================================

describe('SEO Source of Truth - Content is Canonical', function () {
    it('resolves SEO metadata from Content typed columns as primary source', function () {
        $resolved = SeoMetadata::resolveForContentContext([
            'seo_title' => 'Content SEO Title',
            'seo_meta_description' => 'Content Meta Description',
            'primary_keyword' => 'content keyword',
            'robots_index' => true,
            'robots_follow' => true,
            'schema_type' => 'Article',
        ]);

        expect($resolved['seo_title'])->toBe('Content SEO Title')
            ->and($resolved['seo_meta_description'])->toBe('Content Meta Description')
            ->and($resolved['primary_keyword'])->toBe('content keyword')
            ->and($resolved['robots_index'])->toBeTrue()
            ->and($resolved['robots_follow'])->toBeTrue()
            ->and($resolved['schema_type'])->toBe('Article');
    });

    it('provides canonical field list for SEO', function () {
        $fields = SeoMetadata::canonicalFields();

        expect($fields)->toContain('seo_title')
            ->and($fields)->toContain('seo_meta_description')
            ->and($fields)->toContain('primary_keyword')
            ->and($fields)->toContain('robots_index')
            ->and($fields)->toContain('robots_follow')
            ->and($fields)->toContain('schema_type')
            ->and($fields)->toHaveCount(13);
    });

    it('provides syncable field list excluding primary_keyword', function () {
        $fields = SeoMetadata::syncableFields();

        expect($fields)->toContain('seo_title')
            ->and($fields)->not->toContain('primary_keyword')
            ->and($fields)->toHaveCount(12);
    });

    it('provides legacy field mapping for ContentSeo migration', function () {
        $mapping = SeoMetadata::legacyFieldMapping();

        expect($mapping['meta_title'])->toBe('seo_title')
            ->and($mapping['meta_description'])->toBe('seo_meta_description')
            ->and($mapping['primary_keyword'])->toBe('primary_keyword');
    });

    it('content model has hasCompleteSeo helper', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'seo_title' => 'Complete SEO Title',
            'seo_meta_description' => 'Complete meta description',
            'primary_keyword' => 'keyword',
        ]);

        expect($content->hasCompleteSeo())->toBeTrue();

        $incompleteContent = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Incomplete Content',
            'seo_title' => 'Title Only',
            'seo_meta_description' => null,
            'primary_keyword' => null,
        ]);

        expect($incompleteContent->hasCompleteSeo())->toBeFalse();
    });

    it('content model can sync SEO from draft', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Content for SEO Sync',
            'seo_title' => null,
            'seo_meta_description' => null,
        ]);

        $brief = Brief::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'content_id' => $content->id,
            'primary_keyword' => 'brief keyword',
            'topic' => 'Test Topic',
            'title' => 'Brief Title',
            'status' => 'approved',
        ]);

        $draft = Draft::create([
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'title' => 'Draft Title',
            'seo_title' => 'Draft SEO Title',
            'seo_meta_description' => 'Draft meta description',
            'seo_h1' => 'Draft H1',
            'status' => 'generated',
        ]);

        // Load the brief relationship
        $draft->load('brief');

        $updated = $content->syncSeoFromDraft($draft);

        expect($updated)->toBeTrue()
            ->and($content->fresh()->seo_title)->toBe('Draft SEO Title')
            ->and($content->fresh()->seo_meta_description)->toBe('Draft meta description')
            ->and($content->fresh()->seo_h1)->toBe('Draft H1')
            ->and($content->fresh()->primary_keyword)->toBe('brief keyword');
    });
});

// =========================================================================
// Remote ID Consolidation Tests
// =========================================================================

describe('Remote ID - ContentPublication is Canonical', function () {
    it('content resolves canonical remote ID from ContentPublication', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'wp_post_id' => '999', // Legacy field
        ]);

        // Create publication with canonical remote_id
        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '12345',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        // Should prefer ContentPublication.remote_id over Content.wp_post_id
        $remoteId = $content->getCanonicalRemoteId(null, $site->id);

        expect($remoteId)->toBe('12345');
    });

    it('content falls back to legacy wp_post_id when no publication exists', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'wp_post_id' => '999',
        ]);

        $remoteId = $content->getCanonicalRemoteId();

        expect($remoteId)->toBe('999');
    });

    it('content hasRemotePublication checks both canonical and legacy sources', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'wp_post_id' => null,
        ]);

        expect($content->hasRemotePublication())->toBeFalse();

        $content->update(['wp_post_id' => '123']);

        expect($content->hasRemotePublication())->toBeTrue();
    });

    it('content publication provides getWpPostId for backwards compatibility', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $publication = ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '54321',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        expect($publication->getWpPostId())->toBe('54321');
    });

    it('content publication getRemoteId works for any provider', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $publication = ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_LARAVEL,
            'remote_id' => 'laravel-uuid-123',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        expect($publication->getRemoteId())->toBe('laravel-uuid-123')
            ->and($publication->getWpPostId())->toBeNull(); // Not WordPress
    });

    it('content publish target resolves canonical remote ID via publication', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $target = ContentPublishTarget::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'wp_post_id' => '111', // Legacy
        ]);

        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '222', // Canonical
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        $remoteId = $target->getCanonicalRemoteId();

        expect($remoteId)->toBe('222'); // Should prefer publication
    });
});

// =========================================================================
// Delivery Status Clarification Tests
// =========================================================================

describe('Delivery Status - ContentPublication is Authoritative', function () {
    it('content resolves delivery status from ContentPublication', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'delivery_status' => 'pending', // Legacy shadow
        ]);

        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        $status = $content->resolveDeliveryStatus(null, $site->id);

        expect($status)->toBe(ContentPublication::STATUS_DELIVERED);
    });

    it('content isDelivered helper checks publication status', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        expect($content->isDelivered(null, $site->id))->toBeTrue();
    });

    it('content hasDeliveryFailure checks for failed publications', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        expect($content->hasDeliveryFailure())->toBeFalse();

        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_FAILED,
            'last_error_message' => 'Connection timeout',
        ]);

        expect($content->hasDeliveryFailure())->toBeTrue();
    });

    it('publication isSuccessfullyDelivered includes partial success', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $publication = ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => 'partial_success',
        ]);

        expect($publication->isSuccessfullyDelivered())->toBeTrue();
    });
});

// =========================================================================
// Revision/Version Semantic Clarity Tests
// =========================================================================

describe('ContentRevision - Legacy Numbered Snapshots', function () {
    it('revision has type helpers documented', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $revision = ContentRevision::create([
            'content_id' => $content->id,
            'revision_number' => 1,
            'label' => 'R1',
            'content_html' => '<p>Test content</p>',
            'is_active' => true,
        ]);

        expect($revision->isActive())->toBeTrue()
            ->and($revision->getLabel())->toBe('R1');
    });
});

describe('ContentVersion - Hierarchical Tree (Preferred)', function () {
    it('has type constants for version classification', function () {
        expect(ContentVersion::TYPE_BRIEF)->toBe('brief')
            ->and(ContentVersion::TYPE_DRAFT)->toBe('draft')
            ->and(ContentVersion::TYPE_REVISION)->toBe('revision')
            ->and(ContentVersion::TYPE_PUBLISHED_SNAPSHOT)->toBe('published_snapshot');
    });

    it('has source constants for origin tracking', function () {
        expect(ContentVersion::SOURCE_ARGUSLY)->toBe('pl')
            ->and(ContentVersion::SOURCE_WORDPRESS)->toBe('wp')
            ->and(ContentVersion::SOURCE_API)->toBe('api');
    });

    it('provides type helper methods', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $briefVersion = ContentVersion::create([
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_BRIEF,
            'body' => '{"topic": "test"}',
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        $draftVersion = ContentVersion::create([
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_DRAFT,
            'parent_version_id' => $briefVersion->id,
            'body' => '<p>Draft content</p>',
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        expect($briefVersion->isBrief())->toBeTrue()
            ->and($briefVersion->hasContent())->toBeFalse()
            ->and($draftVersion->isDraft())->toBeTrue()
            ->and($draftVersion->hasContent())->toBeTrue();
    });

    it('provides source helper methods', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $version = ContentVersion::create([
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_DRAFT,
            'body' => '<p>Content</p>',
            'source' => ContentVersion::SOURCE_WORDPRESS,
        ]);

        expect($version->isFromWordPress())->toBeTrue()
            ->and($version->isFromArgusly())->toBeFalse()
            ->and($version->isFromApi())->toBeFalse();
    });

    it('can traverse version lineage', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $briefVersion = ContentVersion::create([
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_BRIEF,
            'body' => '{"topic": "test"}',
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        $draftVersion = ContentVersion::create([
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_DRAFT,
            'parent_version_id' => $briefVersion->id,
            'body' => '<p>Draft content</p>',
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        $revisionVersion = ContentVersion::create([
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_REVISION,
            'parent_version_id' => $draftVersion->id,
            'body' => '<p>Revised content</p>',
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        $root = $revisionVersion->getRootVersion();
        $depth = $revisionVersion->getDepth();

        expect($root->id)->toBe($briefVersion->id)
            ->and($depth)->toBe(2);
    });
});

// =========================================================================
// Backward Compatibility Tests
// =========================================================================

describe('Backward Compatibility', function () {
    it('content still exposes wp_post_id in fillable for legacy code', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'wp_post_id' => '12345',
        ]);

        expect($content->wp_post_id)->toBe('12345');
    });

    it('content getLegacyWpPostId helper marks deprecation', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'wp_post_id' => '54321',
        ]);

        expect($content->getLegacyWpPostId())->toBe('54321');
    });

    it('content_seo table still accessible for legacy reads', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $contentSeo = ContentSeo::create([
            'content_id' => $content->id,
            'meta_title' => 'Legacy Title',
            'meta_description' => 'Legacy Description',
            'primary_keyword' => 'legacy keyword',
        ]);

        expect($content->seo->meta_title)->toBe('Legacy Title');
    });

    it('draft delivery_status still exists for per-attempt tracking', function () {
        [$org, $workspace, $site] = createTestWorkspaceWithSite();

        $brief = Brief::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'topic' => 'Test Topic',
            'title' => 'Brief Title',
            'status' => 'approved',
        ]);

        $draft = Draft::create([
            'brief_id' => $brief->id,
            'client_site_id' => $site->id,
            'title' => 'Test Draft',
            'status' => 'generated',
            'delivery_status' => 'processing',
            'delivery_attempts' => 1,
        ]);

        expect($draft->delivery_status)->toBe('processing')
            ->and($draft->delivery_attempts)->toBe(1);
    });
});
