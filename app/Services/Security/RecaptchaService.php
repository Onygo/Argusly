<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecaptchaService
{
    private const VERIFY_API_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function siteKey(): ?string
    {
        $siteKey = trim((string) config('services.recaptcha.site_key', ''));

        return $siteKey !== '' ? $siteKey : null;
    }

    public function isConfigured(): bool
    {
        return $this->siteKey() !== null
            && $this->secretKey() !== null;
    }

    public function verify(?string $token, string $expectedAction = 'submit'): bool
    {
        $siteKey = $this->siteKey();
        $secretKey = $this->secretKey();
        $responseToken = trim((string) $token);

        if ($siteKey === null || $secretKey === null) {
            Log::warning('reCAPTCHA verification missing configuration', [
                'has_site_key' => $siteKey !== null,
                'has_secret' => $secretKey !== null,
            ]);

            return false;
        }

        if ($responseToken === '') {
            Log::warning('reCAPTCHA verification missing token');

            return false;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(5)
                ->timeout(8)
                ->post(self::VERIFY_API_URL, [
                    'secret' => $secretKey,
                    'response' => $responseToken,
                ]);
        } catch (Throwable $exception) {
            Log::warning('reCAPTCHA verification HTTP failure', [
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! $response->ok()) {
            Log::warning('reCAPTCHA verification HTTP failure', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $data = (array) $response->json();
        $minScore = (float) config('services.recaptcha.min_score', 0.5);
        $success = ($data['success'] ?? false) === true;
        $action = (string) ($data['action'] ?? '');
        $score = (float) ($data['score'] ?? 0);
        $errorCodes = $data['error-codes'] ?? [];

        Log::info('reCAPTCHA verification response', [
            'success' => $success,
            'score' => $score,
            'action' => $action !== '' ? $action : null,
            'hostname' => $data['hostname'] ?? null,
            'error_codes' => is_array($errorCodes) ? $errorCodes : [],
        ]);

        if (! $success) {
            Log::warning('reCAPTCHA verification failed', [
                'error_codes' => is_array($errorCodes) ? $errorCodes : [],
            ]);

            return false;
        }

        if ($action !== $expectedAction) {
            Log::warning('reCAPTCHA verification action mismatch', [
                'expected' => $expectedAction,
                'actual' => $action !== '' ? $action : null,
            ]);

            return false;
        }

        if ($score < $minScore) {
            Log::warning('reCAPTCHA verification score below threshold', [
                'score' => $score,
                'threshold' => $minScore,
            ]);

            return false;
        }

        return true;
    }

    private function secretKey(): ?string
    {
        $secretKey = trim((string) config('services.recaptcha.secret_key', ''));

        return $secretKey !== '' ? $secretKey : null;
    }
}
