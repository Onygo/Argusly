<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PilotSignupRequested extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{name: string, email: string, company: string, website: string|null, role: string|null, goal: string|null, created_at: string}  $signup
     */
    public function __construct(public array $signup)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [$this->signup['email']],
            subject: 'New Argusly pilot request: '.$this->signup['company'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pilot-signup-requested',
        );
    }
}
