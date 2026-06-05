@props([
    'action' => 'submit',
])

@php
    $recaptchaService = app(\App\Services\Security\RecaptchaService::class);
    $siteKey = $recaptchaService->siteKey();
    $isConfigured = $recaptchaService->isConfigured();
@endphp

<div {{ $attributes->class(['md:col-span-2']) }} data-recaptcha-container data-recaptcha-action="{{ $action }}">
    @if ($isConfigured && $siteKey)
        <input type="hidden" name="recaptcha_token" value="{{ old('recaptcha_token') }}" data-recaptcha-token>
        <p class="text-xs text-textSecondary">
            {{ __('public.page.contact_form.recaptcha_label') }}
        </p>

        <script>
            (function() {
                const currentScript = document.currentScript;
                const container = currentScript ? currentScript.closest('[data-recaptcha-container]') : null;
                const form = currentScript ? currentScript.closest('form') : null;
                const tokenField = container ? container.querySelector('[data-recaptcha-token]') : null;
                const action = container ? (container.getAttribute('data-recaptcha-action') || 'submit') : 'submit';

                if (!form || !tokenField) {
                    return;
                }

                form.addEventListener('submit', function(event) {
                    if (form.dataset.recaptchaSubmitting === 'true') {
                        return;
                    }

                    event.preventDefault();

                    if (typeof grecaptcha === 'undefined' || typeof grecaptcha.execute !== 'function') {
                        console.error('reCAPTCHA v3 not loaded');
                        return;
                    }

                    grecaptcha.ready(function() {
                        grecaptcha.execute('{{ $siteKey }}', { action: action })
                            .then(function(token) {
                                tokenField.value = token;
                                form.dataset.recaptchaSubmitting = 'true';
                                form.submit();
                            })
                            .catch(function(error) {
                                console.error('reCAPTCHA v3 error:', error);
                            });
                    });
                });
            })();
        </script>
    @else
        <div class="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ __('public.page.contact_form.recaptcha_unavailable') }}
        </div>
    @endif

    @error('recaptcha_token')
        <p class="mt-2 text-sm text-rose-800">{{ $message }}</p>
    @enderror
</div>
