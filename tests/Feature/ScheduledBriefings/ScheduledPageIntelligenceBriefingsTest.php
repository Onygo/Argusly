<?php

use App\Contracts\PageIntelligence\ScheduledBriefingContract;
use App\Http\Middleware\DenyWriteActionsInSupportMode;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Http\Middleware\EnsureEmailCodeVerified;
use App\Http\Middleware\EnsureSupportModeContext;
use App\Http\Middleware\EnsureUserApproved;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Http\Middleware\ProtectHeavyEndpoints;
use App\Http\Middleware\SetAppLocale;
use App\Jobs\PageIntelligence\GeneratePageIntelligenceReportArtifactJob;
use App\Jobs\PageIntelligence\GenerateScheduledPageIntelligenceBriefingJob;
use App\Models\MarketPack;
use App\Models\MarketPackInstallation;
use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\PageIntelligenceReport;
use App\Models\PageIntelligenceReportDelivery;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

it('registers the scheduled briefing runner in the Laravel scheduler', function (): void {
    $event = collect(Schedule::events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'page-intelligence:run-scheduled-briefings --limit=50'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('allows a schedule to be created and toggled from the UI', function (): void {
    [$workspace, $user] = scheduledBriefingWorkspace();

    $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.scheduled-briefings.index', ['workspace' => $workspace->id]))
        ->assertOk()
        ->assertSee('Scheduled Briefings')
        ->assertSee('Create schedule');

    $response = $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.scheduled-briefings.store'), [
            'workspace' => $workspace->id,
            'report_type' => ReportBuilder::TYPE_WEEKLY,
            'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
            'day_of_week' => 1,
            'timezone' => 'Europe/Amsterdam',
            'recipients' => "ops@example.com\nlead@example.com",
        'delivery_channels' => ['email'],
        'is_active' => '1',
    ]);

    $response->assertSessionHasNoErrors();
    $briefing = ScheduledPageIntelligenceBriefing::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($briefing->report_type)->toBe(ReportBuilder::TYPE_WEEKLY)
        ->and($briefing->frequency)->toBe(ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY)
        ->and($briefing->timezone)->toBe('Europe/Amsterdam')
        ->and($briefing->recipients_json)->toBe(['ops@example.com', 'lead@example.com'])
        ->and($briefing->delivery_channels_json)->toBe(['email_placeholder'])
        ->and($briefing->next_run_at)->not->toBeNull();

    $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.scheduled-briefings.edit', $briefing))
        ->assertOk()
        ->assertSee('Save schedule')
        ->assertSee('Delivery History');

    $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.scheduled-briefings.deactivate', $briefing))
        ->assertRedirect();

    expect($briefing->refresh()->is_active)->toBeFalse();

    $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.scheduled-briefings.activate', $briefing))
        ->assertRedirect();

    expect($briefing->refresh()->is_active)->toBeTrue();
});

it('dispatches a job for a due active schedule', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GenerateScheduledPageIntelligenceBriefingJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, ['next_run_at' => now()->subMinute()]);

    $this->artisan('page-intelligence:run-scheduled-briefings')
        ->assertSuccessful();

    Bus::assertDispatched(GenerateScheduledPageIntelligenceBriefingJob::class, function (GenerateScheduledPageIntelligenceBriefingJob $job) use ($briefing): bool {
        $briefing->refresh();

        return $job->scheduledBriefingId === (string) $briefing->id
            && $job->schedulerClaimToken === $briefing->scheduler_claim_token;
    });
    Bus::assertDispatchedTimes(GenerateScheduledPageIntelligenceBriefingJob::class, 1);
    expect($briefing->refresh()->scheduler_claim_token)->not->toBeEmpty()
        ->and($briefing->scheduler_claim_expires_at)->not->toBeNull();

    Carbon::setTestNow();
});

it('does not enqueue duplicate scheduled generation across repeated scheduler ticks', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GenerateScheduledPageIntelligenceBriefingJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    scheduledBriefing($workspace, $user, ['next_run_at' => now()->subMinute()]);

    $this->artisan('page-intelligence:run-scheduled-briefings')->assertSuccessful();
    $this->artisan('page-intelligence:run-scheduled-briefings')->assertSuccessful();

    Bus::assertDispatchedTimes(GenerateScheduledPageIntelligenceBriefingJob::class, 1);

    Carbon::setTestNow();
});

