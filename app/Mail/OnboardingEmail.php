<?php

namespace App\Mail;

use App\Models\OnboardingState;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OnboardingEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly OnboardingState $state,
        public readonly string $emailKey
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), 'PublishLayer'),
            subject: $this->subjectFor($this->emailKey),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding',
            text: 'emails.onboarding-text',
            with: $this->payloadFor($this->emailKey),
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadFor(string $key): array
    {
        $loginUrl = route('login');
        $wizardUrl = route('app.onboarding.show');
        $billingUrl = route('app.billing.index');

        return match ($key) {
            'verify_email' => [
                'preheader' => 'Confirm your email address to continue setup.',
                'headline' => 'Confirm your email address',
                'intro' => 'Please confirm your email address to continue setup.',
                'body' => 'After confirmation, you can complete onboarding and start your first workflow.',
                'cta_label' => 'Confirm email',
                'cta_url' => $loginUrl,
            ],
            'verify_reminder_1' => [
                'preheader' => 'Email confirmation is still pending.',
                'headline' => 'Confirm your email address',
                'intro' => 'Email confirmation is still pending.',
                'body' => 'Confirm your email address to continue setup and connect your first site.',
                'cta_label' => 'Continue setup',
                'cta_url' => $loginUrl,
            ],
            'verify_reminder_2' => [
                'preheader' => 'Final reminder to confirm your email.',
                'headline' => 'Confirm your email address',
                'intro' => 'This is a final reminder to confirm your email.',
                'body' => 'Confirm your email address to unlock your workspace.',
                'cta_label' => 'Confirm email',
                'cta_url' => $loginUrl,
            ],
            'nudge_login' => [
                'preheader' => 'Your workspace is ready.',
                'headline' => 'Your workspace is ready',
                'intro' => 'Your workspace is ready for first use.',
                'body' => 'Log in and complete onboarding to connect your first site.',
                'cta_label' => 'Log in to PublishLayer',
                'cta_url' => $loginUrl,
            ],
            'nudge_no_action' => [
                'preheader' => 'Continue onboarding in your workspace.',
                'headline' => 'Your workspace is ready',
                'intro' => 'You logged in successfully.',
                'body' => 'Continue onboarding and start your first brief or draft.',
                'cta_label' => 'Continue onboarding',
                'cta_url' => $wizardUrl,
            ],
            'first_value_ready' => [
                'preheader' => 'Your draft is ready.',
                'headline' => 'Your draft is ready',
                'intro' => 'A draft is now available in your workspace.',
                'body' => 'Review the draft and continue your publishing workflow.',
                'cta_label' => 'Open dashboard',
                'cta_url' => route('app.dashboard'),
            ],
            'trial_ending' => [
                'preheader' => 'Your trial period is ending soon.',
                'headline' => 'Trial ending soon',
                'intro' => 'Your trial period is ending soon.',
                'body' => 'Review billing to keep your workspace active.',
                'cta_label' => 'Review billing',
                'cta_url' => $billingUrl,
            ],
            'reengage' => [
                'preheader' => 'Your workspace has been inactive.',
                'headline' => 'Your workspace is ready',
                'intro' => 'Your workspace has been inactive.',
                'body' => 'Return to your dashboard to continue operations.',
                'cta_label' => 'Return to PublishLayer',
                'cta_url' => route('app.dashboard'),
            ],
            default => [
                'preheader' => 'Welcome to PublishLayer.',
                'headline' => 'Welcome to PublishLayer',
                'intro' => 'Your account is ready.',
                'body' => 'Complete onboarding and connect your first site.',
                'cta_label' => 'Get started',
                'cta_url' => $wizardUrl,
            ],
        };
    }

    private function subjectFor(string $key): string
    {
        return match ($key) {
            'verify_email' => 'Confirm your email address',
            'verify_reminder_1' => 'Confirm your email address',
            'verify_reminder_2' => 'Confirm your email address',
            'nudge_login' => 'Your workspace is ready',
            'nudge_no_action' => 'Your workspace is ready',
            'first_value_ready' => 'Your draft is ready',
            'trial_ending' => 'Trial ending soon',
            'reengage' => 'Your workspace is ready',
            default => 'Welcome to PublishLayer',
        };
    }
}
