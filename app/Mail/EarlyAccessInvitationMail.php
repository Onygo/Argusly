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
            subject: 'Your PublishLayer early access invite'
        );
    }

    public function content(): Content
    {
        $signup = $this->invite->signup;

        return new Content(
            view: 'emails.early-access-invitation',
            text: 'emails.early-access-invitation-text',
            with: [
                'subjectLine' => 'Your PublishLayer early access invite',
                'preheader' => 'Activate your PublishLayer early access account.',
                'headline' => 'Activate your early access account',
                'intro' => sprintf('Your PublishLayer early access request for %s has been approved.', (string) ($signup?->company_name ?: $signup?->full_name ?: 'your team')),
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
