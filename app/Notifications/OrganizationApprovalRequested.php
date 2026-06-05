<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationApprovalRequested extends Notification
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public User $user
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Organization approval requested')
            ->view('emails.notifications.organization-approval-requested', [
                'preheader' => 'A new organization is waiting for approval.',
                'headline' => 'Organization approval requested',
                'intro' => 'A new organization registration is waiting for review.',
                'lines' => [
                    'Organization: ' . $this->organization->name,
                    'Requester: ' . $this->user->name . ' (' . $this->user->email . ')',
                ],
                'cta_label' => 'Review organization',
                'cta_url' => route('admin.organizations.show', $this->organization),
            ])
            ->text('emails.notifications.organization-approval-requested-text', [
                'preheader' => 'A new organization is waiting for approval.',
                'headline' => 'Organization approval requested',
                'intro' => 'A new organization registration is waiting for review.',
                'lines' => [
                    'Organization: ' . $this->organization->name,
                    'Requester: ' . $this->user->name . ' (' . $this->user->email . ')',
                ],
                'cta_label' => 'Review organization',
                'cta_url' => route('admin.organizations.show', $this->organization),
            ]);
    }
}
