<?php

use App\Mail\BillingWebhookGapsAlert;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports missing webhook and activation gaps for paid subscription intent', function () {
    Mail::fake();

    $organization = Organization::create([
        'name' => 'Diag Org',
        'slug' => 'diag-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Diag Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Diag Site',
        'site_url' => 'https://diag.example.com',
        'allowed_domains' => ['diag.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'diag-growth',
        'slug' => 'growth',
        'name' => 'Growth',
        'interval' => 'month',
        'monthly_price_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits' => 300,
        'included_credits_per_interval' => 300,
        'seat_limit' => 5,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 5,
        'status' => 'pending_mandate',
    ]);

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 7900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_diag_missing_001',
        'paid_at' => now(),
        'meta' => ['purpose' => 'subscription_initial'],
    ]);

    $this->artisan('billing:diagnose-mollie-webhook-gaps', [
        '--provider_payment_id' => 'tr_diag_missing_001',
        '--notify-email' => 'dev@publishlayer.com',
        '--alert-cooldown-minutes' => 0,
        '--fail-on-issues' => true,
    ])
        ->expectsOutputToContain('missing_webhook_event')
        ->assertExitCode(1);

    Mail::assertSent(BillingWebhookGapsAlert::class, function (BillingWebhookGapsAlert $mail): bool {
        return $mail->hasTo('dev@publishlayer.com');
    });
});

it('passes when webhook exists and allowance is granted', function () {
    Mail::fake();

    $organization = Organization::create([
        'name' => 'Diag Healthy Org',
        'slug' => 'diag-healthy-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Diag Healthy Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Diag Healthy Site',
        'site_url' => 'https://diag-healthy.example.com',
        'allowed_domains' => ['diag-healthy.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'diag-healthy-growth',
        'slug' => 'growth',
        'name' => 'Growth',
        'interval' => 'month',
        'monthly_price_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits' => 300,
        'included_credits_per_interval' => 300,
        'seat_limit' => 5,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 7900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_diag_ok_001',
        'paid_at' => now(),
        'meta' => ['purpose' => 'subscription_initial'],
    ]);

    WebhookEvent::create([
        'id' => (string) Str::uuid(),
        'provider' => 'mollie',
        'provider_event_id' => 'tr_diag_ok_001',
        'event_type' => 'payment.updated',
        'payload' => ['id' => 'tr_diag_ok_001'],
        'received_at' => now(),
        'handled_at' => now(),
        'handler_result' => ['ok' => true],
    ]);

    CreditLedgerEntry::create([
        'id' => (string) Str::uuid(),
        'credit_wallet_id' => CreditWallet::create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $site->id,
            'workspace_id' => $workspace->id,
            'balance_cached' => 300,
            'reserved_cached' => 0,
        ])->id,
        'type' => 'allowance',
        'source' => 'included_plan',
        'amount' => 300,
        'remaining' => 300,
        'source_type' => Subscription::class,
        'source_id' => $subscription->id,
        'client_site_id' => $site->id,
        'organization_id' => $organization->id,
        'idempotency_key' => 'allowance:test:' . $subscription->id,
    ]);

    $this->artisan('billing:diagnose-mollie-webhook-gaps', [
        '--provider_payment_id' => 'tr_diag_ok_001',
        '--notify-email' => 'dev@publishlayer.com',
        '--alert-cooldown-minutes' => 0,
        '--fail-on-issues' => true,
    ])
        ->expectsOutputToContain('No webhook/activation gaps detected.')
        ->assertExitCode(0);

    Mail::assertNothingSent();
});
