<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserApprovalRequested extends Notification
{
    use Queueable;

    public function __construct(public User $user)
    {
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
        return (new MailMessage)
            ->subject('User approval requested')
            ->view('emails.notifications.user-approval-requested', [
                'preheader' => 'A new user registration is waiting for approval.',
                'headline' => 'User approval requested',
                'intro' => 'A new user registration was submitted.',
                'lines' => [
                    "Name: {$this->user->name}",
                    "Email: {$this->user->email}",
                    'You can review this request in the admin user list.',
                ],
                'cta_label' => 'Review user',
                'cta_url' => route('admin.users'),
            ])
            ->text('emails.notifications.user-approval-requested-text', [
                'preheader' => 'A new user registration is waiting for approval.',
                'headline' => 'User approval requested',
                'intro' => 'A new user registration was submitted.',
                'lines' => [
                    "Name: {$this->user->name}",
                    "Email: {$this->user->email}",
                    'You can review this request in the admin user list.',
                ],
                'cta_label' => 'Review user',
                'cta_url' => route('admin.users'),
            ]);
    }
}
