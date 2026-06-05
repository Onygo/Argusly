<?php

use App\Contracts\LinkIntelligence\LinkApplyService;
use App\Contracts\LinkIntelligence\LinkSuggestionService;
use App\DTO\LinkIntelligence\ApplyOptions;
use App\Events\LinkIntelligence\ArticleSignalsRequested;
use App\Models\CrossLinkPermission;
use App\Models\Draft;
use App\Models\LinkProfile;
use App\Models\LinkSuggestion;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\ClientSite;
use App\Services\LinkIntelligence\BuildArticleSignalsService;
use App\Services\LinkIntelligence\DefaultLinkSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('link_intelligence.embedding.service', App\Services\LinkIntelligence\Mocks\LocalMockEmbeddingService::class);
    config()->set('link_intelligence.entity_extraction.service', App\Services\LinkIntelligence\Mocks\LocalMockEntityExtractionService::class);

    Event::fake([ArticleSignalsRequested::class]);
});

it('does not generate suggestions for irrelevant articles', function () {
    [$workspace, $site] = makeWorkspaceWithSite('Org A', 'Workspace A', 'https://a.example.com');

    LinkProfile::create([
        'workspace_id' => $workspace->id,
        'default_internal_linking_enabled' => true,
        'external_suggestions_enabled' => false,
        'min_similarity_threshold' => 0.70,
        'min_audience_overlap_threshold' => 0.60,
    ]);

    $source = makeDraft($site, 'Laravel queue retries for API errors', '<p>Queue retry strategy for webhook delivery and idempotency keys.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    $target = makeDraft($site, 'Dog nutrition for small breeds', '<p>Healthy feeding schedule and vitamins for puppies and dog behavior.</p>', [
        'intent' => 'informational',
        'persona_tags' => ['pet_owner'],
        'sector_tags' => ['petcare'],
        'seniority_tags' => ['beginner'],
    ]);

    app(BuildArticleSignalsService::class)->handle($source);
    app(BuildArticleSignalsService::class)->handle($target);

    $suggestions = app(LinkSuggestionService::class)->generateSuggestions($source);

    expect($suggestions)->toHaveCount(0);
});

