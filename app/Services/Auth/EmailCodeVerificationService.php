<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Support\Facades\Hash;

class EmailCodeVerificationService
{
    public function issueCode(User $user): string
    {
        $code = $this->generateCode();

        $user->forceFill([
            'email_code_hash' => Hash::make($code),
            'email_code_expires_at' => now()->addMinutes($this->expiryMinutes()),
            'email_code_verified_at' => null,
            'email_code_sent_at' => now(),
            'email_code_attempts' => 0,
            'email_code_last_attempt_at' => null,
        ])->save();

        return $code;
    }

    public function sendCode(User $user, string $code): void
    {
        $user->notify(new VerifyEmailCodeNotification($code, $this->expiryMinutes()));
    }

    public function markVerified(User $user): void
    {
        $user->forceFill([
            'email_code_hash' => null,
            'email_code_expires_at' => null,
            'email_code_verified_at' => now(),
            'email_code_attempts' => 0,
            'email_code_last_attempt_at' => null,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();
    }

    public function recordFailedAttempt(User $user): void
    {
        $user->forceFill([
            'email_code_attempts' => ((int) $user->email_code_attempts) + 1,
            'email_code_last_attempt_at' => now(),
        ])->save();
    }

    public function expiryMinutes(): int
    {
        return max(1, (int) config('publishlayer.auth.email_code.expiry_minutes', 15));
    }

    public function resendCooldownSeconds(): int
    {
        return max(1, (int) config('publishlayer.auth.email_code.resend_cooldown_seconds', 60));
    }

    public function verifyMaxAttempts(): int
    {
        return max(1, (int) config('publishlayer.auth.email_code.verify_max_attempts', 5));
    }

    public function verifyDecaySeconds(): int
    {
        return max(1, (int) config('publishlayer.auth.email_code.verify_decay_seconds', 900));
    }

    public function resendMaxAttempts(): int
    {
        return max(1, (int) config('publishlayer.auth.email_code.resend_max_attempts', 5));
    }

    public function resendDecaySeconds(): int
    {
        return max(1, (int) config('publishlayer.auth.email_code.resend_decay_seconds', 900));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
