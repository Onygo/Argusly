<?php

use App\Support\PublicErrorMessageSanitizer;

it('keeps ordinary provider errors readable', function (): void {
    expect(PublicErrorMessageSanitizer::sanitize('LinkedIn account is not connected.'))
        ->toBe('LinkedIn account is not connected.');
});

it('hides sensitive technical error messages', function (): void {
    $message = 'SQLSTATE[HY000] Access denied for user root in /var/www/html/vendor/laravel/framework/src/File.php:42 token=abc123';

    expect(PublicErrorMessageSanitizer::sanitize($message, 'Safe fallback.'))
        ->toBe('Safe fallback.');
});

it('redacts token shaped values before display', function (): void {
    expect(PublicErrorMessageSanitizer::sanitize('Provider rejected bearer abcdefghijklmnopqrstuvwxyz0123456789'))
        ->toBe('Provider rejected bearer [REDACTED]');

    expect(PublicErrorMessageSanitizer::sanitize('Provider returned token=secret-value', 'Safe fallback.'))
        ->toBe('Safe fallback.');
});
