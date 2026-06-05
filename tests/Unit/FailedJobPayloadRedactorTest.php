<?php

use App\Support\FailedJobPayloadRedactor;

it('redacts secret keys and bearer tokens while keeping identifiers visible', function () {
    $payload = [
        'organization_id' => 99,
        'site_id' => 'site_abc',
        'token' => 'plain_token_value',
        'api_key' => 'key_123',
        'authorization' => 'Bearer super-secret-token',
        'nested' => [
            'password' => 'secret-password',
            'webhook_secret' => 'whsec_123',
            'safe' => 'keep-me',
        ],
    ];

    $redacted = FailedJobPayloadRedactor::redact($payload);

    expect($redacted['organization_id'])->toBe(99)
        ->and($redacted['site_id'])->toBe('site_abc')
        ->and($redacted['token'])->toBe('[REDACTED]')
        ->and($redacted['api_key'])->toBe('[REDACTED]')
        ->and($redacted['authorization'])->toBe('Bearer [REDACTED]')
        ->and($redacted['nested']['password'])->toBe('[REDACTED]')
        ->and($redacted['nested']['webhook_secret'])->toBe('[REDACTED]')
        ->and($redacted['nested']['safe'])->toBe('keep-me');
});
