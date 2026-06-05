<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $expiresInMinutes
    ) {
    }

    /**
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $payload = [
            'preheader' => 'Use this one-time code to verify your email address.',
            'headline' => 'Verify your email',
            'intro' => 'Enter the code below to continue.',
            'body' => "This verification code expires in {$this->expiresInMinutes} minutes.",
            'lines' => [
                "Verification code: {$this->code}",
                'If you did not request this, ignore this message.',
            ],
        ];

        return (new MailMessage)
            ->subject('Verify your email')
            ->view('emails.notifications.verify-email-code', $payload)
            ->text('emails.notifications.verify-email-code-text', $payload);
    }
}
