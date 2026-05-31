<?php

namespace App\Contracts;

use App\Models\EmailProvider;

interface EmailProviderInterface
{
    /**
     * @return array{ok: bool, provider: string, message_id: string, to: string, subject: string}
     */
    public function sendTestEmail(EmailProvider $provider, string $to): array;

    /**
     * @param  array{subject: string, html?: string|null, text?: string|null, metadata?: array<string, mixed>|null}  $payload
     * @return array{ok: bool, provider: string, message_id?: string|null, to: string, subject: string, error?: string|null}
     */
    public function sendNewsletterEmail(EmailProvider $provider, string $to, array $payload): array;
}
