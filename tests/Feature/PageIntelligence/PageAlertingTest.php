<?php

use App\Models\AlertRule;
use App\Models\MonitoredPage;
use App\Models\Notification;
use App\Models\PageAlert;
use App\Models\PageMention;
use App\Models\PagePrValue;
use App\Models\PageSentiment;
use App\Models\PageSnapshot;
use App\Models\RecommendedAction;
use App\Services\PageIntelligence\Alerts\PageAlertRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fires an alert rule when configured conditions match', function (): void {
    [$page, $rule] = pageAlertHighPrValueScenario(minScore: 75, severity: 'medium');

    $alerts = app(PageAlertRuleEvaluator::class)->evaluateRule($rule);

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first())->toBeInstanceOf(PageAlert::class)
        ->and($alerts->first()->monitored_page_id)->toBe($page->id)
        ->and($alerts->first()->trigger)->toBe(AlertRule::TRIGGER_HIGH_PR_VALUE_PAGE);
});

it('prevents duplicate alerts during the rule cooldown window', function (): void {
    [, $rule] = pageAlertHighPrValueScenario(minScore: 75, severity: 'medium');

    app(PageAlertRuleEvaluator::class)->evaluateRule($rule);
    app(PageAlertRuleEvaluator::class)->evaluateRule($rule);

    $alert = PageAlert::query()->where('alert_rule_id', $rule->id)->firstOrFail();

    expect(PageAlert::query()->where('alert_rule_id', $rule->id)->count())->toBe(1)
        ->and($alert->alert_key)->toBe($alert->dedupe_hash);
});

it('creates a notification for fired page alerts', function (): void {
    [, $rule] = pageAlertHighPrValueScenario(minScore: 75, severity: 'medium');

    $alert = app(PageAlertRuleEvaluator::class)->evaluateRule($rule)->first();

    expect($alert->notification_id)->not->toBeNull()
        ->and(Notification::query()->whereKey($alert->notification_id)->exists())->toBeTrue()
        ->and(Notification::query()->whereKey($alert->notification_id)->first()->meta['source'])->toBe('page_intelligence_alert');
});

it('creates a recommended action for high severity page alerts', function (): void {
    [, $rule] = pageAlertHighPrValueScenario(minScore: 75, severity: 'high');

    $alert = app(PageAlertRuleEvaluator::class)->evaluateRule($rule)->first();

    expect($alert->recommended_action_id)->not->toBeNull()
        ->and(RecommendedAction::query()->whereKey($alert->recommended_action_id)->exists())->toBeTrue()
        ->and(RecommendedAction::query()->whereKey($alert->recommended_action_id)->first()->source_group)->toBe(RecommendedAction::SOURCE_AI_VISIBILITY);
});

it('supports dismissed and resolved alert states', function (): void {
    $alert = PageAlert::factory()->create();

    $alert->markDismissed();
    expect($alert->status)->toBe(PageAlert::STATUS_DISMISSED)
        ->and($alert->dismissed_at)->not->toBeNull();

    $alert = PageAlert::factory()->create();
    $alert->markResolved();

    expect($alert->status)->toBe(PageAlert::STATUS_RESOLVED)
        ->and($alert->resolved_at)->not->toBeNull();
});

it('fires a new page mentioning brand alert', function (): void {
    $page = MonitoredPage::factory()->create();
    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $rule = AlertRule::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'trigger' => AlertRule::TRIGGER_NEW_BRAND_PAGE,
        'conditions_json' => ['window_minutes' => 1440],
        'cooldown_minutes' => 60,
        'severity' => 'medium',
    ]);

    PageMention::query()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'mention_type' => 'brand',
        'entity_type' => 'brand',
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly',
        'matched_text' => 'Argusly',
        'source_field' => 'main_text',
        'position_start' => 0,
        'position_end' => 7,
        'evidence_snippet' => 'Argusly was mentioned in a new article.',
        'confidence_score' => 95,
        'observed_at' => now(),
        'analysis_method' => 'test',
        'model_used' => 'test',
        'dedupe_hash' => hash('sha256', $page->id.'|brand'),
    ]);

    $alerts = app(PageAlertRuleEvaluator::class)->evaluateRule($rule);

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->title)->toContain('Argusly');
});

it('fires a high-risk negative page alert for negative sentiment on high authority sources', function (): void {
    $page = MonitoredPage::factory()->create();
    $page->source->forceFill(['authority_score' => 91, 'trust_level' => 5])->save();
    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $rule = AlertRule::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'trigger' => AlertRule::TRIGGER_HIGH_RISK_NEGATIVE_PAGE,
        'conditions_json' => ['min_source_authority' => 80, 'max_compound_score' => -0.1, 'window_minutes' => 1440],
        'severity' => 'high',
    ]);

    PageSentiment::query()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'target_type' => PageSentiment::TARGET_PAGE,
        'target_key' => 'page:'.$page->id,
        'target_name' => $page->title_current,
        'compound_score' => -0.52,
        'label' => 'negative',
        'confidence_score' => 0.88,
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'explanation' => 'Negative coverage on an authoritative source.',
        'evidence_json' => [],
        'analyzed_at' => now(),
    ]);

    $alerts = app(PageAlertRuleEvaluator::class)->evaluateRule($rule);

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->trigger)->toBe(AlertRule::TRIGGER_HIGH_RISK_NEGATIVE_PAGE)
        ->and($alerts->first()->title)->toBe('High-risk negative page detected');
});

/**
 * @return array{0:MonitoredPage,1:AlertRule}
 */
function pageAlertHighPrValueScenario(int $minScore, string $severity): array
{
    $page = MonitoredPage::factory()->create();
    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $rule = AlertRule::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'trigger' => AlertRule::TRIGGER_HIGH_PR_VALUE_PAGE,
        'conditions_json' => ['min_score' => $minScore, 'window_minutes' => 1440],
        'cooldown_minutes' => 120,
        'severity' => $severity,
    ]);

    PagePrValue::query()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'model_key' => 'argusly_pr_value',
        'model_version' => 'test',
        'score' => 88,
        'estimated_value_amount' => 12500,
        'currency' => 'EUR',
        'confidence' => 90,
        'breakdown_json' => ['test' => true],
        'calculated_at' => now(),
    ]);

    return [$page, $rule];
}
