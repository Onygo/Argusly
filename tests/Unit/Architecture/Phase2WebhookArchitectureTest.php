<?php

/**
 * Phase 2 Webhook Architecture Tests
 *
 * Tests for the webhook event system refactor focused on:
 * - Event registry and versioning
 * - Envelope structure and event ID generation
 * - Payload builder consistency
 * - Signature verification
 * - Deprecation handling
 */

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SeoAudit;
use App\Models\Workspace;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\Payloads\ArticlePayload;
use App\Support\Webhooks\Payloads\DraftPayload;
use App\Support\Webhooks\Payloads\LegacyBriefPayload;
use App\Support\Webhooks\Payloads\MediaPayload;
use App\Support\Webhooks\Payloads\PublicationPayload;
use App\Support\Webhooks\Payloads\SeoAuditPayload;
use App\Support\Webhooks\Payloads\SystemPayload;
use App\Support\Webhooks\WebhookEnvelope;
use App\Support\Webhooks\WebhookEventRegistry;
use App\Support\Webhooks\WebhookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// =========================================================================
// Test Helpers
// =========================================================================

function createTestWorkspaceForWebhooks(): array
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
// WebhookEventRegistry Tests
// =========================================================================

describe('WebhookEventRegistry - Event Catalog', function () {
    it('has a current version in date format', function () {
        expect(WebhookEventRegistry::CURRENT_VERSION)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    });

    it('catalog returns events grouped by category', function () {
        $catalog = WebhookEventRegistry::catalog();

        expect($catalog)->toHaveKeys(['article', 'draft', 'publication', 'media', 'seo', 'system', 'legacy'])
            ->and($catalog['article'])->toHaveKey(WebhookEventRegistry::ARTICLE_CREATED)
            ->and($catalog['draft'])->toHaveKey(WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED)
            ->and($catalog['publication'])->toHaveKey(WebhookEventRegistry::PUBLICATION_SUCCEEDED);
    });

    it('catalog entries include required metadata', function () {
        $catalog = WebhookEventRegistry::catalog();
        $articleCreated = $catalog['article'][WebhookEventRegistry::ARTICLE_CREATED];

        expect($articleCreated)->toHaveKeys(['event', 'description'])
            ->and($articleCreated['event'])->toBe('article.created')
            ->and($articleCreated['description'])->toBeString();
    });

    it('allEvents returns flat list of event strings', function () {
        $events = WebhookEventRegistry::allEvents();

        expect($events)->toBeArray()
            ->and($events)->toContain(WebhookEventRegistry::ARTICLE_CREATED)
            ->and($events)->toContain(WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED)
            ->and($events)->toContain(WebhookEventRegistry::PUBLICATION_SUCCEEDED)
            ->and($events)->toContain(WebhookEventRegistry::LEGACY_BRIEF_CREATED);
    });

    it('activeEvents excludes deprecated events', function () {
        $events = WebhookEventRegistry::activeEvents();

        expect($events)->toContain(WebhookEventRegistry::ARTICLE_CREATED)
            ->and($events)->not->toContain(WebhookEventRegistry::LEGACY_BRIEF_CREATED)
            ->and($events)->not->toContain(WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED);
    });
});

describe('WebhookEventRegistry - Deprecation', function () {
    it('identifies deprecated events correctly', function () {
        expect(WebhookEventRegistry::isDeprecated(WebhookEventRegistry::LEGACY_BRIEF_CREATED))->toBeTrue()
            ->and(WebhookEventRegistry::isDeprecated(WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED))->toBeTrue()
            ->and(WebhookEventRegistry::isDeprecated(WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED))->toBeTrue()
            ->and(WebhookEventRegistry::isDeprecated(WebhookEventRegistry::ARTICLE_CREATED))->toBeFalse();
    });

    it('provides replacement events for deprecated events', function () {
        expect(WebhookEventRegistry::getReplacementEvent(WebhookEventRegistry::LEGACY_BRIEF_CREATED))
            ->toBe(WebhookEventRegistry::ARTICLE_CREATED)
            ->and(WebhookEventRegistry::getReplacementEvent(WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED))
            ->toBe(WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED)
            ->and(WebhookEventRegistry::getReplacementEvent(WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED))
            ->toBe(WebhookEventRegistry::DRAFT_TRANSLATION_SUCCEEDED);
    });

    it('returns null for non-deprecated events', function () {
        expect(WebhookEventRegistry::getReplacementEvent(WebhookEventRegistry::ARTICLE_CREATED))->toBeNull();
    });
});

