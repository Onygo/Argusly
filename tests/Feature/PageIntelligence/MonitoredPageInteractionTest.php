<?php

use App\Http\Controllers\App\AppMonitoredPageController;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\Organization;
use App\Models\PageAlert;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageMention;
use App\Models\PagePrValue;
use App\Models\PageSentiment;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\RecommendedAction;
use App\Models\SignalEvent;
use App\Models\User;
use App\Support\Interaction\AppInteractionRegistry;
use App\Support\Interaction\MonitoredPageDataTable;
use App\Support\Interaction\MonitoredPageMetadataProvider;
use App\Support\Interaction\Providers\AppPageIntelligenceInteractionProvider;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('resolves monitored page as a universal resource', function (): void {
    $page = MonitoredPage::factory()->create();
    $user = monitoredPageInteractionUser($page);

    $registry = AppInteractionRegistry::resourceRegistryFor([$page]);
    $resource = $registry->resolve(ResourceType::MONITORED_PAGE.':'.$page->id, ResourceContext::make([
        'user' => $user,
        'subject' => $page,
        'metadata' => ['subject' => $page],
    ]));

    expect($registry->hasType(ResourceType::MONITORED_PAGE))->toBeTrue()
        ->and($resource)->toBeArray()
        ->and($resource['type'])->toBe(ResourceType::MONITORED_PAGE)
        ->and($resource['primary_route']['name'])->toBe('app.page-intelligence.monitored-pages.show')
        ->and($resource['drawer']['target'])->toBe('monitored-page.inspect')
        ->and($resource['available_actions'])->toBe([AppPageIntelligenceInteractionProvider::ACTION_MONITORED_PAGE_OPEN]);
});

it('generates monitored page drawer metadata for inspection', function (): void {
    [$page, $snapshot] = monitoredPageInteractionPageWithEvidence();
    $content = Content::factory()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'title' => 'Memory linked content',
        'published_url' => $page->canonical_url,
    ]);
    ContentPageLink::factory()
        ->forContentAndPage($content, $page)
        ->create(['confidence_score' => 94.0]);
    $alert = PageAlert::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'title' => 'Memory alert evidence',
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
        'title' => 'Memory recommended action',
        'summary' => 'Use memory evidence.',
        'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
        'priority_score' => 90,
        'confidence_score' => 88,
        'expected_impact_score' => 90,
        'priority_label' => 'high',
        'confidence_label' => 'high',
        'expected_impact_label' => 'high',
        'metadata' => ['recommended_next_step' => 'Inspect evidence and prepare guidance.'],
        'visible_at' => now(),
    ]);
    $alert->forceFill(['recommended_action_id' => $action->id])->save();

    $drawer = app(MonitoredPageMetadataProvider::class)->forPage($page);
    $sections = collect($drawer['sections'])->keyBy('key');

    expect($drawer['resource_type'])->toBe(ResourceType::MONITORED_PAGE)
        ->and($drawer['title'])->toBe('Argusly market mention')
        ->and($sections)->toHaveKeys([
            'page',
            'marketing_memory',
            'latest_snapshot',
            'summary',
            'entities_mentions',
            'sentiment',
            'topics',
            'pr_value',
            'linked_signal_events',
            'recommended_next_actions',
        ])
        ->and($sections['summary']['items'][0]['value'])->toContain('Argusly is cited')
        ->and($sections['entities_mentions']['items'][0]['value'])->toContain('Argusly')
        ->and($sections['sentiment']['items'][0]['value'])->toContain('positive')
        ->and($sections['topics']['items'][0]['value'])->toContain('AI visibility')
        ->and($sections['pr_value']['items'][0]['value'])->toContain('Source Authority')
        ->and($sections['marketing_memory']['items'][0]['value'])->toContain('Memory linked content')
        ->and($sections['marketing_memory']['items'][1]['value'])->toContain('Memory recommended action')
        ->and($sections['marketing_memory']['items'][3]['value'])->toContain('Acts On')
        ->and($sections['latest_snapshot']['items'][0]['value'])->toBe((string) $snapshot->http_status)
        ->and($drawer['metadata']['snapshot_internal'])->toBeTrue();
});

