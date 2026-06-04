<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{name: string, email: string, company: string|null, website: string|null, topic: string, message: string, status: string, lead_score: int, lead_quality: string, lead_signals: array<int, string>, suggested_reply: string, created_at: string}  $contactRequest
     */
    public function __construct(public array $contactRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [$this->contactRequest['email']],
            subject: 'New Argusly contact request: '.$this->topicLabel(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-request-submitted',
            with: [
                'topicLabel' => $this->topicLabel(),
            ],
        );
    }

    public function topicLabel(): string
    {
        return [
            'pilot' => 'Pilot request',
            'sales' => 'Sales conversation',
            'support' => 'Support',
            'partnership' => 'Partnership',
            'press' => 'Press',
            'other' => 'Other',
        ][$this->contactRequest['topic']] ?? 'Contact';
    }
}
