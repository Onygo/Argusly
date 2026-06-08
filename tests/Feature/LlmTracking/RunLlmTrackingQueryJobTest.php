<?php

use App\Jobs\LlmTracking\RunLlmTrackingQueryJob;
use App\Models\ClientSite;
use App\Models\CreditReservation;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditReservationService;
use App\Services\LlmTracking\LlmVisibilityTrackingService;
use App\Services\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createLlmTrackingContext(int $llmLimit): array
{
    $organization = Organization::query()->create([
        'name' => 'LLM Tracking Org',
        'slug' => 'llm-tracking-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'LLM Tracking Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'LLM Tracking Site',
        'site_url' => 'https://llm-tracking.example.com',
        'allowed_domains' => ['llm-tracking.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'llm-tracking-plan-' . Str::random(4),
        'slug' => 'llm-tracking-plan-' . Str::random(4),
        'name' => 'LLM Tracking Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'limits' => ['users' => 3, 'sites' => 3, 'workspaces' => 1],
        'is_active' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'llm_tracking_queries_per_month_limit',
        'value_type' => 'int',
        'value_int' => $llmLimit,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Visibility check',
        'query_text' => 'Best platform for B2B content workflow',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['AcmeSEO'],
        'target_urls' => ['https://argusly.com/features'],
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    return [$workspace, $site, $query];
}

it('creates a run record and updates flags when job succeeds', function () {
    [$workspace, $site, $query] = createLlmTrackingContext(10);

    $quotaMock = \Mockery::mock(PlanQuotaService::class)->shouldIgnoreMissing();
    $quotaMock->shouldReceive('assertCanRunLlmQuery')->once();
    $this->app->instance(PlanQuotaService::class, $quotaMock);

    $reservation = CreditReservation::query()->make([
        'status' => CreditReservation::STATUS_RESERVED,
        'client_site_id' => $site->id,
        'amount' => 1,
    ]);
    $reservationsMock = \Mockery::mock(CreditReservationService::class)->shouldIgnoreMissing();
    $reservationsMock->shouldReceive('reserve')->once()->andReturn($reservation);
    $reservationsMock->shouldReceive('capture')->once();
    $this->app->instance(CreditReservationService::class, $reservationsMock);

    $mock = \Mockery::mock(LlmVisibilityTrackingService::class);
    $mock->shouldReceive('resolveRoute')->once()->andReturn([
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
    ]);
    $mock->shouldReceive('run')->once()->andReturn([
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'raw_response' => ['id' => 'resp_123'],
        'parsed_payload' => [
            'response_text' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.',
        ],
        'answer_text' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.',
        'brand_hits' => [['term' => 'Argusly', 'count' => 1, 'bucket' => 'first']],
        'competitor_hits' => [['term' => 'AcmeSEO', 'count' => 1, 'bucket' => 'last']],
        'entity_presence' => [
            ['term' => 'Argusly', 'type' => 'brand', 'present' => true, 'count' => 1, 'position_score' => 1.0],
            ['term' => 'AcmeSEO', 'type' => 'competitor', 'present' => true, 'count' => 1, 'position_score' => 0.2],
        ],
        'url_hits' => [['target_url' => 'https://argusly.com/features', 'count' => 1, 'bucket' => 'middle']],
        'citation_ranking' => ['brand' => ['bucket' => 'first'], 'url' => ['bucket' => 'middle']],
        'sources' => [['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website']],
        'share_of_voice_snapshot' => ['share_brand' => 0.5],
        'suggestions' => [],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => true,
        'presence_score' => 1.0,
        'position_score' => 1.0,
        'sentiment_score' => 0.6,
        'sentiment_label' => 'neutral',
        'competitive_score' => 0.5,
        'ai_visibility_score' => 0.82,
        'visibility_breakdown' => ['ai_visibility_score' => 0.82],
    ]);
    $this->app->instance(LlmVisibilityTrackingService::class, $mock);

    Bus::dispatchSync(new RunLlmTrackingQueryJob($query->id, now()->toDateString()));

    $run = LlmTrackingQueryRun::query()->where('llm_tracking_query_id', $query->id)->latest('id')->first();
    expect($run)->not->toBeNull();
    expect($run->status)->toBe('succeeded');
    expect($run->brand_mentioned)->toBeTrue();
    expect($run->urls_cited)->toBeTrue();
    expect($run->competitors_mentioned)->toBeTrue();
    expect($run->is_cached)->toBeFalse();
    expect((float) $run->ai_visibility_score)->toBe(0.82);
    expect((string) $run->sentiment_label)->toBe('neutral');
});

it('uses cached run for identical same-day runs without extra quota usage', function () {
    [$workspace, $site, $query] = createLlmTrackingContext(10);

    $quotaMock = \Mockery::mock(PlanQuotaService::class)->shouldIgnoreMissing();
    $quotaMock->shouldReceive('assertCanRunLlmQuery')->once();
    $this->app->instance(PlanQuotaService::class, $quotaMock);

    $reservation = CreditReservation::query()->make([
        'status' => CreditReservation::STATUS_RESERVED,
        'client_site_id' => $site->id,
        'amount' => 1,
    ]);
    $reservationsMock = \Mockery::mock(CreditReservationService::class)->shouldIgnoreMissing();
    $reservationsMock->shouldReceive('reserve')->once()->andReturn($reservation);
    $reservationsMock->shouldReceive('capture')->once();
    $this->app->instance(CreditReservationService::class, $reservationsMock);

    $mock = \Mockery::mock(LlmVisibilityTrackingService::class);
    $mock->shouldReceive('resolveRoute')->twice()->andReturn([
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
    ]);
    $mock->shouldReceive('run')->once()->andReturn([
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'raw_response' => ['id' => 'resp_123'],
        'parsed_payload' => ['response_text' => 'Argusly via https://argusly.com/features.'],
        'answer_text' => 'Argusly via https://argusly.com/features.',
        'brand_hits' => [['term' => 'Argusly', 'count' => 1, 'bucket' => 'first']],
        'competitor_hits' => [],
        'entity_presence' => [
            ['term' => 'Argusly', 'type' => 'brand', 'present' => true, 'count' => 1, 'position_score' => 1.0],
        ],
        'url_hits' => [['target_url' => 'https://argusly.com/features', 'count' => 1, 'bucket' => 'middle']],
        'citation_ranking' => ['brand' => ['bucket' => 'first'], 'url' => ['bucket' => 'middle']],
        'sources' => [['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website']],
        'share_of_voice_snapshot' => ['share_brand' => 1.0],
        'suggestions' => [],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => false,
        'presence_score' => 1.0,
        'position_score' => 1.0,
        'sentiment_score' => 0.6,
        'sentiment_label' => 'neutral',
        'competitive_score' => 1.0,
        'ai_visibility_score' => 0.92,
        'visibility_breakdown' => ['ai_visibility_score' => 0.92],
    ]);
    $this->app->instance(LlmVisibilityTrackingService::class, $mock);

    $runDate = now()->toDateString();
    Bus::dispatchSync(new RunLlmTrackingQueryJob($query->id, $runDate));
    Bus::dispatchSync(new RunLlmTrackingQueryJob($query->id, $runDate));

    $runs = LlmTrackingQueryRun::query()
        ->where('llm_tracking_query_id', $query->id)
        ->orderBy('id')
        ->get();

    expect($runs)->toHaveCount(2);
    expect((bool) $runs[0]->is_cached)->toBeFalse();
    expect((bool) $runs[1]->is_cached)->toBeTrue();
    expect((float) $runs[1]->ai_visibility_score)->toBe(0.92);
});

it('fails the run when llm quota is exceeded', function () {
    [, , $query] = createLlmTrackingContext(0);

    $quotaMock = \Mockery::mock(PlanQuotaService::class);
    $quotaMock->shouldReceive('assertCanRunLlmQuery')->once()->andThrow(new \RuntimeException('Monthly quota exceeded'));
    $this->app->instance(PlanQuotaService::class, $quotaMock);

    $reservationsMock = \Mockery::mock(CreditReservationService::class);
    $reservationsMock->shouldReceive('reserve')->never();
    $this->app->instance(CreditReservationService::class, $reservationsMock);

    $mock = \Mockery::mock(LlmVisibilityTrackingService::class);
    $mock->shouldReceive('resolveRoute')->once()->andReturn([
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
    ]);
    $mock->shouldReceive('run')->never();
    $this->app->instance(LlmVisibilityTrackingService::class, $mock);

    Bus::dispatchSync(new RunLlmTrackingQueryJob($query->id, now()->toDateString()));

    $run = LlmTrackingQueryRun::query()->where('llm_tracking_query_id', $query->id)->latest('id')->first();
    expect($run)->not->toBeNull();
    expect($run->status)->toBe('failed');
    expect((string) $run->error_message)->toContain('Monthly quota exceeded');
});

it('dispatches due active queries with daily spreading command', function () {
    [, $site, $query] = createLlmTrackingContext(10);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now()->startOfDay(),
        'status' => 'succeeded',
    ]);

    $second = LlmTrackingQuery::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'Due query',
        'query_text' => 'Who mentions Argusly?',
        'frequency' => 'daily',
        'is_active' => true,
        'locale' => 'en',
    ]);

    $hour = abs(crc32((string) $second->id)) % 24;
    $this->travelTo(now()->startOfDay()->setHour($hour)->setMinute(7));

    Bus::fake();

    $this->artisan('llm-tracking:dispatch-daily --max-dispatch=10 --queue=default')->assertExitCode(0);

    Bus::assertDispatched(RunLlmTrackingQueryJob::class, function (RunLlmTrackingQueryJob $job) use ($second) {
        return $job->queryId === $second->id;
    });

    Bus::assertNotDispatched(RunLlmTrackingQueryJob::class, function (RunLlmTrackingQueryJob $job) use ($query) {
        return $job->queryId === $query->id;
    });
});

it('dispatches weekly cadence queries on their assigned day', function () {
    [, $site, $query] = createLlmTrackingContext(10);

    $query->update(['frequency' => 'weekly']);

    $day = abs(crc32((string) $query->id)) % 7;
    $scheduledMoment = now()->startOfWeek()->addDays($day)->setHour(10)->setMinute(7);
    $this->travelTo($scheduledMoment);

    Bus::fake();

    $this->artisan('llm-tracking:dispatch-daily --max-dispatch=10 --queue=default')->assertExitCode(0);

    Bus::assertDispatched(RunLlmTrackingQueryJob::class, function (RunLlmTrackingQueryJob $job) use ($query) {
        return $job->queryId === $query->id;
    });
});