it('does not allow users from another organization to inspect a monitored page', function (): void {
    $page = MonitoredPage::factory()->create();
    $otherOrganization = Organization::query()->create([
        'name' => 'Other Page Intelligence Org',
        'slug' => 'other-page-intelligence-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $user = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $request = Request::create('/page-intelligence/monitored-pages/'.$page->getRouteKey(), 'GET');
    $request->setUserResolver(fn (): User => $user);

    try {
        app(AppMonitoredPageController::class)->show($request, $page, app(MonitoredPageMetadataProvider::class));
        $this->fail('Unauthorized monitored page inspection did not abort.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('builds monitored page data table rows without N+1 relation queries', function (): void {
    $first = MonitoredPage::factory()->create();

    foreach (range(1, 5) as $index) {
        $page = MonitoredPage::factory()->create([
            'organization_id' => $first->organization_id,
            'workspace_id' => $first->workspace_id,
        ]);
        $snapshot = PageSnapshot::factory()->forPage($page, $index)->create();
        PageContentExtraction::factory()->forSnapshot($snapshot)->create(['summary' => 'Summary '.$index]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $table = app(MonitoredPageDataTable::class);
    $pages = $table->queryForWorkspace($first->workspace_id)->limit(5)->get();
    $rows = $table->rows($pages);
    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($rows)->toHaveCount(5)
        ->and($queryCount)->toBeLessThanOrEqual(8);

    foreach ($pages as $page) {
        expect($page->relationLoaded('source'))->toBeTrue()
            ->and($page->relationLoaded('latestSnapshot'))->toBeTrue();
    }
});

function monitoredPageInteractionUser(MonitoredPage $page): User
{
    return User::factory()->create([
        'organization_id' => $page->organization_id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
}

function monitoredPageInteractionPageWithEvidence(): array
{
    $page = MonitoredPage::factory()->create([
        'title_current' => 'Argusly market mention',
        'canonical_url' => 'https://example.com/argusly-market-mention',
        'domain' => 'example.com',
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page, 3)->create([
        'http_status' => 200,
        'content_changed' => true,
        'fetched_at' => now(),
    ]);
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => 'Argusly market mention',
        'summary' => 'Argusly is cited as a strong AI visibility platform.',
        'word_count' => 420,
        'quality_score' => 87.5,
    ]);

    PageEntity::query()->create(monitoredPageInteractionAnalysisAttributes($page, $snapshot, $extraction) + [
        'entity_type' => PageEntity::TYPE_BRAND,
        'entity_key' => 'brand:argusly',
        'entity_name' => 'Argusly',
        'source_type' => 'company_profile',
        'mention_count' => 2,
        'first_position' => 15,
        'prominence_score' => 0.82,
        'confidence_score' => 0.95,
        'evidence_json' => [['snippet' => 'Argusly is cited']],
        'analysis_method' => 'deterministic',
        'analyzer_version' => 'test',
        'observed_at' => now(),
    ]);
    PageMention::query()->create(monitoredPageInteractionAnalysisAttributes($page, $snapshot, $extraction) + [
        'mention_type' => 'brand',
        'entity_type' => PageEntity::TYPE_BRAND,
        'entity_key' => 'brand:argusly',
        'entity_name' => 'Argusly',
        'matched_text' => 'Argusly',
        'source_field' => 'main_text',
        'position_start' => 15,
        'position_end' => 22,
        'evidence_snippet' => 'Argusly is cited as a strong platform.',
        'confidence_score' => 0.95,
        'observed_at' => now(),
        'analysis_method' => 'deterministic',
        'dedupe_hash' => hash('sha256', 'argusly-test-mention'),
    ]);
    PageSentiment::query()->create(monitoredPageInteractionAnalysisAttributes($page, $snapshot, $extraction) + [
        'target_type' => PageSentiment::TARGET_PAGE,
        'target_key' => 'page:'.$page->id,
        'target_name' => 'Argusly market mention',
        'compound_score' => 0.45,
        'label' => 'positive',
        'confidence_score' => 0.8,
        'analysis_method' => 'deterministic',
        'model_used' => 'lexicon',
        'analyzer_version' => 'test',
        'explanation' => 'Positive visibility language.',
        'evidence_json' => [['snippet' => 'strong AI visibility platform']],
        'analyzed_at' => now(),
    ]);
    PageTopic::query()->create(monitoredPageInteractionAnalysisAttributes($page, $snapshot, $extraction) + [
        'topic_key' => 'ai_visibility',
        'topic_name' => 'AI visibility',
        'topic_type' => 'market',
        'source_type' => 'taxonomy',
        'mention_count' => 1,
        'first_position' => 32,
        'prominence_score' => 0.71,
        'confidence_score' => 0.88,
        'keywords_json' => ['AI visibility'],
        'evidence_json' => [['snippet' => 'AI visibility platform']],
        'classification_method' => 'deterministic',
        'classifier_version' => 'test',
        'classified_at' => now(),
    ]);
    PagePrValue::query()->create(monitoredPageInteractionAnalysisAttributes($page, $snapshot, $extraction) + [
        'model_key' => 'argusly',
        'model_version' => '1.0',
        'score' => 74.25,
        'estimated_value_amount' => 1250,
        'currency' => 'EUR',
        'confidence' => 0.7,
        'breakdown_json' => ['source_authority' => 0.8, 'brand_prominence' => 0.82],
        'calculated_at' => now(),
    ]);
    SignalEvent::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'topic' => 'Brand mention on monitored page',
        'metadata' => ['monitored_page_id' => $page->id],
        'evidence' => ['monitored_page_id' => $page->id],
    ]);

    return [$page, $snapshot];
}

function monitoredPageInteractionAnalysisAttributes(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction): array
{
    return [
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'page_content_extraction_id' => $extraction->id,
    ];
}
