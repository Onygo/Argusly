<?php

namespace App\Services;

use App\Enums\EarlyAccessSignupStatus;
use App\Mail\EarlyAccessInvitationMail;
use App\Models\EarlyAccessInvite;
use App\Models\EarlyAccessSignup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EarlyAccessInvitationService
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function send(EarlyAccessSignup $signup, ?User $actor = null, ?Request $request = null): EarlyAccessInvite
    {
        $invite = DB::transaction(function () use ($signup, $actor, $request): EarlyAccessInvite {
            $signup = EarlyAccessSignup::query()->whereKey($signup->id)->lockForUpdate()->firstOrFail();
            $status = $this->statusFor($signup);

            if ($status === EarlyAccessSignupStatus::ACTIVATED) {
                $this->throwInviteError('Activated signups cannot receive a new invite.');
            }

            if ($status === EarlyAccessSignupStatus::REJECTED) {
                $this->throwInviteError('Rejected signups must be approved again before sending an invite.');
            }

            if (! in_array($status, [EarlyAccessSignupStatus::APPROVED, EarlyAccessSignupStatus::INVITED], true)) {
                $this->throwInviteError('Approve this signup before sending an invite.');
            }

            $existingUser = $this->existingUserForEmail((string) $signup->email);
            if ($existingUser && (int) ($existingUser->organization_id ?? 0) > 0) {
                $this->throwInviteError('This email already belongs to an existing user account. Review the signup before inviting.');
            }

            EarlyAccessInvite::query()
                ->where('early_access_signup_id', $signup->id)
                ->whereNull('accepted_at')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->update([
                    'expires_at' => now(),
                    'updated_at' => now(),
                ]);

            $plainToken = Str::random(64);

            $invite = EarlyAccessInvite::query()->create([
                'early_access_signup_id' => $signup->id,
                'email' => (string) $signup->email,
                'token_hash' => hash('sha256', $plainToken),
                'token_encrypted' => Crypt::encryptString($plainToken),
                'expires_at' => now()->addDays(14),
                'invited_by' => $actor?->id,
            ]);

            $before = [
                'status' => $status->value,
                'invited_at' => optional($signup->invited_at)?->toIso8601String(),
                'invited_by' => $signup->invited_by,
            ];

            $signup->status = EarlyAccessSignupStatus::INVITED;
            $signup->invited_at = now();
            $signup->invited_by = $actor?->id ?? $signup->invited_by;
            $signup->rejected_at = null;
            $signup->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $signup,
                action: 'early_access.signup.invited',
                before: $before,
                after: [
                    'status' => $this->statusFor($signup)->value,
                    'invited_at' => optional($signup->invited_at)?->toIso8601String(),
                    'invite_id' => $invite->id,
                    'invited_by' => $signup->invited_by,
                ],
                request: $request
            );

            return $invite->fresh(['signup']);
        });

        Mail::to($invite->email)->send(new EarlyAccessInvitationMail($invite));

        return $invite;
    }

    public function resend(EarlyAccessSignup $signup, ?User $actor = null, ?Request $request = null): EarlyAccessInvite
    {
        return $this->send($signup, $actor, $request);
    }

    public function resolveInvite(string $token): EarlyAccessInvite
    {
        $hash = hash('sha256', $token);

        $invite = EarlyAccessInvite::query()
            ->with('signup')
            ->where('token_hash', $hash)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invite->isExpired()) {
            abort(410);
        }

        return $invite;
    }

    public function inviteUrl(EarlyAccessInvite $invite): string
    {
        return URL::route('public.early-access.invites.show', $invite->token);
    }

    private function existingUserForEmail(string $email): ?User
    {
        $normalized = strtolower(trim($email));

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();
    }

    private function statusFor(EarlyAccessSignup $signup): EarlyAccessSignupStatus
    {
        return $signup->status instanceof EarlyAccessSignupStatus
            ? $signup->status
            : EarlyAccessSignupStatus::from((string) $signup->status);
    }

    private function throwInviteError(string $message): never
    {
        throw ValidationException::withMessages([
            'early_access' => $message,
        ]);
    }
}