describe('WebhookEventRegistry - Validation', function () {
    it('validates known events', function () {
        expect(WebhookEventRegistry::isValid(WebhookEventRegistry::ARTICLE_CREATED))->toBeTrue()
            ->and(WebhookEventRegistry::isValid(WebhookEventRegistry::LEGACY_BRIEF_CREATED))->toBeTrue()
            ->and(WebhookEventRegistry::isValid('*'))->toBeTrue();
    });

    it('rejects unknown events', function () {
        expect(WebhookEventRegistry::isValid('unknown.event'))->toBeFalse()
            ->and(WebhookEventRegistry::isValid(''))->toBeFalse()
            ->and(WebhookEventRegistry::isValid('article'))->toBeFalse();
    });
});

// =========================================================================
// WebhookEnvelope Tests
// =========================================================================

describe('WebhookEnvelope - Structure', function () {
    it('includes all required envelope fields', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
            workspaceId: 'ws_456',
        );

        $array = $envelope->toArray();

        expect($array)->toHaveKeys(['event', 'event_version', 'event_id', 'sent_at', 'data', 'workspace_id'])
            ->and($array['event'])->toBe('article.created')
            ->and($array['event_version'])->toBe(WebhookEventRegistry::CURRENT_VERSION)
            ->and($array['data'])->toBe(['article_id' => 'art_123'])
            ->and($array['workspace_id'])->toBe('ws_456');
    });

    it('includes links when provided', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
            links: ['article' => 'https://example.com/api/v1/articles/art_123'],
        );

        $array = $envelope->toArray();

        expect($array)->toHaveKey('links')
            ->and($array['links']['article'])->toBe('https://example.com/api/v1/articles/art_123');
    });

    it('excludes links when empty', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        $array = $envelope->toArray();

        expect($array)->not->toHaveKey('links');
    });

    it('includes deprecation notice for deprecated events', function () {
        $envelope = new WebhookEnvelope(
            event: WebhookEventRegistry::LEGACY_BRIEF_CREATED,
            data: ['brief_id' => 'brief_123'],
        );

        $array = $envelope->toArray();

        expect($array)->toHaveKey('_deprecation')
            ->and($array['_deprecation']['replacement'])->toBe(WebhookEventRegistry::ARTICLE_CREATED)
            ->and($array['_deprecation']['sunset_date'])->toBe('2026-06-01');
    });
});

describe('WebhookEnvelope - Event ID', function () {
    it('generates event ID in correct format', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        expect($envelope->eventId())->toMatch('/^evt_[A-Z0-9]{26}_[a-f0-9]{8}$/');
    });

    it('uses provided event ID when given', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
            eventId: 'evt_custom_12345678',
        );

        expect($envelope->eventId())->toBe('evt_custom_12345678');
    });

    it('generates deterministic fingerprint for same resource within time window', function () {
        // Freeze time to ensure same minute
        $this->freezeTime();

        $envelope1 = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_same_123'],
        );

        $envelope2 = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_same_123'],
        );

        // Extract fingerprints (last 8 chars)
        $fingerprint1 = substr($envelope1->eventId(), -8);
        $fingerprint2 = substr($envelope2->eventId(), -8);

        expect($fingerprint1)->toBe($fingerprint2);
    });

    it('generates different fingerprints for different resources', function () {
        $this->freezeTime();

        $envelope1 = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        $envelope2 = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_456'],
        );

        $fingerprint1 = substr($envelope1->eventId(), -8);
        $fingerprint2 = substr($envelope2->eventId(), -8);

        expect($fingerprint1)->not->toBe($fingerprint2);
    });
});

