<?php

namespace App\Mail;

use App\Models\EarlyAccessInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EarlyAccessInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly EarlyAccessInvite $invite)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Argusly Pilot Program invite'
        );
    }

    public function content(): Content
    {
        $signup = $this->invite->signup;

        return new Content(
            view: 'emails.early-access-invitation',
            text: 'emails.early-access-invitation-text',
            with: [
                'subjectLine' => 'Your Argusly Pilot Program invite',
                'preheader' => 'Activate your Argusly Pilot Program account.',
                'headline' => 'Activate your Pilot Program account',
                'intro' => sprintf('Your Argusly pilot application for %s has been approved.', (string) ($signup?->company_name ?: $signup?->full_name ?: 'your team')),
                'body' => 'Use the activation link below to set your password and access your workspace.',
                'cta_label' => 'Activate account',
                'cta_url' => route('public.early-access.invites.show', $this->invite->token),
                'invite' => $this->invite,
                'signup' => $signup,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
