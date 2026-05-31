<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailProvider;
use Illuminate\Support\Str;

class FakeEmailProvider implements EmailProviderInterface
{
    public function sendTestEmail(EmailProvider $provider, string $to): array
    {
        return [
            'ok' => true,
            'provider' => $provider->provider,
            'message_id' => 'fake_'.Str::uuid()->toString(),
            'to' => $to,
            'subject' => 'Argusly email provider test',
        ];
    }

    public function sendNewsletterEmail(EmailProvider $provider, string $to, array $payload): array
    {
        if (str_contains($to, 'fail') || ($provider->settings['force_failure'] ?? false)) {
            return [
                'ok' => false,
                'provider' => $provider->provider,
                'to' => $to,
                'subject' => $payload['subject'],
                'error' => 'Fake email provider rejected the recipient.',
            ];
        }

        return [
            'ok' => true,
            'provider' => $provider->provider,
            'message_id' => 'fake_newsletter_'.Str::uuid()->toString(),
            'to' => $to,
            'subject' => $payload['subject'],
        ];
    }
}