describe('WebhookEnvelope - Headers', function () {
    it('includes standard headers', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        $headers = $envelope->headers('signature123', 1);

        expect($headers)->toHaveKeys([
            'Content-Type',
            'X-Argusly-Event',
            'X-Argusly-Event-Version',
            'X-Argusly-Event-ID',
            'X-Argusly-Signature',
            'X-Argusly-Delivery-Attempt',
            'X-Argusly-Timestamp',
        ])
            ->and($headers['Content-Type'])->toBe('application/json')
            ->and($headers['X-Argusly-Event'])->toBe('article.created')
            ->and($headers['X-Argusly-Signature'])->toBe('sha256=signature123')
            ->and($headers['X-Argusly-Delivery-Attempt'])->toBe('1');
    });

    it('includes deprecation headers for deprecated events', function () {
        $envelope = new WebhookEnvelope(
            event: WebhookEventRegistry::LEGACY_BRIEF_CREATED,
            data: ['brief_id' => 'brief_123'],
        );

        $headers = $envelope->headers('signature123');

        expect($headers)->toHaveKeys(['X-Argusly-Deprecation', 'Sunset'])
            ->and($headers['X-Argusly-Deprecation'])->toBe('true')
            ->and($headers['Sunset'])->toBe('Sun, 01 Jun 2026 00:00:00 GMT');
    });

    it('excludes deprecation headers for active events', function () {
        $envelope = new WebhookEnvelope(
            event: WebhookEventRegistry::ARTICLE_CREATED,
            data: ['article_id' => 'art_123'],
        );

        $headers = $envelope->headers('signature123');

        expect($headers)->not->toHaveKey('X-Argusly-Deprecation')
            ->and($headers)->not->toHaveKey('Sunset');
    });
});

describe('WebhookEnvelope - Signature', function () {
    it('computes HMAC-SHA256 signature', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        $signature = $envelope->sign('secret-key');

        // Verify it's a valid hex string (64 chars for SHA256)
        expect($signature)->toMatch('/^[a-f0-9]{64}$/')
            ->and($signature)->toBe(hash_hmac('sha256', $envelope->toJson(), 'secret-key'));
    });

    it('produces consistent signatures for same payload and secret', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
            eventId: 'evt_fixed_id',
        );

        $sig1 = $envelope->sign('secret');
        $sig2 = $envelope->sign('secret');

        expect($sig1)->toBe($sig2);
    });

    it('produces different signatures for different secrets', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
            eventId: 'evt_fixed_id',
        );

        $sig1 = $envelope->sign('secret1');
        $sig2 = $envelope->sign('secret2');

        expect($sig1)->not->toBe($sig2);
    });
});

describe('WebhookEnvelope - Legacy Support', function () {
    it('toLegacyArray uses id instead of event_id', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        $legacy = $envelope->toLegacyArray();

        expect($legacy)->toHaveKeys(['event', 'id', 'sent_at', 'data'])
            ->and($legacy)->not->toHaveKey('event_id')
            ->and($legacy)->not->toHaveKey('event_version')
            ->and($legacy['id'])->toBe($envelope->eventId());
    });

    it('legacyHeaders excludes version header', function () {
        $envelope = new WebhookEnvelope(
            event: 'article.created',
            data: ['article_id' => 'art_123'],
        );

        $headers = $envelope->legacyHeaders('signature123');

        expect($headers)->toHaveKey('X-Argusly-Event')
            ->and($headers)->not->toHaveKey('X-Argusly-Event-Version')
            ->and($headers)->not->toHaveKey('X-Argusly-Event-ID');
    });
});

// =========================================================================
// Payload Builder Tests
// =========================================================================

