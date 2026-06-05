<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BillingWebhookGapsAlert extends Mailable
{
    /**
     * @param array<int,array<string,mixed>> $issueRows
     */
    public function __construct(
        public int $checkedCount,
        public array $issueRows
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[PublishLayer] Mollie webhook activation gaps detected'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.webhook-gaps-alert'
        );
    }
}