it('generates suggestions for relevant editorial matches above threshold', function () {
    [$workspace, $site] = makeWorkspaceWithSite('Org B', 'Workspace B', 'https://b.example.com');

    LinkProfile::create([
        'workspace_id' => $workspace->id,
        'default_internal_linking_enabled' => true,
        'external_suggestions_enabled' => false,
        'min_similarity_threshold' => 0.70,
        'min_audience_overlap_threshold' => 0.60,
    ]);

    $source = makeDraft($site, 'Laravel queue idempotency patterns', '<p>Idempotency keys, queue retries, workers, failed jobs and monitoring for production.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    $target = makeDraft($site, 'Production queue retries in Laravel', '<p>Laravel queue retries, worker tuning, idempotency keys and failure monitoring for teams.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    app(BuildArticleSignalsService::class)->handle($source);
    app(BuildArticleSignalsService::class)->handle($target);

    $suggestions = app(LinkSuggestionService::class)->generateSuggestions($source);

    expect($suggestions->count())->toBeGreaterThan(0);
    expect($suggestions->first()->similarity_score)->toBeGreaterThanOrEqual(0.70);
    expect($suggestions->first()->audience_overlap_score)->toBeGreaterThanOrEqual(0.60);
});

it('only allows cross domain suggestions when permission is approved', function () {
    [$workspaceA, $siteA] = makeWorkspaceWithSite('Org C', 'Workspace C', 'https://c.example.com');
    [$workspaceB, $siteB] = makeWorkspaceWithSite('Org D', 'Workspace D', 'https://d.example.com');

    LinkProfile::create([
        'workspace_id' => $workspaceA->id,
        'default_internal_linking_enabled' => true,
        'external_suggestions_enabled' => true,
        'min_similarity_threshold' => 0.70,
        'min_audience_overlap_threshold' => 0.60,
    ]);

    $source = makeDraft($siteA, 'API webhook retries and queue safety', '<p>Webhook retries, idempotency and queue observability for backend teams.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    $targetExternal = makeDraft($siteB, 'Queue idempotency for webhook ingestion', '<p>Queue workers, retry policies, webhook safety and idempotency in production.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    app(BuildArticleSignalsService::class)->handle($source);
    app(BuildArticleSignalsService::class)->handle($targetExternal);

    $service = app(DefaultLinkSuggestionService::class);

    $none = $service->generateSuggestions($source);
    expect($none)->toHaveCount(0);

    CrossLinkPermission::create([
        'from_workspace_id' => $workspaceA->id,
        'to_workspace_id' => $workspaceB->id,
        'status' => 'approved',
        'relationship_type' => 'partner',
        'approved_by_user_id' => User::factory()->create(['organization_id' => Organization::first()->id])->id,
        'approved_at' => now(),
    ]);

    $summary = $service->debugPoolSummary($source);
    expect($summary['external']['eligible_after_filters'])->toBeGreaterThan(0);

    $debug = $service->debugCandidates($source);
    expect($debug->contains(fn (array $row) => (string) $row['target_workspace_id'] === (string) $workspaceB->id))->toBeTrue();
});

it('respects rate limits and blocks extra suggestions', function () {
    [$workspace, $site] = makeWorkspaceWithSite('Org E', 'Workspace E', 'https://e.example.com');

    LinkProfile::create([
        'workspace_id' => $workspace->id,
        'default_internal_linking_enabled' => true,
        'external_suggestions_enabled' => false,
        'max_outbound_links_per_article' => 1,
        'min_similarity_threshold' => 0.70,
        'min_audience_overlap_threshold' => 0.60,
    ]);

    $source = makeDraft($site, 'Laravel queue throughput optimization', '<p>Queue workers and retries with idempotency for backend throughput.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    $targets = [
        makeDraft($site, 'Queue retries in Laravel', '<p>Queue retries and idempotency for robust delivery pipelines.</p>', [
            'intent' => 'technical',
            'persona_tags' => ['developer'],
            'sector_tags' => ['saas'],
            'seniority_tags' => ['senior'],
        ]),
        makeDraft($site, 'Failed job monitoring in Laravel queues', '<p>Monitoring failed jobs and queue retries with idempotency controls.</p>', [
            'intent' => 'technical',
            'persona_tags' => ['developer'],
            'sector_tags' => ['saas'],
            'seniority_tags' => ['senior'],
        ]),
    ];

    app(BuildArticleSignalsService::class)->handle($source);
    foreach ($targets as $target) {
        app(BuildArticleSignalsService::class)->handle($target);
    }

    $suggestions = app(LinkSuggestionService::class)->generateSuggestions($source);

    expect($suggestions)->toHaveCount(1);
});

it('applies approved suggestion to content and stores audit fields', function () {
    [$workspace, $site] = makeWorkspaceWithSite('Org F', 'Workspace F', 'https://f.example.com');

    LinkProfile::create([
        'workspace_id' => $workspace->id,
    ]);

    $source = makeDraft($site, 'Queue idempotency basics', '<p>Learn queue idempotency basics for webhook processing and retries.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
    ]);

    $target = makeDraft($site, 'Webhook retry strategy', '<p>Webhook retries and queue worker safety patterns for backend apps.</p>', [
        'intent' => 'technical',
        'persona_tags' => ['developer'],
        'sector_tags' => ['saas'],
        'seniority_tags' => ['senior'],
        'canonical_url' => 'https://f.example.com/webhook-retry-strategy',
    ]);

    app(BuildArticleSignalsService::class)->handle($source);
    app(BuildArticleSignalsService::class)->handle($target);

    $suggestion = LinkSuggestion::create([
        'source_article_id' => $source->id,
        'target_article_id' => $target->id,
        'source_workspace_id' => $workspace->id,
        'target_workspace_id' => $workspace->id,
        'source_client_site_id' => $site->id,
        'target_client_site_id' => $site->id,
        'similarity_score' => 0.92,
        'shared_entities' => ['queue', 'idempotency'],
        'intent_match_score' => 1.0,
        'audience_overlap_score' => 1.0,
        'suggested_anchor_variants' => ['queue idempotency basics'],
        'suggested_placement' => 'inline',
        'status' => 'approved',
        'reviewed_by_user_id' => User::factory()->create(['organization_id' => $workspace->organization_id])->id,
        'reviewed_at' => now(),
    ]);

    app(LinkApplyService::class)->applySuggestion($suggestion, new ApplyOptions(
        placement: 'inline',
        anchorText: 'queue idempotency basics',
    ));

    $source->refresh();
    $suggestion->refresh();

    expect($source->content_html)->toContain('<a href="https://f.example.com/webhook-retry-strategy">queue idempotency basics</a>');
    expect($suggestion->status)->toBe('applied');
    expect($suggestion->applied_at)->not->toBeNull();
    expect($suggestion->reviewed_at)->not->toBeNull();
});

it('normalizes comma separated audience strings and computes overlap', function () {
    [$workspace, $site] = makeWorkspaceWithSite('Org G', 'Workspace G', 'https://g.example.com');

    LinkProfile::create([
        'workspace_id' => $workspace->id,
        'default_internal_linking_enabled' => true,
        'external_suggestions_enabled' => false,
        'min_similarity_threshold' => 0.70,
        'min_audience_overlap_threshold' => 0.60,
    ]);

    $source = makeDraft($site, 'Agentic AI architecture', '<p>AI strategy and architecture for teams.</p>', [
        'intent' => 'technical',
        'audience_tags' => 'developer,cto,founder,operations',
    ]);

    $target = makeDraft($site, 'From pilot to production', '<p>Operationalizing AI across teams.</p>', [
        'intent' => 'technical',
        'audience_tags' => 'cto,founder,operations',
    ]);

    app(BuildArticleSignalsService::class)->handle($source);
    app(BuildArticleSignalsService::class)->handle($target);

    $rows = app(DefaultLinkSuggestionService::class)->debugCandidates($source);
    $targetRow = $rows->firstWhere('target_article_id', (string) $target->id);

    expect($targetRow)->not->toBeNull();
    expect((float) ($targetRow['audience_overlap_score'] ?? 0))->toBeGreaterThanOrEqual(0.70);
});

it('computes audience overlap from brief audience when draft meta is missing', function () {
    [$workspace, $site] = makeWorkspaceWithSite('Org H', 'Workspace H', 'https://h.example.com');

    LinkProfile::create([
        'workspace_id' => $workspace->id,
        'default_internal_linking_enabled' => true,
        'external_suggestions_enabled' => false,
        'min_similarity_threshold' => 0.70,
        'min_audience_overlap_threshold' => 0.60,
    ]);

    $source = makeDraft($site, 'AI architecture', '<p>Architecture for AI adoption.</p>', [
        'intent' => 'technical',
        'audience_tags' => [],
    ]);
    $target = makeDraft($site, 'AI operations', '<p>Operating AI in production.</p>', [
        'intent' => 'technical',
        'audience_tags' => [],
    ]);

    $sourceBrief = $source->brief()->first();
    $targetBrief = $target->brief()->first();
    $sourceBrief->update(['audience' => 'developer,cto,founder,operations']);
    $targetBrief->update(['audience' => 'cto,founder,operations']);

    app(BuildArticleSignalsService::class)->handle($source->fresh());
    app(BuildArticleSignalsService::class)->handle($target->fresh());

    $rows = app(DefaultLinkSuggestionService::class)->debugCandidates($source->fresh());
    $targetRow = $rows->firstWhere('target_article_id', (string) $target->id);

    expect($targetRow)->not->toBeNull();
    expect((float) ($targetRow['audience_overlap_score'] ?? 0))->toBeGreaterThanOrEqual(0.70);
});

function makeWorkspaceWithSite(string $orgName, string $workspaceName, string $siteUrl): array
{
    $org = Organization::create([
        'name' => $orgName,
        'slug' => str()->slug($orgName . '-' . uniqid()),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => $workspaceName,
        'organization_id' => $org->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => $workspaceName . ' Site',
        'site_url' => $siteUrl,
        'allowed_domains' => [parse_url($siteUrl, PHP_URL_HOST)],
        'is_active' => true,
    ]);

    return [$workspace, $site];
}

function makeDraft(ClientSite $site, string $title, string $contentHtml, array $meta = []): Draft
{
    return Draft::create([
        'brief_id' => createBriefForSite($site)->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'title' => $title,
        'output_type' => 'kb_article',
        'content_html' => $contentHtml,
        'meta' => $meta,
        'links' => [],
    ]);
}

function createBriefForSite(ClientSite $site)
{
    return App\Models\Brief::create([
        'client_site_id' => $site->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief ' . uniqid(),
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);
}
