<?php

use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\PageAlert;
use App\Models\RecommendedAction;
use App\Services\MarketingMemory\ContentInventoryGraphContextBuilder;
use App\Support\Intelligence\Testing\FakeIntelligenceGraphProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('projects content inventory links and page alert actions into graph context', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/graph-context',
        'title_current' => 'Graph context page',
    ]);
    $content = Content::factory()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'title' => 'Graph context content',
        'published_url' => 'https://example.com/graph-context',
    ]);
    ContentPageLink::factory()
        ->forContentAndPage($content, $page)
        ->create(['confidence_score' => 92.0]);

    $alert = PageAlert::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'title' => 'High-risk page evidence',
        'severity' => 'high',
    ]);
    $action = RecommendedAction::query()->create([
        'workspace_id' => $page->workspace_id,
        'organization_id' => $page->organization_id,
        'source_type' => PageAlert::class,
        'source_id' => $alert->id,
        'source_signature' => 'page_alert:'.$alert->id.':recommended_action',
        'source_group' => RecommendedAction::SOURCE_AI_VISIBILITY,
        'action_type' => 'prepare_reputation_response',
        'status' => RecommendedAction::STATUS_OPEN,
        'title' => 'Prepare response',
        'summary' => 'Inspect page evidence.',
        'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
        'priority_score' => 90,
        'confidence_score' => 88,
        'expected_impact_score' => 90,
        'priority_label' => 'high',
        'confidence_label' => 'high',
        'expected_impact_label' => 'high',
        'metadata' => [
            'action_category' => 'risk_response',
            'recommended_next_step' => 'Inspect evidence and prepare guidance.',
        ],
        'visible_at' => now(),
    ]);
    $alert->forceFill(['recommended_action_id' => $action->id])->save();

    $graph = app(ContentInventoryGraphContextBuilder::class)
        ->projectContent($content, new FakeIntelligenceGraphProjector())
        ->graph();

    $nodeKeys = collect($graph['nodes'])->pluck('key');
    $edges = collect($graph['edges']);

    expect($nodeKeys)->toContain(
        'content:'.$content->id,
        'page:'.$page->id,
        'page_alert:'.$alert->id,
        'action:'.$action->id,
    );

    $contentToPage = $edges
        ->first(fn (array $edge): bool => $edge['type'] === 'derives_from'
            && $edge['source_key'] === 'content:'.$content->id
            && $edge['target_key'] === 'page:'.$page->id);
    $actionToPage = $edges
        ->first(fn (array $edge): bool => $edge['type'] === 'acts_on'
            && $edge['source_key'] === 'action:'.$action->id
            && $edge['target_key'] === 'page:'.$page->id);

    expect($contentToPage)->not->toBeNull()
        ->and($contentToPage['confidence'])->toBe(0.92)
        ->and($actionToPage)->not->toBeNull()
        ->and(data_get($actionToPage, 'metadata.recommended_next_step'))->toBe('Inspect evidence and prepare guidance.');
});
