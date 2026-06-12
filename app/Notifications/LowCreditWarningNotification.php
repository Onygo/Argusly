<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowCreditWarningNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly string $mailLocale
    ) {}

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
        $locale = $this->mailLocale;
        $ctaUrl = (string) ($this->payload['cta_url'] ?? '');
        $title = trans('app.credits.low_warning.title', [], $locale);
        $bodyKey = (bool) ($this->payload['has_active_automations'] ?? false)
            ? 'app.credits.low_warning.body_with_automation'
            : 'app.credits.low_warning.body';

        $body = trans($bodyKey, [
            'count' => (int) ($this->payload['active_automation_count'] ?? 0),
            'next_run' => (string) ($this->payload['next_automation_run_label'] ?? ''),
        ], $locale);

        $automationHint = null;

        if ((bool) ($this->payload['has_active_automations'] ?? false)) {
            $automationHint = trans('app.credits.low_warning.email_automation_hint', [
                'count' => (int) ($this->payload['active_automation_count'] ?? 0),
                'next_run' => (string) ($this->payload['next_automation_run_label'] ?? trans('app.common.na', [], $locale)),
            ], $locale);
        }

        $payload = [
            'subjectLine' => trans('app.credits.low_warning.email_subject', [], $locale),
            'preheader' => $title,
            'headline' => trans('app.credits.low_warning.email_greeting', [], $locale),
            'intro' => $title,
            'body' => $body,
            'availableCredits' => number_format((int) ($this->payload['available_credits'] ?? 0)),
            'automationHint' => $automationHint,
            'cta_label' => trans('app.credits.low_warning.cta', [], $locale),
            'cta_url' => $ctaUrl,
        ];

        return (new MailMessage)
            ->subject(trans('app.credits.low_warning.email_subject', [], $locale))
            ->view('emails.notifications.low-credit-warning', $payload)
            ->text('emails.notifications.low-credit-warning-text', $payload);
    }
}
