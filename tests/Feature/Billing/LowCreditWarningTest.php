<?php

use App\Models\ClientSite;
use App\Models\ContentAutomation;
use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteCreditAllocation;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCreditWallet;
use App\Notifications\LowCreditWarningNotification;
use App\Services\CreditWalletService;
use App\Services\Credits\CreditWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('detects low credits, sends a throttled mail warning, and creates an in-app notification', function () {
    config()->set('credits.warnings.enabled', true);
    config()->set('credits.warnings.absolute_threshold', 10);
    config()->set('credits.warnings.resend_cooldown_hours', 24);
    config()->set('credits.warnings.minimum_automation_run_credits', 10);

    [$owner, $workspace, $site] = makeLowCreditWarningContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 9,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-test'],
    );

    ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Low credit automation',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addHour(),
        'chain_size' => 5,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Automation warning topic',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Notification::fake();

    $result = app(CreditWarningService::class)->syncWorkspaceWarning($workspace);

    expect($result['is_low'])->toBeTrue()
        ->and($result['is_automation_risk'])->toBeTrue()
        ->and($result['warning_sent'])->toBeTrue();

    Notification::assertSentTo($owner, LowCreditWarningNotification::class);
    Notification::assertSentOnDemand(LowCreditWarningNotification::class);

    expect(WorkspaceNotification::query()
        ->where('workspace_id', (string) $workspace->id)
        ->where('title', __('app.credits.low_warning.title'))
        ->count())->toBe(1);
});

it('does not spam low-credit mails inside the cooldown window and resets after credits recover', function () {
    config()->set('credits.warnings.enabled', true);
    config()->set('credits.warnings.absolute_threshold', 10);
    config()->set('credits.warnings.resend_cooldown_hours', 24);

    [$owner, $workspace, $site] = makeLowCreditWarningContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 8,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-cooldown'],
    );

    Notification::fake();

    $service = app(CreditWarningService::class);
    $service->syncWorkspaceWarning($workspace);
    $service->syncWorkspaceWarning($workspace->fresh());

    Notification::assertSentToTimes($owner, LowCreditWarningNotification::class, 1);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-reset'],
    );

    $recovered = $service->syncWorkspaceWarning($workspace->fresh());

    expect($recovered['is_low'])->toBeFalse();
    expect($workspace->fresh()->low_credit_warning_state)->toBeNull()
        ->and($workspace->fresh()->low_credit_warning_sent_at)->toBeNull();
});

it('creates a fresh in-app notification after credits recover and later fall low again', function () {
    config()->set('credits.warnings.enabled', true);
    config()->set('credits.warnings.absolute_threshold', 10);

    [$owner, $workspace, $site] = makeLowCreditWarningContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 8,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-cycle-1'],
    );

    $service = app(CreditWarningService::class);
    $service->syncWorkspaceWarning($workspace);

    expect(WorkspaceNotification::query()
        ->where('workspace_id', (string) $workspace->id)
        ->where('meta->kind', 'low_credits')
        ->count())->toBe(1);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-cycle-reset'],
    );

    $service->syncWorkspaceWarning($workspace->fresh());

    $allocation = SiteCreditAllocation::query()->where('client_site_id', (string) $site->id)->firstOrFail();
    $allocation->forceFill([
        'allocated_credits' => 6,
        'reserved_cached' => 0,
    ])->save();
    WorkspaceCreditWallet::query()
        ->where('workspace_id', (string) $workspace->id)
        ->firstOrFail()
        ->forceFill([
            'balance_cached' => 6,
            'reserved_cached' => 0,
        ])->save();

    $service->syncWorkspaceWarning($workspace->fresh(), sendNotifications: false);
    $service->syncWorkspaceWarning($workspace->fresh());

    expect(WorkspaceNotification::query()
        ->where('workspace_id', (string) $workspace->id)
        ->where('meta->kind', 'low_credits')
        ->count())->toBe(2);
});

it('renders the automation-specific low-credit banner in the app when active automations exist', function () {
    config()->set('credits.warnings.enabled', true);
    config()->set('credits.warnings.absolute_threshold', 10);

    [$owner, $workspace, $site] = makeLowCreditWarningContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 9,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-banner'],
    );

    ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Banner automation',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addHour(),
        'chain_size' => 2,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Banner topic',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertSee('Credits are running low')
        ->assertSee('active automations are enabled');
});

it('supports localized low-credit email copy', function () {
    $notification = new LowCreditWarningNotification([
        'available_credits' => 9,
        'has_active_automations' => true,
        'active_automation_count' => 2,
        'next_automation_run_label' => 'in 2 hours',
        'cta_url' => 'https://example.com/billing',
    ], 'nl');

    $mail = $notification->toMail(new class
    {
    });

    expect($mail->subject)->toBe('Credits raken bijna op')
        ->and($mail->viewData['intro'] ?? null)->toBe('Credits raken bijna op')
        ->and($mail->viewData['body'] ?? null)->toContain('actieve automations ingesteld')
        ->and($mail->viewData['automationHint'] ?? null)->toContain('Volgende geplande run: in 2 hours');
});

function makeLowCreditWarningContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Low Credit Org',
        'slug' => 'low-credit-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Low Credit BV',
        'billing_address_line1' => 'Waarschuwingsstraat 1',
        'billing_country_code' => 'NL',
        'billing_email' => 'billing+' . Str::lower(Str::random(4)) . '@example.com',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Low Credit Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Low Credit Site',
        'site_url' => 'https://low-credit.example.com',
        'base_url' => 'https://low-credit.example.com',
        'allowed_domains' => ['low-credit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'low-credit-warning-plan'],
        [
            'name' => 'Low Credit Warning Plan',
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
        'name' => 'Low Credit Owner',
        'email' => 'low-credit-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $organization->forceFill(['primary_user_id' => $owner->id])->save();

    return [$owner, $workspace, $site];
}
