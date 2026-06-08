<?php

use App\Models\ClientSite;
use App\Models\CompetitorContentItem;
use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\Organization;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\CompetitorIntelligence\CompetitorContentImportPipeline;
use App\Services\CompetitorIntelligence\CompetitorIntelligenceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeCompetitorIntelligenceScope(): array
{
    $organization = Organization::query()->create([
        'name' => 'Competitor Intelligence Org',
        'slug' => 'competitor-intelligence-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Competitor Intelligence Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Argusly Demo',
        'site_url' => 'https://argusly.example.com',
        'base_url' => 'https://argusly.example.com',
        'allowed_domains' => ['argusly.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'RivalLayer',
        'domain' => 'rivallayer.example.com',
        'notes' => 'Demo competitor.',
        'is_active' => true,
    ]);

    return [$organization, $workspace, $site, $competitor];
}

it('imports competitor content into normalized intelligence signals', function () {
    [, , , $competitor] = makeCompetitorIntelligenceScope();

    $item = app(CompetitorContentImportPipeline::class)->import($competitor, [
        'url' => 'rivallayer.example.com/argusly-alternatives',
        'title' => 'Best Argusly Alternatives for Agentic Marketing',
        'meta_description' => 'Compare platforms, pricing, features, and AI visibility workflows.',
        'content_excerpt' => 'This comparison page covers Argusly alternatives, pricing, demos, answer blocks, and competitor content gap workflows.',
    ]);

    expect($item)->toBeInstanceOf(CompetitorContentItem::class)
        ->and($item->url)->toBe('https://rivallayer.example.com/argusly-alternatives')
        ->and($item->query_intent)->toBe('comparison')
        ->and($item->funnel_stage)->toBe('bofu')
        ->and($item->is_comparison_page)->toBeTrue()
        ->and($item->detected_topics)->not->toBeEmpty()
        ->and($item->normalized_payload['intelligence']['seo_patterns'])->toBeArray();
});

it('analyzes competitor topics and creates scored opportunity outputs', function () {
    [, $workspace, , $competitor] = makeCompetitorIntelligenceScope();

    $pipeline = app(CompetitorContentImportPipeline::class);
    $pipeline->import($competitor, [
        'url' => 'https://rivallayer.example.com/argusly-alternatives',
        'title' => 'Argusly Alternatives and Pricing Comparison',
        'meta_description' => 'Compare agentic marketing platforms for AI visibility and content operations.',
        'content_excerpt' => 'A comparison page for Argusly alternatives, pricing, BOFU demos, AI visibility, and competitor opportunity workflows.',
    ]);
    $pipeline->import($competitor, [
        'url' => 'https://rivallayer.example.com/ai-visibility-implementation-guide',
        'title' => 'AI Visibility Implementation Guide',
        'meta_description' => 'How content teams implement answer blocks, schema, and AI search tracking.',
        'content_excerpt' => 'This implementation guide explains setup, workflow configuration, answer blocks, recurring topics, schema, and entity extraction for AI visibility.',
    ]);

    $run = app(CompetitorIntelligenceAnalyzer::class)->analyze($workspace, $competitor, ['test' => true]);

    expect($run->status)->toBe('completed')
        ->and($run->content_items_count)->toBe(2)
        ->and(CompetitorTopicSignal::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and(CompetitorContentOpportunity::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and(CompetitorContentOpportunity::query()->where('type', 'comparison_page')->exists())->toBeTrue()
        ->and(CompetitorContentOpportunity::query()->whereIn('type', ['implementation_guide', 'answer_block_gap'])->exists())->toBeTrue();
});
