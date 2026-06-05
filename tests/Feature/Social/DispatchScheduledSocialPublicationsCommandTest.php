<?php

use App\Enums\SocialPublicationStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Jobs\SocialDistribution\PublishSocialPostJob;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialDistributionAuditLog;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('dispatches due scheduled social publications', function (): void {
    Bus::fake([PublishSocialPostJob::class]);

    $publication = SocialPublication::factory()->create([
        'status' => SocialPublicationStatus::SCHEDULED,
        'scheduled_for' => now()->subMinute(),
        'queued_at' => null,
    ]);

    $futurePublication = SocialPublication::factory()->create([
        'status' => SocialPublicationStatus::SCHEDULED,
        'scheduled_for' => now()->addHours(3),
        'queued_at' => null,
    ]);

    $this->artisan('social:dispatch-scheduled-publications --limit=10')
        ->expectsOutput('Processed 1 due social publication(s). Dispatched 1 publish job(s).')
        ->assertExitCode(0);

    $publication->refresh();
    $futurePublication->refresh();

    expect($publication->status)->toBe(SocialPublicationStatus::QUEUED)
        ->and($publication->queued_at)->not->toBeNull()
        ->and($futurePublication->status)->toBe(SocialPublicationStatus::SCHEDULED)
        ->and($futurePublication->queued_at)->toBeNull()
        ->and(SocialDistributionAuditLog::query()
            ->where('social_publication_id', $publication->id)
            ->where('event', 'publication.queued_by_scheduler')
            ->exists())->toBeTrue();

    Bus::assertDispatched(PublishSocialPostJob::class, fn (PublishSocialPostJob $job): bool => $job->publicationId === (string) $publication->id);
    Bus::assertNotDispatched(PublishSocialPostJob::class, fn (PublishSocialPostJob $job): bool => $job->publicationId === (string) $futurePublication->id);
});

it('requeues social publications after rate limit retry time passes', function (): void {
    Bus::fake([PublishSocialPostJob::class]);

    $publication = SocialPublication::factory()->create([
        'status' => SocialPublicationStatus::RATE_LIMITED,
        'scheduled_for' => now()->subHour(),
        'next_retry_at' => now()->subMinute(),
        'queued_at' => now()->subHour(),
    ]);

    $this->artisan('social:dispatch-scheduled-publications --limit=10')
        ->expectsOutput('Processed 1 due social publication(s). Dispatched 1 publish job(s).')
        ->assertExitCode(0);

    $publication->refresh();

    expect($publication->status)->toBe(SocialPublicationStatus::QUEUED)
        ->and($publication->next_retry_at)->toBeNull();

    Bus::assertDispatched(PublishSocialPostJob::class, fn (PublishSocialPostJob $job): bool => $job->publicationId === (string) $publication->id);
});

it('dispatches legacy social schedules that were stored as amsterdam wall clock time', function (): void {
    Bus::fake([PublishSocialPostJob::class]);
    $this->travelTo(CarbonImmutable::parse('2026-05-26 18:45:00', 'UTC'));

    $publication = SocialPublication::factory()->create([
        'status' => SocialPublicationStatus::SCHEDULED,
        'scheduled_for' => CarbonImmutable::parse('2026-05-26 20:30:00', 'UTC'),
        'queued_at' => null,
        'metadata' => [],
    ]);

    $this->artisan('social:dispatch-scheduled-publications --limit=10')
        ->expectsOutput('Processed 1 due social publication(s). Dispatched 1 publish job(s).')
        ->assertExitCode(0);

    expect($publication->refresh()->status)->toBe(SocialPublicationStatus::QUEUED);

    Bus::assertDispatched(PublishSocialPostJob::class, fn (PublishSocialPostJob $job): bool => $job->publicationId === (string) $publication->id);
});

it('stores scheduled social publication times from the browser timezone as utc', function (): void {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Social Schedule Org',
        'slug' => 'social-schedule-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Social Schedule Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Social Schedule Campaign',
        'slug' => 'social-schedule-campaign',
        'status' => 'active',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Ricardo LinkedIn',
        'status' => SocialAccountStatus::CONNECTED,
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::APPROVED,
        'variant_number' => 1,
        'hook' => 'Planning should respect local time.',
        'body' => 'A scheduled post should publish when the user expects it.',
        'approved_at' => now(),
        'approved_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.distribution.variants.schedule', [
            'variant' => $variant,
            'workspace_id' => $workspace->id,
        ]), [
            'social_account_id' => $account->id,
            'scheduled_for' => '2026-05-26T20:30',
            'timezone' => 'Europe/Amsterdam',
        ])
        ->assertRedirect();

    $publication = SocialPublication::query()->firstOrFail();

    expect($publication->scheduled_for->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-05-26 18:30:00')
        ->and($publication->metadata['scheduled_timezone'])->toBe('Europe/Amsterdam')
        ->and($publication->metadata['scheduled_local'])->toBe('2026-05-26T20:30');
});