it('does not save schedules for market packs that are not installed', function (): void {
    [$workspace, $user] = scheduledBriefingWorkspace();

    $response = $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.scheduled-briefings.store'), [
            'workspace' => $workspace->id,
            'report_type' => ReportBuilder::TYPE_WEEKLY,
            'market_pack_key' => 'unknown-pack',
            'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
            'day_of_week' => 1,
            'timezone' => 'UTC',
            'is_active' => '1',
        ]);

    $response->assertSessionHasErrors('market_pack_key');

    expect(ScheduledPageIntelligenceBriefing::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('allows schedules for installed market packs', function (): void {
    [$workspace, $user] = scheduledBriefingWorkspace();
    $pack = MarketPack::query()->create([
        'key' => 'automotive',
        'name' => 'Automotive',
        'description' => 'Automotive market pack',
        'market_category' => 'automotive',
        'status' => MarketPack::STATUS_ACTIVE,
        'version' => '1.0',
        'locale' => 'en',
        'defaults_json' => [],
        'metadata_json' => [],
    ]);
    MarketPackInstallation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'market_pack_id' => $pack->id,
        'status' => MarketPackInstallation::STATUS_ACTIVE,
        'installed_at' => now(),
    ]);

    $response = $this->withoutMiddleware(scheduledBriefingDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.scheduled-briefings.store'), [
            'workspace' => $workspace->id,
            'report_type' => ReportBuilder::TYPE_WEEKLY,
            'market_pack_key' => 'automotive',
            'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
            'day_of_week' => 1,
            'timezone' => 'UTC',
            'is_active' => '1',
        ]);

    $response->assertSessionHasNoErrors();

    expect(ScheduledPageIntelligenceBriefing::query()->where('workspace_id', $workspace->id)->value('market_pack_key'))->toBe('automotive');
});

it('skips inactive schedules', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GenerateScheduledPageIntelligenceBriefingJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    scheduledBriefing($workspace, $user, [
        'is_active' => false,
        'next_run_at' => now()->subMinute(),
    ]);

    $this->artisan('page-intelligence:run-scheduled-briefings')
        ->assertSuccessful();

    Bus::assertNotDispatched(GenerateScheduledPageIntelligenceBriefingJob::class);

    Carbon::setTestNow();
});

it('respects schedule timezone boundaries', function (): void {
    [$workspace, $user] = scheduledBriefingWorkspace();
    $localRun = Carbon::parse('2026-07-07 00:00:00', 'Europe/Amsterdam');
    $briefing = scheduledBriefing($workspace, $user, [
        'timezone' => 'Europe/Amsterdam',
        'day_of_week' => 2,
        'next_run_at' => $localRun->copy()->timezone('UTC'),
    ]);

    Bus::fake([GenerateScheduledPageIntelligenceBriefingJob::class]);
    Carbon::setTestNow($localRun->copy()->subMinute()->timezone('UTC'));
    $this->artisan('page-intelligence:run-scheduled-briefings')->assertSuccessful();
    Bus::assertNotDispatched(GenerateScheduledPageIntelligenceBriefingJob::class);

    Bus::fake([GenerateScheduledPageIntelligenceBriefingJob::class]);
    Carbon::setTestNow($localRun->copy()->addMinute()->timezone('UTC'));
    $this->artisan('page-intelligence:run-scheduled-briefings')->assertSuccessful();
    Bus::assertDispatched(GenerateScheduledPageIntelligenceBriefingJob::class, function (GenerateScheduledPageIntelligenceBriefingJob $job) use ($briefing): bool {
        $briefing->refresh();

        return $job->scheduledBriefingId === (string) $briefing->id
            && $job->schedulerClaimToken === $briefing->scheduler_claim_token;
    });

    Carbon::setTestNow();
});

it('generates a report snapshot through the scheduled briefing contract', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'valid-claim',
    ]);

    expect(app(ScheduledBriefingContract::class))->toBeInstanceOf(ReportBuilder::class);

    (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'valid-claim'))->handle(app(ScheduledBriefingContract::class));

    $report = PageIntelligenceReport::query()->firstOrFail();

    expect($report->scheduled_page_intelligence_briefing_id)->toBe($briefing->id)
        ->and($report->report_type)->toBe(ReportBuilder::TYPE_WEEKLY)
        ->and($report->status)->toBe(PageIntelligenceReport::STATUS_GENERATED)
        ->and(data_get($report->payload_json, 'scheduled_briefing.delivery_enabled'))->toBeTrue()
        ->and($briefing->refresh()->last_generated_at)->not->toBeNull()
        ->and($briefing->next_run_at->isFuture())->toBeTrue();
    Bus::assertDispatched(GeneratePageIntelligenceReportArtifactJob::class, fn (GeneratePageIntelligenceReportArtifactJob $job): bool => $job->pageIntelligenceReportId === (string) $report->id);

    Carbon::setTestNow();
});

