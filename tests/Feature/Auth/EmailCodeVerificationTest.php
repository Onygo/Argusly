<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\OrganizationApprovalRequested;
use App\Notifications\VerifyEmailCodeNotification;
use App\Services\Auth\EmailCodeVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('registration sends verification code email and stores hashed challenge', function () {
    Notification::fake();
    createStarterPlan();

    $response = $this->post('/register', [
        'name' => 'Code User',
        'email' => 'code-user@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Code Co',
        'plan' => 'starter',
    ]);

    $response->assertRedirect(route('verify-code.show'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'code-user@example.com')->firstOrFail();
    expect(trim((string) $user->email_code_hash))->not->toBe('')
        ->and($user->email_code_expires_at)->not->toBeNull()
        ->and($user->email_code_verified_at)->toBeNull()
        ->and($user->email_code_sent_at)->not->toBeNull()
        ->and((int) $user->email_code_attempts)->toBe(0);

    Notification::assertSentTo($user, VerifyEmailCodeNotification::class);
});

it('sends organization approval request only after email verification', function () {
    Notification::fake();
    createStarterPlan();

    $admin = User::query()->create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret1234'),
        'is_admin' => true,
        'approved_at' => now(),
    ]);

    $this->post('/register', [
        'name' => 'Pending Owner',
        'email' => 'pending-owner@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Pending Approval Co',
        'plan' => 'starter',
    ])->assertRedirect(route('verify-code.show'));

    Notification::assertNotSentTo($admin, OrganizationApprovalRequested::class);

    $user = User::query()->where('email', 'pending-owner@example.com')->firstOrFail();
    $code = null;
    Notification::assertSentTo($user, VerifyEmailCodeNotification::class, function (VerifyEmailCodeNotification $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $this->post('/verify-code', ['code' => $code])
        ->assertRedirect(route('app.billing.index'));

    Notification::assertSentTo($admin, OrganizationApprovalRequested::class);
});

it('redirects authenticated users with pending code verification away from app routes', function () {
    $user = createApprovedActiveUser();
    app(EmailCodeVerificationService::class)->issueCode($user);

    $this->actingAs($user)
        ->get('/app/dashboard')
        ->assertRedirect('/verify-code');
});

it('verifies correct code and clears challenge fields', function () {
    $user = createApprovedActiveUser();

    $service = app(EmailCodeVerificationService::class);
    $code = $service->issueCode($user);

    $this->actingAs($user)
        ->post('/verify-code', ['code' => $code])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHas('status', 'Email verification completed.');

    $user->refresh();
    expect($user->email_code_verified_at)->not->toBeNull()
        ->and($user->email_code_hash)->toBeNull()
        ->and($user->email_code_expires_at)->toBeNull()
        ->and((int) $user->email_code_attempts)->toBe(0)
        ->and($user->email_verified_at)->not->toBeNull();
});

it('increments attempts on wrong code and rate limits after threshold', function () {
    $user = createApprovedActiveUser();
    app(EmailCodeVerificationService::class)->issueCode($user);

    $this->actingAs($user);

    for ($i = 1; $i <= 5; $i++) {
        $this->post('/verify-code', ['code' => '000000'])
            ->assertSessionHasErrors(['code']);
    }

    $this->post('/verify-code', ['code' => '000000'])
        ->assertSessionHasErrors(['code']);

    $user->refresh();
    expect((int) $user->email_code_attempts)->toBe(5)
        ->and((string) session('errors')?->first('code'))->toContain('Too many attempts');
});

it('rejects expired verification code', function () {
    $user = createApprovedActiveUser();
    $service = app(EmailCodeVerificationService::class);
    $code = $service->issueCode($user);

    $user->forceFill([
        'email_code_expires_at' => now()->subMinute(),
    ])->save();

    $this->actingAs($user)
        ->post('/verify-code', ['code' => $code])
        ->assertSessionHasErrors(['code']);

    expect((string) session('errors')?->first('code'))->toContain('expired');
});

it('resend generates a new code and invalidates the previous one', function () {
    Notification::fake();

    $user = createApprovedActiveUser();
    $service = app(EmailCodeVerificationService::class);
    $oldCode = $service->issueCode($user);

    $user->forceFill([
        'email_code_sent_at' => now()->subMinutes(2),
    ])->save();

    $this->actingAs($user)
        ->post('/verify-code/resend')
        ->assertSessionHas('status', 'A new verification code has been sent.');

    $newCode = null;
    Notification::assertSentTo($user, VerifyEmailCodeNotification::class, function (VerifyEmailCodeNotification $notification) use (&$newCode): bool {
        $newCode = $notification->code;

        return true;
    });

    expect(is_string($newCode))->toBeTrue()
        ->and($newCode)->not->toBe($oldCode);

    $user->refresh();
    expect(Hash::check($oldCode, (string) $user->email_code_hash))->toBeFalse()
        ->and(Hash::check((string) $newCode, (string) $user->email_code_hash))->toBeTrue();
});

it('rate limits resend requests within cooldown window', function () {
    $user = createApprovedActiveUser();
    app(EmailCodeVerificationService::class)->issueCode($user);

    $this->actingAs($user)
        ->post('/verify-code/resend')
        ->assertSessionHasErrors(['code']);

    expect((string) session('errors')?->first('code'))->toContain('Please wait');
});

it('keeps admin approval gate after email code verification', function () {
    $organization = Organization::query()->create([
        'name' => 'Pending Org',
        'slug' => 'pending-org-' . Str::random(6),
        'status' => 'pending',
    ]);

    $user = User::query()->create([
        'name' => 'Pending User',
        'email' => 'pending-user@example.com',
        'password' => Hash::make('secret1234'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $service = app(EmailCodeVerificationService::class);
    $code = $service->issueCode($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'secret1234',
    ])->assertRedirect(route('verify-code.show'));

    $this->post('/verify-code', ['code' => $code])
        ->assertRedirect(route('app.billing.index'));

    $this->post('/logout');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'secret1234',
    ])->assertRedirect(route('pending'));
});

function createStarterPlan(): Plan
{
    return Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'creator',
        'slug' => 'creator',
        'name' => 'Creator',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 2,
        'is_active' => true,
    ]);
}

function createApprovedActiveUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Verified Flow Org',
        'slug' => 'verified-flow-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Verified Flow User',
        'email' => 'verified-flow-' . Str::random(6) . '@example.com',
        'password' => Hash::make('secret1234'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
}
