<?php

use App\Mail\ContactSubmissionReceived;
use App\Mail\EarlyAccessInvitationMail;
use App\Mail\OnboardingEmail;
use App\Models\ContactSubmission;
use App\Models\EarlyAccessInvite;
use App\Models\EarlyAccessSignup;
use App\Models\OnboardingState;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\OrganizationApprovalRequested;
use App\Notifications\UserApprovalRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders onboarding email in html and text with system footer', function () {
    [$state] = makeOnboardingMailContext();

    $mail = new OnboardingEmail($state, 'verify_email');

    $mail->assertHasSubject('Confirm your email address');
    $mail->assertSeeInHtml('This is a system email from Argusly.');
    $mail->assertSeeInText('This is a system email from Argusly.');
    $mail->assertDontSeeInHtml('<img', escape: false);
});

it('renders contact submission email in html and text with system footer', function () {
    $submission = ContactSubmission::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'company' => 'Acme',
        'subject' => 'Enterprise pricing',
        'message' => 'Please contact us.',
        'topic' => 'Enterprise',
        'source_page' => 'landing.cta',
        'cta_label' => 'Request pricing',
        'url' => 'https://example.com/contact',
        'ip_address' => '127.0.0.1',
    ]);

    $mail = new ContactSubmissionReceived($submission);

    $mail->assertHasSubject('Argusly contact: Enterprise pricing');
    $mail->assertSeeInHtml('This is a system email from Argusly.');
    $mail->assertSeeInText('This is a system email from Argusly.');
    $mail->assertDontSeeInHtml('<img', escape: false);
});

it('renders early access invitation email in html and text with system footer', function () {
    $signup = EarlyAccessSignup::query()->create([
        'full_name' => 'Invite Candidate',
        'email' => 'invite@example.com',
        'company_name' => 'Invite Co',
        'status' => \App\Enums\EarlyAccessSignupStatus::INVITED,
        'submitted_at' => now(),
        'approved_at' => now(),
        'invited_at' => now(),
    ]);

    $token = 'mail-early-access-token-' . Str::random(12);

    $invite = EarlyAccessInvite::query()->create([
        'early_access_signup_id' => $signup->id,
        'email' => $signup->email,
        'token_hash' => hash('sha256', $token),
        'token_encrypted' => Crypt::encryptString($token),
        'expires_at' => now()->addDays(14),
    ]);

    $mail = new EarlyAccessInvitationMail($invite);

    $mail->assertHasSubject('Your Argusly Pilot Program invite');
    $mail->assertSeeInHtml('This is a system email from Argusly.');
    $mail->assertSeeInText('This is a system email from Argusly.');
    $mail->assertDontSeeInHtml('<img', escape: false);
});

it('renders organization approval notification with html and text views', function () {
    [$organization, $requester, $admin] = makeNotificationContext();

    $notification = new OrganizationApprovalRequested($organization, $requester);
    $message = $notification->toMail($admin);

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and($message->subject)->toBe('Organization approval requested')
        ->and($message->view)->toBeArray()
        ->and($message->view['html'] ?? null)->toBe('emails.notifications.organization-approval-requested')
        ->and($message->view['text'] ?? null)->toBe('emails.notifications.organization-approval-requested-text');

    $html = view((string) $message->view['html'], $message->data())->render();
    $text = view((string) $message->view['text'], $message->data())->render();

    expect($html)->toContain('This is a system email from Argusly.')
        ->and($text)->toContain('This is a system email from Argusly.')
        ->and($html)->not->toContain('<img');
});

it('renders user approval notification with html and text views', function () {
    [$organization, $requester, $admin] = makeNotificationContext();

    $notification = new UserApprovalRequested($requester);
    $message = $notification->toMail($admin);

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and($message->subject)->toBe('User approval requested')
        ->and($message->view)->toBeArray()
        ->and($message->view['html'] ?? null)->toBe('emails.notifications.user-approval-requested')
        ->and($message->view['text'] ?? null)->toBe('emails.notifications.user-approval-requested-text');

    $html = view((string) $message->view['html'], $message->data())->render();
    $text = view((string) $message->view['text'], $message->data())->render();

    expect($html)->toContain('This is a system email from Argusly.')
        ->and($text)->toContain('This is a system email from Argusly.')
        ->and($html)->not->toContain('<img');
});

/**
 * @return array{0:OnboardingState,1:User}
 */
function makeOnboardingMailContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Mail Org',
        'slug' => 'mail-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Mail Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::query()->create([
        'name' => 'Mail User',
        'email' => 'mail-user-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $state = OnboardingState::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'phase' => 'registered',
        'registered_at' => now(),
        'emails_sent_json' => [],
        'completed_steps_json' => [],
    ]);

    return [$state, $user];
}

/**
 * @return array{0:Organization,1:User,2:User}
 */
function makeNotificationContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Notify Org',
        'slug' => 'notify-org-' . Str::random(6),
        'status' => 'pending',
    ]);

    $requester = User::query()->create([
        'name' => 'Requester',
        'email' => 'requester-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
    ]);

    $admin = User::query()->create([
        'name' => 'Admin',
        'email' => 'admin-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$organization, $requester, $admin];
}
