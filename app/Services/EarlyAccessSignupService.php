<?php

namespace App\Services;

use App\Enums\EarlyAccessSignupStatus;
use App\Models\EarlyAccessSignup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EarlyAccessSignupService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly PilotQualificationService $qualification
    )
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createFromPublicSubmission(array $payload, Request $request): EarlyAccessSignup
    {
        $email = strtolower(trim((string) ($payload['email'] ?? $payload['work_email'] ?? '')));
        $source = trim((string) ($payload['source'] ?? $payload['intent'] ?? ''));
        $data = [
            'full_name' => trim((string) ($payload['full_name'] ?? '')),
            'email' => $email,
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'country' => trim((string) ($payload['country'] ?? '')) ?: null,
            'job_title' => trim((string) ($payload['job_title'] ?? '')) ?: null,
            'company_name' => trim((string) ($payload['company_name'] ?? $payload['company'] ?? '')) ?: null,
            'company_size' => trim((string) ($payload['company_size_visible'] ?? $payload['team_size'] ?? '')) ?: null,
            'industry' => trim((string) ($payload['industry'] ?? '')) ?: null,
            'website' => trim((string) ($payload['website'] ?? '')) ?: null,
            'use_case' => trim((string) ($payload['use_case'] ?? $payload['message'] ?? '')) ?: null,
            'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
            'source' => $source !== '' ? $source : null,
            'priority' => $this->normalizePriority($payload['priority'] ?? null),
            'utm_source' => trim((string) ($payload['utm_source'] ?? '')) ?: null,
            'utm_medium' => trim((string) ($payload['utm_medium'] ?? '')) ?: null,
            'utm_campaign' => trim((string) ($payload['utm_campaign'] ?? '')) ?: null,
            'marketing_consent' => filter_var($payload['marketing_consent'] ?? false, FILTER_VALIDATE_BOOL),
        ];
        $data['qualification_score'] = $this->qualification->score($data);

        return EarlyAccessSignup::query()->create($data + [
            'status' => EarlyAccessSignupStatus::NEW,
            'submitted_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createManualPilotApplication(array $payload, ?User $actor = null): EarlyAccessSignup
    {
        $data = [
            'full_name' => trim((string) ($payload['full_name'] ?? '')),
            'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
            'company_name' => trim((string) ($payload['company_name'] ?? '')) ?: null,
            'website' => trim((string) ($payload['website'] ?? '')) ?: null,
            'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
            'source' => 'admin_invite',
            'priority' => 'high',
            'assigned_admin_id' => $actor?->id,
        ];
        $data['qualification_score'] = $this->qualification->score($data);

        return EarlyAccessSignup::query()->create($data + [
            'status' => EarlyAccessSignupStatus::INVITED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'approved_at' => now(),
            'invited_at' => now(),
            'invited_by' => $actor?->id,
        ]);
    }

    public function markReviewed(EarlyAccessSignup $signup, ?User $actor = null, ?Request $request = null): EarlyAccessSignup
    {
        return DB::transaction(function () use ($signup, $actor, $request): EarlyAccessSignup {
            $signup = $this->lockSignup($signup);
            $status = $this->statusFor($signup);

            if ($status === EarlyAccessSignupStatus::ACTIVATED) {
                $this->throwStatusError('Activated signups cannot be reviewed again.');
            }

            if (! in_array($status, [EarlyAccessSignupStatus::NEW, EarlyAccessSignupStatus::REJECTED, EarlyAccessSignupStatus::REVIEWED], true)) {
                $this->throwStatusError('Only new or rejected signups can be marked as reviewed.');
            }

            $before = ['status' => $status->value, 'reviewed_at' => optional($signup->reviewed_at)?->toIso8601String()];

            $signup->status = EarlyAccessSignupStatus::REVIEWED;
            $signup->reviewed_at = $signup->reviewed_at ?? now();
            $signup->rejected_at = null;
            $signup->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $signup,
                action: 'early_access.signup.reviewed',
                before: $before,
                after: ['status' => $this->statusFor($signup)->value, 'reviewed_at' => optional($signup->reviewed_at)?->toIso8601String()],
                request: $request
            );

            return $signup;
        });
    }

    public function approve(EarlyAccessSignup $signup, ?User $actor = null, ?Request $request = null): EarlyAccessSignup
    {
        return DB::transaction(function () use ($signup, $actor, $request): EarlyAccessSignup {
            $signup = $this->lockSignup($signup);
            $status = $this->statusFor($signup);

            if ($status === EarlyAccessSignupStatus::ACTIVATED) {
                $this->throwStatusError('Activated signups cannot be approved again.');
            }

            if (! in_array($status, [
                EarlyAccessSignupStatus::NEW,
                EarlyAccessSignupStatus::REVIEWED,
                EarlyAccessSignupStatus::REJECTED,
                EarlyAccessSignupStatus::APPROVED,
            ], true)) {
                $this->throwStatusError('This signup cannot be approved from its current status.');
            }

            $before = [
                'status' => $status->value,
                'approved_at' => optional($signup->approved_at)?->toIso8601String(),
                'reviewed_at' => optional($signup->reviewed_at)?->toIso8601String(),
            ];

            $signup->status = EarlyAccessSignupStatus::APPROVED;
            $signup->reviewed_at = $signup->reviewed_at ?? now();
            $signup->approved_at = now();
            $signup->rejected_at = null;
            $signup->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $signup,
                action: 'early_access.signup.approved',
                before: $before,
                after: [
                    'status' => $this->statusFor($signup)->value,
                    'approved_at' => optional($signup->approved_at)?->toIso8601String(),
                    'reviewed_at' => optional($signup->reviewed_at)?->toIso8601String(),
                ],
                request: $request
            );

            return $signup;
        });
    }

    public function reject(EarlyAccessSignup $signup, ?User $actor = null, ?Request $request = null): EarlyAccessSignup
    {
        return DB::transaction(function () use ($signup, $actor, $request): EarlyAccessSignup {
            $signup = $this->lockSignup($signup);
            $status = $this->statusFor($signup);

            if ($status === EarlyAccessSignupStatus::ACTIVATED) {
                $this->throwStatusError('Activated signups cannot be rejected.');
            }

            if ($status === EarlyAccessSignupStatus::REJECTED) {
                return $signup;
            }

            $before = ['status' => $status->value, 'rejected_at' => optional($signup->rejected_at)?->toIso8601String()];

            $signup->status = EarlyAccessSignupStatus::REJECTED;
            $signup->rejected_at = now();
            $signup->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $signup,
                action: 'early_access.signup.rejected',
                before: $before,
                after: ['status' => $this->statusFor($signup)->value, 'rejected_at' => optional($signup->rejected_at)?->toIso8601String()],
                request: $request
            );

            return $signup;
        });
    }

    public function updateInternalNotes(EarlyAccessSignup $signup, ?string $notes, ?User $actor = null, ?Request $request = null): EarlyAccessSignup
    {
        $cleanNotes = trim((string) $notes);

        return DB::transaction(function () use ($signup, $cleanNotes, $actor, $request): EarlyAccessSignup {
            $signup = $this->lockSignup($signup);
            $before = ['internal_notes' => (string) ($signup->internal_notes ?? '')];

            $signup->internal_notes = $cleanNotes !== '' ? $cleanNotes : null;
            $signup->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $signup,
                action: 'early_access.signup.internal_notes.updated',
                before: $before,
                after: ['internal_notes' => (string) ($signup->internal_notes ?? '')],
                request: $request
            );

            return $signup;
        });
    }

    private function lockSignup(EarlyAccessSignup $signup): EarlyAccessSignup
    {
        return EarlyAccessSignup::query()->whereKey($signup->id)->lockForUpdate()->firstOrFail();
    }

    private function statusFor(EarlyAccessSignup $signup): EarlyAccessSignupStatus
    {
        return $signup->status instanceof EarlyAccessSignupStatus
            ? $signup->status
            : EarlyAccessSignupStatus::from((string) $signup->status);
    }

    private function throwStatusError(string $message): never
    {
        throw ValidationException::withMessages([
            'early_access' => $message,
        ]);
    }

    private function normalizePriority(mixed $value): ?string
    {
        $priority = strtolower(trim((string) $value));

        return in_array($priority, ['low', 'medium', 'high'], true) ? $priority : null;
    }
}