it('does not create a duplicate report for a duplicate queued run', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'duplicate-claim',
    ]);
    $job = new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'duplicate-claim');

    $job->handle(app(ScheduledBriefingContract::class));
    $job->handle(app(ScheduledBriefingContract::class));

    expect(PageIntelligenceReport::query()->count())->toBe(1);

    Carbon::setTestNow();
});

it('creates delivery records and an in-app notification for scheduled snapshots', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    Mail::fake();
    Notification::fake();
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'recipients_json' => [$user->email, 'customer@example.com'],
        'delivery_channels_json' => ['in_app', 'email_placeholder'],
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'email-free-claim',
    ]);

    (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'email-free-claim'))->handle(app(ScheduledBriefingContract::class));

    expect(PageIntelligenceReport::query()->count())->toBe(1);
    $report = PageIntelligenceReport::query()->firstOrFail();

    expect(PageIntelligenceReportDelivery::query()->where('report_id', $report->id)->count())->toBe(3)
        ->and(PageIntelligenceReportDelivery::query()
            ->where('report_id', $report->id)
            ->where('recipient_user_id', $user->id)
            ->where('channel', PageIntelligenceReportDelivery::CHANNEL_IN_APP)
            ->value('status'))->toBe(PageIntelligenceReportDelivery::STATUS_DELIVERED)
        ->and(PageIntelligenceReportDelivery::query()
            ->where('report_id', $report->id)
            ->where('recipient_email', 'customer@example.com')
            ->where('channel', PageIntelligenceReportDelivery::CHANNEL_EMAIL_PLACEHOLDER)
            ->value('status'))->toBe(PageIntelligenceReportDelivery::STATUS_SKIPPED)
        ->and(WorkspaceNotification::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('meta->page_intelligence_report_id', $report->id)
            ->count())->toBe(1)
        ->and(data_get($briefing->refresh()->delivery_state_json, 'delivered'))->toBe(1)
        ->and(data_get($briefing->delivery_state_json, 'skipped'))->toBe(2);

    Mail::assertNothingSent();
    Notification::assertNothingSent();

    Carbon::setTestNow();
});

it('does not duplicate delivery records or in-app notifications on retry', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'recipients_json' => [$user->email],
        'delivery_channels_json' => ['in_app'],
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'delivery-retry-claim',
    ]);
    $job = new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'delivery-retry-claim');

    $job->handle(app(ScheduledBriefingContract::class));
    $job->handle(app(ScheduledBriefingContract::class));

    $report = PageIntelligenceReport::query()->firstOrFail();

    expect(PageIntelligenceReport::query()->count())->toBe(1)
        ->and(PageIntelligenceReportDelivery::query()->where('report_id', $report->id)->count())->toBe(1)
        ->and(WorkspaceNotification::query()->where('meta->page_intelligence_report_id', $report->id)->count())->toBe(1);

    Carbon::setTestNow();
});

it('keeps in-app delivery tenant safe', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    [, $otherUser] = scheduledBriefingWorkspace('Other Scheduled Briefing Workspace');
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'recipients_json' => [$user->email, $otherUser->email],
        'delivery_channels_json' => ['in_app'],
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'tenant-safe-claim',
    ]);

    (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'tenant-safe-claim'))->handle(app(ScheduledBriefingContract::class));

    $report = PageIntelligenceReport::query()->firstOrFail();

    expect(PageIntelligenceReportDelivery::query()->where('report_id', $report->id)->count())->toBe(1)
        ->and(PageIntelligenceReportDelivery::query()->where('report_id', $report->id)->value('recipient_user_id'))->toBe($user->id)
        ->and(WorkspaceNotification::query()->where('user_id', $otherUser->id)->count())->toBe(0);

    Carbon::setTestNow();
});

