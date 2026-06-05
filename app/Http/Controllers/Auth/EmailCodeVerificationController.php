<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\OrganizationApprovalRequested;
use App\Services\Auth\EmailCodeVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class EmailCodeVerificationController extends Controller
{
    public function show(Request $request, EmailCodeVerificationService $codes): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->needsEmailCodeVerification()) {
            return $this->redirectAfterVerification();
        }

        return view('auth.verify-code', [
            'email' => (string) $user->email,
            'expiresInMinutes' => $codes->expiryMinutes(),
            'resendCooldownSeconds' => $codes->resendCooldownSeconds(),
        ]);
    }

    public function verify(Request $request, EmailCodeVerificationService $codes): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->needsEmailCodeVerification()) {
            return $this->redirectAfterVerification();
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        [$userKey, $ipKey] = $this->verificationRateLimitKeys($request, (string) $user->id);
        if ($this->tooManyAttempts($userKey, $ipKey, $codes->verifyMaxAttempts())) {
            $seconds = max(RateLimiter::availableIn($userKey), RateLimiter::availableIn($ipKey));

            return back()->withErrors([
                'code' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $inputCode = trim((string) $validated['code']);
        $hash = trim((string) ($user->email_code_hash ?? ''));
        $expired = ! $user->email_code_expires_at || $user->email_code_expires_at->isPast();
        $invalid = $hash === '' || ! Hash::check($inputCode, $hash);

        if ($expired || $invalid) {
            RateLimiter::hit($userKey, $codes->verifyDecaySeconds());
            RateLimiter::hit($ipKey, $codes->verifyDecaySeconds());
            $codes->recordFailedAttempt($user->fresh());

            return back()->withErrors([
                'code' => $expired
                    ? 'This code has expired. Request a new verification code.'
                    : 'Invalid verification code.',
            ]);
        }

        RateLimiter::clear($userKey);
        RateLimiter::clear($ipKey);
        $codes->markVerified($user->fresh());
        $this->notifyAdminsForVerifiedRegistration($user->fresh());

        return redirect()
            ->intended(route('app.billing.index'))
            ->with('status', 'Email verification completed.');
    }

    public function resend(Request $request, EmailCodeVerificationService $codes): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->needsEmailCodeVerification()) {
            return $this->redirectAfterVerification();
        }

        $freshUser = $user->fresh();
        $secondsSinceSent = $freshUser->email_code_sent_at
            ? $freshUser->email_code_sent_at->diffInSeconds(now())
            : null;
        $cooldown = $codes->resendCooldownSeconds();

        if ($secondsSinceSent !== null && $secondsSinceSent < $cooldown) {
            $waitSeconds = $cooldown - $secondsSinceSent;

            return back()->withErrors([
                'code' => "Please wait {$waitSeconds} seconds before requesting another code.",
            ]);
        }

        [$userKey, $ipKey] = $this->resendRateLimitKeys($request, (string) $user->id);
        if ($this->tooManyAttempts($userKey, $ipKey, $codes->resendMaxAttempts())) {
            $seconds = max(RateLimiter::availableIn($userKey), RateLimiter::availableIn($ipKey));

            return back()->withErrors([
                'code' => "Too many resend requests. Try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($userKey, $codes->resendDecaySeconds());
        RateLimiter::hit($ipKey, $codes->resendDecaySeconds());

        $code = $codes->issueCode($freshUser);
        $codes->sendCode($freshUser->fresh(), $code);

        return back()->with('status', 'A new verification code has been sent.');
    }

    private function redirectAfterVerification(): RedirectResponse
    {
        return redirect()->intended(route('app.billing.index'));
    }

    private function notifyAdminsForVerifiedRegistration(User $user): void
    {
        $organization = $user->organization;
        if (! $organization || (string) $organization->status !== 'pending') {
            return;
        }

        $admins = User::query()->where('is_admin', true)->get();
        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new OrganizationApprovalRequested($organization, $user));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function verificationRateLimitKeys(Request $request, string $userId): array
    {
        $ip = trim((string) ($request->ip() ?? 'unknown'));

        return [
            "email-code-verify:user:{$userId}",
            "email-code-verify:ip:{$ip}",
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resendRateLimitKeys(Request $request, string $userId): array
    {
        $ip = trim((string) ($request->ip() ?? 'unknown'));

        return [
            "email-code-resend:user:{$userId}",
            "email-code-resend:ip:{$ip}",
        ];
    }

    private function tooManyAttempts(string $userKey, string $ipKey, int $maxAttempts): bool
    {
        return RateLimiter::tooManyAttempts($userKey, $maxAttempts)
            || RateLimiter::tooManyAttempts($ipKey, $maxAttempts);
    }
}