describe('ArticlePayload - Structure', function () {
    it('created payload has correct event type', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'status' => 'draft',
            'primary_keyword' => 'test keyword',
        ]);

        $payload = ArticlePayload::created($content);

        expect($payload)->toBeInstanceOf(WebhookPayload::class)
            ->and($payload->eventType())->toBe(WebhookEventRegistry::ARTICLE_CREATED)
            ->and($payload->version())->toBe(WebhookEventRegistry::CURRENT_VERSION);
    });

    it('includes required article fields', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'status' => 'draft',
            'type' => 'article',
            'primary_keyword' => 'test keyword',
            'seo_title' => 'Test SEO Title',
        ]);

        $payload = ArticlePayload::created($content);
        $data = $payload->toArray();

        expect($data)->toHaveKeys([
            'article_id',
            'title',
            'status',
            'type',
            'primary_keyword',
            'seo_title',
            'workspace_id',
            'client_site_id',
            'created_at',
            'updated_at',
        ])
            ->and($data['article_id'])->toBe($content->id)
            ->and($data['title'])->toBe('Test Article')
            ->and($data['status'])->toBe('draft');
    });

    it('provides links to API resources', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
        ]);

        $payload = ArticlePayload::created($content);
        $links = $payload->links();

        expect($links)->toHaveKey('article')
            ->and($links['article'])->toContain('/api/v1/articles/');
    });

    it('includes rejection reason for rejected events', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
        ]);

        $payload = ArticlePayload::rejected($content, 'Content does not meet quality standards');
        $data = $payload->toArray();

        expect($data)->toHaveKey('reason')
            ->and($data['reason'])->toBe('Content does not meet quality standards');
    });

    it('includes actor_user_id when provided', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
        ]);

        $payload = ArticlePayload::approved($content, 'user_123');
        $data = $payload->toArray();

        expect($data)->toHaveKey('actor_user_id')
            ->and($data['actor_user_id'])->toBe('user_123');
    });

    it('has factory methods for all article events', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
        ]);

        expect(ArticlePayload::created($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_CREATED)
            ->and(ArticlePayload::updated($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_UPDATED)
            ->and(ArticlePayload::submitted($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_SUBMITTED)
            ->and(ArticlePayload::approved($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_APPROVED)
            ->and(ArticlePayload::rejected($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_REJECTED)
            ->and(ArticlePayload::scheduled($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_SCHEDULED)
            ->and(ArticlePayload::archived($content)->eventType())->toBe(WebhookEventRegistry::ARTICLE_ARCHIVED);
    });
});

describe('DraftPayload - Structure', function () {
    it('generation succeeded payload has correct structure', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $brief = Brief::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'topic' => 'Test Topic',
            'title' => 'Brief Title',
            'primary_keyword' => 'test keyword',
            'status' => 'approved',
        ]);

        $draft = Draft::create([
            'brief_id' => $brief->id,
            'client_site_id' => $site->id,
            'title' => 'Generated Draft',
            'status' => 'generated',
        ]);

        $payload = DraftPayload::generationSucceeded($draft, 'op_123');
        $data = $payload->toArray();

        expect($payload->eventType())->toBe(WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED)
            ->and($data)->toHaveKeys(['draft_id', 'title', 'status', 'operation_id'])
            ->and($data['draft_id'])->toBe($draft->id)
            ->and($data['operation_id'])->toBe('op_123');
    });

    it('generation failed payload includes error', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

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
            'title' => 'Failed Draft',
            'status' => 'failed',
        ]);

        $payload = DraftPayload::generationFailed($draft, 'API timeout occurred', 'op_456');
        $data = $payload->toArray();

        expect($payload->eventType())->toBe(WebhookEventRegistry::DRAFT_GENERATION_FAILED)
            ->and($data)->toHaveKey('error')
            ->and($data['error'])->toBe('API timeout occurred');
    });
});

