<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists oversized generated brief audience data without truncation failures', function () {
    [$workspace, $site] = makeBriefAiPayloadContext();

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => str_repeat('Generated content title ', 30),
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'chained_content',
        'external_key' => str_repeat('external-', 40),
    ]);

    $longAudience = trim('Technical buyers and revenue leaders ' . str_repeat('needing detailed implementation context ', 25));

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'queued',
        'source' => 'content_automation.run.with.extra.context',
        'progress' => 0,
        'title' => str_repeat('Generated brief title ', 30),
        'language' => 'en_US',
        'content_type' => 'blog_post_with_generated_suffix',
        'output_type' => 'kb_article',
        'intent' => str_repeat('commercial investigation ', 20),
        'primary_keyword' => str_repeat('automation ', 80),
        'audience' => $longAudience,
        'target_audience' => $longAudience,
        'search_intent' => str_repeat('informational ', 10),
        'notes' => str_repeat('Long generated notes. ', 200),
    ]);

    $fresh = $brief->fresh();

    expect($fresh)->not->toBeNull()
        ->and(mb_strlen((string) $fresh->title))->toBeLessThanOrEqual(255)
        ->and(mb_strlen((string) $fresh->source))->toBeLessThanOrEqual(100)
        ->and(mb_strlen((string) $fresh->audience))->toBe(255)
        ->and($fresh->audience_details)->toBe($longAudience)
        ->and($fresh->target_audience)->toBe($longAudience)
        ->and(mb_strlen((string) $fresh->search_intent))->toBeLessThanOrEqual(32)
        ->and(mb_strlen((string) $fresh->content_type))->toBeLessThanOrEqual(32);
});

it('keeps manual brief audience labels backward compatible', function () {
    [, $site] = makeBriefAiPayloadContext();

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Manual brief',
        'language' => 'en',
        'audience' => 'operations,developer',
        'target_audience' => 'Operations and developer teams',
    ]);

    expect($brief->fresh()->audience)->toBe('operations,developer')
        ->and($brief->fresh()->audience_details)->toBeNull();
});

function makeBriefAiPayloadContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Brief AI Payload Org',
        'slug' => 'brief-ai-payload-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Brief AI Payload Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Brief AI Payload Site',
        'site_url' => 'https://brief-ai-payload.example.com',
        'base_url' => 'https://brief-ai-payload.example.com',
        'allowed_domains' => ['brief-ai-payload.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}
