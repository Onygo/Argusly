<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PilotSignupFollowUp extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{name: string, email: string, company: string, website: string|null, role: string|null, goal: string|null}  $signup
     */
    public function __construct(public array $signup) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Next step for your Argusly pilot',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pilot-signup-follow-up',
        );
    }
}