describe('PublicationPayload - Structure', function () {
    it('succeeded payload includes remote information', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Published Article',
        ]);

        $publication = ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => 'wordpress',
            'remote_id' => '12345',
            'remote_url' => 'https://example.com/published-article',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        $payload = PublicationPayload::succeeded($content, $publication);
        $data = $payload->toArray();

        expect($payload->eventType())->toBe(WebhookEventRegistry::PUBLICATION_SUCCEEDED)
            ->and($data)->toHaveKeys(['article_id', 'publication_id', 'provider', 'remote_id', 'remote_url'])
            ->and($data['remote_id'])->toBe('12345')
            ->and($data['remote_url'])->toBe('https://example.com/published-article');
    });

    it('failed payload includes error information', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Failed Publication',
        ]);

        $payload = PublicationPayload::failed($content, 'Connection refused', null, 'wordpress');
        $data = $payload->toArray();

        expect($payload->eventType())->toBe(WebhookEventRegistry::PUBLICATION_FAILED)
            ->and($data)->toHaveKey('error')
            ->and($data['error'])->toBe('Connection refused');
    });
});

describe('SystemPayload - Structure', function () {
    it('credits low payload includes threshold information', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $payload = SystemPayload::creditsLow($workspace, 50, 100, 10);
        $data = $payload->toArray();

        expect($payload->eventType())->toBe(WebhookEventRegistry::CREDITS_LOW)
            ->and($data)->toHaveKeys(['workspace_id', 'current_credits', 'threshold_credits', 'percentage_remaining'])
            ->and($data['current_credits'])->toBe(50)
            ->and($data['threshold_credits'])->toBe(100)
            ->and($data['percentage_remaining'])->toBe(10);
    });
});

describe('LegacyBriefPayload - Backwards Compatibility', function () {
    it('produces legacy brief.created event type', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $brief = Brief::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'topic' => 'Legacy Topic',
            'title' => 'Legacy Brief',
            'primary_keyword' => 'legacy keyword',
            'status' => 'approved',
        ]);

        $payload = LegacyBriefPayload::created($brief);

        expect($payload->eventType())->toBe(WebhookEventRegistry::LEGACY_BRIEF_CREATED)
            ->and(WebhookEventRegistry::isDeprecated($payload->eventType()))->toBeTrue();
    });

    it('includes brief-specific fields', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $brief = Brief::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Legacy Brief',
            'primary_keyword' => 'legacy keyword',
            'status' => 'approved',
        ]);

        $data = LegacyBriefPayload::created($brief)->toArray();

        expect($data)->toHaveKeys(['brief_id', 'title', 'primary_keyword', 'status'])
            ->and($data['brief_id'])->toBe($brief->id)
            ->and($data['title'])->toBe('Legacy Brief')
            ->and($data['primary_keyword'])->toBe('legacy keyword');
    });
});

// =========================================================================
// Envelope + Payload Integration Tests
// =========================================================================

describe('WebhookEnvelope - fromPayload Integration', function () {
    it('creates envelope from payload with all fields', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => 'test',
        ]);

        $payload = ArticlePayload::created($content);
        $envelope = WebhookEnvelope::fromPayload($payload, $workspace->id);

        $array = $envelope->toArray();

        expect($array['event'])->toBe(WebhookEventRegistry::ARTICLE_CREATED)
            ->and($array['event_version'])->toBe(WebhookEventRegistry::CURRENT_VERSION)
            ->and($array['workspace_id'])->toBe($workspace->id)
            ->and($array['data']['article_id'])->toBe($content->id)
            ->and($array['links']['article'])->toContain('/api/v1/articles/');
    });

    it('json encodes without escaping unicode or slashes', function () {
        [$org, $workspace, $site] = createTestWorkspaceForWebhooks();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Artículo con ñ y acentos',
            'primary_keyword' => 'test',
        ]);

        $payload = ArticlePayload::created($content);
        $envelope = WebhookEnvelope::fromPayload($payload, $workspace->id);

        $json = $envelope->toJson();

        expect($json)->toContain('Artículo con ñ y acentos')
            ->and($json)->toContain('/api/v1/')
            ->and($json)->not->toContain('\u')
            ->and($json)->not->toContain('\/');
    });
});