it('records failure metadata when scheduled generation fails', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'test-claim',
    ]);

    $failingContract = new class implements ScheduledBriefingContract
    {
        public function prepare(Workspace $workspace, string $reportType, array $options = [], ?User $user = null): PageIntelligenceReport
        {
            throw new RuntimeException('Scheduled report generation failed for testing.');
        }
    };

    expect(fn () => (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'test-claim'))->handle($failingContract))
        ->toThrow(RuntimeException::class, 'Scheduled report generation failed');

    $briefing->refresh();

    expect($briefing->last_failed_at)->not->toBeNull()
        ->and($briefing->last_error)->toContain('Scheduled report generation failed')
        ->and($briefing->failure_count)->toBe(1)
        ->and($briefing->scheduler_claim_token)->toBeNull()
        ->and(data_get($briefing->delivery_state_json, 'status'))->toBe('failed');

    Carbon::setTestNow();
});

it('does not generate from a stale delayed scheduled job', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->addHour(),
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'stale-claim',
    ]);

    (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'stale-claim'))->handle(app(ScheduledBriefingContract::class));

    expect(PageIntelligenceReport::query()->count())->toBe(0)
        ->and($briefing->refresh()->scheduler_claim_token)->toBeNull();

    Bus::assertNotDispatched(GeneratePageIntelligenceReportArtifactJob::class);
    Carbon::setTestNow();
});

it('does not generate when the scheduled claim lease is expired', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'scheduler_claimed_at' => now()->subMinutes(20),
        'scheduler_claim_expires_at' => now()->subMinute(),
        'scheduler_claim_token' => 'expired-claim',
    ]);

    (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'expired-claim'))->handle(app(ScheduledBriefingContract::class));

    expect(PageIntelligenceReport::query()->count())->toBe(0)
        ->and($briefing->refresh()->scheduler_claim_token)->toBe('expired-claim');

    Bus::assertNotDispatched(GeneratePageIntelligenceReportArtifactJob::class);
    Carbon::setTestNow();
});

it('does not clear another worker claim when a scheduled job token is mismatched', function (): void {
    Carbon::setTestNow('2026-07-06 12:00:00');
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = scheduledBriefingWorkspace();
    $briefing = scheduledBriefing($workspace, $user, [
        'next_run_at' => now()->subMinute(),
        'scheduler_claimed_at' => now(),
        'scheduler_claim_expires_at' => now()->addMinutes(10),
        'scheduler_claim_token' => 'current-worker-claim',
    ]);

    (new GenerateScheduledPageIntelligenceBriefingJob((string) $briefing->id, 'old-worker-claim'))->handle(app(ScheduledBriefingContract::class));

    expect(PageIntelligenceReport::query()->count())->toBe(0)
        ->and($briefing->refresh()->scheduler_claim_token)->toBe('current-worker-claim')
        ->and($briefing->scheduler_claim_expires_at?->isFuture())->toBeTrue();

    Bus::assertNotDispatched(GeneratePageIntelligenceReportArtifactJob::class);
    Carbon::setTestNow();
});

/**
 * @return array{0:Workspace,1:User}
 */
function scheduledBriefingWorkspace(string $name = 'Scheduled Briefing Workspace'): array
{
    $organization = Organization::query()->create([
        'name' => $name.' Organization',
        'slug' => str($name)->slug().'-'.str()->random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => $name,
        'display_name' => $name,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $user];
}

/**
 * @param array<string,mixed> $overrides
 */
function scheduledBriefing(Workspace $workspace, User $user, array $overrides = []): ScheduledPageIntelligenceBriefing
{
    return ScheduledPageIntelligenceBriefing::query()->create($overrides + [
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'market_pack_key' => null,
        'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
        'day_of_week' => 1,
        'day_of_month' => null,
        'timezone' => 'UTC',
        'recipients_json' => [],
        'delivery_channels_json' => [],
        'delivery_state_json' => ['status' => 'not_delivered', 'delivery_enabled' => false, 'email_sent' => false],
        'is_active' => true,
        'last_generated_at' => null,
        'last_failed_at' => null,
        'last_error' => null,
        'failure_count' => 0,
        'next_run_at' => now()->subMinute(),
        'scheduler_claimed_at' => null,
        'scheduler_claim_expires_at' => null,
        'scheduler_claim_token' => null,
        'created_by' => $user->id,
    ]);
}

function scheduledBriefingDisabledMiddleware(): array
{
    return [
        SetAppLocale::class,
        EnsureSupportModeContext::class,
        DenyWriteActionsInSupportMode::class,
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
        ProtectHeavyEndpoints::class,
    ];
}
