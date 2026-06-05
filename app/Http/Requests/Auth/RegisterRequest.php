<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Validator;
use Throwable;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $turnstileEnabled = $this->turnstileEnabled();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => ['required', 'string', 'min:2', 'max:255'],
            'plan' => ['required', 'string', 'max:120'],
            'cf-turnstile-response' => [$turnstileEnabled ? 'required' : 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = strtolower(trim((string) $this->input('email', '')));
            if ($email !== '' && $this->isDisposableEmail($email)) {
                $validator->errors()->add('email', 'Temporary email addresses are not allowed.');
            }

            if ($this->turnstileEnabled() && ! $this->turnstilePasses()) {
                $validator->errors()->add('cf-turnstile-response', 'Please complete the security check.');
            }
        });
    }

    private function isDisposableEmail(string $email): bool
    {
        $domain = strtolower(trim((string) strrchr($email, '@'), '@'));
        if ($domain === '') {
            return false;
        }

        foreach ((array) config('security.registration.disposable_email_domains', []) as $blockedDomain) {
            $blockedDomain = strtolower(trim((string) $blockedDomain));
            if ($blockedDomain === '') {
                continue;
            }

            if ($domain === $blockedDomain || str_ends_with($domain, '.'.$blockedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function turnstileEnabled(): bool
    {
        return (bool) config('services.turnstile.enabled', false)
            && trim((string) config('services.turnstile.site_key', '')) !== ''
            && trim((string) config('services.turnstile.secret_key', '')) !== '';
    }

    private function turnstilePasses(): bool
    {
        $token = trim((string) $this->input('cf-turnstile-response', ''));
        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(3)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => (string) ($this->ip() ?? ''),
                ]);
        } catch (Throwable) {
            return false;
        }

        return $response->ok() && (bool) ($response->json('success') ?? false);
    }
}
