<?php

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Drafts\DraftSmartSuggestionsAgent;
use App\Enums\DraftType;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Draft;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows an authorized user to run smart suggestions for a draft and reload the persisted result', function () {
    [$owner, $viewer, $content, $draft] = makeManualAgentExecutionContext('manual-draft-agent');

    $this->actingAs($owner)
        ->post(route('app.drafts.smart-suggestions.run', $draft))
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    expect($run->agent_key)->toBe(DraftSmartSuggestionsAgent::KEY)
        ->and($run->trigger_type)->toBe('manual')
        ->and($run->trigger_source)->toBe('app.drafts.smart_suggestions')
        ->and($run->draft_id)->toBe($draft->id)
        ->and($run->content_id)->toBe($content->id)
        ->and($run->user_id)->toBe($owner->id)
        ->and(data_get($run->output_payload, 'summary'))->toContain('Smart suggestions prepared for the EN draft');

    $this->actingAs($owner)
        ->get(route('app.drafts.show', [
            'draft' => $draft,
            'tab' => 'intelligence',
            'smart_suggestions_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Smart suggestions')
        ->assertSee('Success');
});

it('prevents unauthorized users from running smart suggestions for a draft', function () {
    [$owner, $viewer, $content, $draft] = makeManualAgentExecutionContext('manual-draft-agent-forbidden');

    $this->actingAs($viewer)
        ->post(route('app.drafts.smart-suggestions.run', $draft))
        ->assertForbidden();

    expect(AgentRun::query()->count())->toBe(0);
});

it('allows an authorized user to run refresh recommendations for content and reload the persisted result', function () {
    [$owner, $viewer, $content, $draft] = makeManualAgentExecutionContext('manual-content-agent');

    $this->actingAs($owner)
        ->post(route('app.content.refresh-recommendations.run', $content))
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    expect($run->agent_key)->toBe(ContentRefreshAgent::KEY)
        ->and($run->trigger_type)->toBe('manual')
        ->and($run->trigger_source)->toBe('app.content.refresh_recommendations')
        ->and($run->content_id)->toBe($content->id)
        ->and($run->site_id)->toBe($content->client_site_id)
        ->and($run->user_id)->toBe($owner->id)
        ->and(data_get($run->output_payload, 'metrics.refresh_score'))->toBeGreaterThan(0);

    $this->actingAs($owner)
        ->get(route('app.content.show', [
            'content' => $content,
            'tab' => 'overview',
            'refresh_recommendations_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Content Health')
        ->assertSee('Freshness')
        ->assertSee('Refresh score')
        ->assertSee('Create refresh draft')
        ->assertSee('Missing SEO structure');
});

it('prevents unauthorized users from running refresh recommendations for content', function () {
    [$owner, $viewer, $content, $draft] = makeManualAgentExecutionContext('manual-content-agent-forbidden');

    $this->actingAs($viewer)
        ->post(route('app.content.refresh-recommendations.run', $content))
        ->assertForbidden();

    expect(AgentRun::query()->count())->toBe(0);
});

it('ignores a stale refresh_recommendations_run query parameter when a newer run matches the latest editable revision', function () {
    [$owner, $viewer, $content, $draft] = makeManualAgentExecutionContext('manual-content-agent-stale-run');

    $staleRun = AgentRun::query()->create([
        'id' => (string) Str::uuid(),
        'agent_key' => ContentRefreshAgent::KEY,
        'trigger_type' => 'manual',
        'trigger_source' => 'app.content.refresh_recommendations',
        'organization_id' => $owner->organization_id,
        'workspace_id' => $content->workspace_id,
        'site_id' => $content->client_site_id,
        'content_id' => $content->id,
        'user_id' => $owner->id,
        'input_payload' => [
            'metadata' => [],
        ],
        'output_payload' => [
            'summary' => 'Old refresh recommendation summary.',
            'metrics' => ['refresh_score' => 22],
            'raw_payload' => [
                'refresh_score' => 22,
                'reasons' => [
                    ['title' => 'Old issue', 'description' => 'This stale run should not win.'],
                ],
                'suggested_actions' => [],
            ],
        ],
        'summary' => 'Old refresh recommendation summary.',
        'status' => \App\Agents\Support\AgentRunStatus::SUCCESS->value,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(10),
    ]);

    $latestDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $draft->brief_id,
        'content_id' => $content->id,
        'client_site_id' => $content->client_site_id,
        'status' => 'generated',
        'title' => 'Manual agent improved draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>Freshly improved body copy for the latest editable revision.</p>',
    ]);

    $currentHash = sha1('Freshly improved body copy for the latest editable revision.');

    AgentRun::query()->create([
        'id' => (string) Str::uuid(),
        'agent_key' => ContentRefreshAgent::KEY,
        'trigger_type' => 'event',
        'trigger_source' => 'content_improvement.completed',
        'organization_id' => $owner->organization_id,
        'workspace_id' => $content->workspace_id,
        'site_id' => $content->client_site_id,
        'content_id' => $content->id,
        'draft_id' => $latestDraft->id,
        'user_id' => $owner->id,
        'input_payload' => [
            'metadata' => [
                'source_revision_hash' => $currentHash,
                'target_draft_id' => $latestDraft->id,
            ],
        ],
        'output_payload' => [
            'summary' => 'Fresh refresh recommendation summary.',
            'metrics' => ['refresh_score' => 31],
            'raw_payload' => [
                'refresh_score' => 31,
                'reasons' => [
                    ['title' => 'Fresh issue', 'description' => 'Newest run matched the latest editable revision.'],
                ],
                'suggested_actions' => [],
            ],
        ],
        'summary' => 'Fresh refresh recommendation summary.',
        'status' => \App\Agents\Support\AgentRunStatus::SUCCESS->value,
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $controller = app(\App\Http\Controllers\App\AppContentController::class);
    $method = new ReflectionMethod($controller, 'resolveSelectedRefreshRecommendationsRun');
    $method->setAccessible(true);

    $request = \Illuminate\Http\Request::create('/content/' . $content->id, 'GET', [
        'refresh_recommendations_run' => $staleRun->id,
    ]);

    $resolved = $method->invoke($controller, $request, $content->fresh(['drafts', 'currentVersion', 'currentRevision']));

    expect($resolved)->not->toBeNull()
        ->and((string) $resolved->id)->not->toBe((string) $staleRun->id)
        ->and((string) data_get($resolved->output_payload, 'raw_payload.reasons.0.title'))->toBe('Fresh issue');
});

function makeManualAgentExecutionContext(string $prefix = 'manual-agent'): array
{
    $organization = Organization::query()->create([
        'name' => 'Manual Agent Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Manual Agent BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Manual Agent Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Manual Agent Site',
        'site_url' => 'https://manual-agent.example.com',
        'allowed_domains' => ['manual-agent.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'manual-agent-test-plan'],
        [
            'name' => 'Manual Agent Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Manual Agent Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $viewer = User::query()->create([
        'name' => 'Manual Agent Viewer',
        'email' => $prefix . '+viewer@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Manual agent content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'manual agent workflow',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Manual agent brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Manual agent draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'seo_meta_description' => '',
        'content_html' => '<p>Short body.</p>',
    ]);

    return [$owner, $viewer, $content, $draft];
}
