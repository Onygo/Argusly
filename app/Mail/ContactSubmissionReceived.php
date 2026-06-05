<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSubmissionReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ContactSubmission $submission)
    {
    }

    public function envelope(): Envelope
    {
        $subject = trim((string) $this->submission->subject) !== ''
            ? 'Argusly contact: ' . $this->submission->subject
            : 'Argusly contact submission';

        return new Envelope(
            subject: $subject
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-submission-received',
            text: 'emails.contact-submission-received-text',
            with: [
                'headline' => 'New contact submission',
                'intro' => 'A new contact form submission was received.',
                'body' => 'Review the details below and follow up when needed.',
                'subjectLine' => trim((string) $this->submission->subject) !== ''
                    ? 'Argusly contact: ' . $this->submission->subject
                    : 'Argusly contact submission',
                'preheader' => 'A new contact form submission is available.',
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
