<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\DraftGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('injects active site competitors into generation context when enabled on site', function () {
    $organization = Organization::query()->create([
        'name' => 'Competitor Context Org',
        'slug' => 'competitor-context-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Competitor Context Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Competitor Context Site',
        'site_url' => 'https://context-competitor.example.com',
        'allowed_domains' => ['context-competitor.example.com'],
        'is_active' => true,
        'capabilities' => ['competitor_context_enabled' => true],
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Acme SEO',
        'domain' => 'acmeseo.com',
        'notes' => 'Strong on long-tail cluster pages',
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Competitor-aware content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Competitor brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Competitor draft',
        'output_type' => 'kb_article',
    ]);

    $context = app(DraftGenerationService::class)->buildSystemContextForDraft($draft);

    expect($context)->toContain('Competitive context (site-level):');
    expect($context)->toContain('Acme SEO (acmeseo.com)');
    expect($context)->toContain('Strong on long-tail cluster pages');
});
