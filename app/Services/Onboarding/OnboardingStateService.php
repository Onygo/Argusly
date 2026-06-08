<?php

namespace App\Services\Onboarding;

use App\Models\Brief;
use App\Models\Draft;
use App\Models\OnboardingState;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OnboardingStateService
{
    public function ensureForUser(User $user, ?string $workspaceId = null): OnboardingState
    {
        $workspace = $workspaceId ? Workspace::query()->find($workspaceId) : $this->resolvePrimaryWorkspace($user);

        /** @var OnboardingState $state */
        $state = OnboardingState::query()->firstOrNew(['user_id' => $user->id]);
        if (! $state->exists) {
            $state->id = (string) \Illuminate\Support\Str::uuid();
            $state->registered_at = now();
            $state->phase = $this->isEmailVerificationRequired()
                ? OnboardingState::PHASE_EMAIL_UNVERIFIED
                : OnboardingState::PHASE_REGISTERED;
            $state->emails_sent_json = [];
            $state->completed_steps_json = [];
        }

        $state->organization_id = $user->organization_id ?: $state->organization_id;
        $state->workspace_id = $workspace?->id ?: $state->workspace_id;
        $state->save();

        return $state;
    }

    public function markRegistered(User $user, ?string $workspaceId = null): OnboardingState
    {
        return $this->ensureForUser($user, $workspaceId);
    }

    public function markVerified(User $user): ?OnboardingState
    {
        $state = $this->ensureForUser($user);
        $state->verified_at = $state->verified_at ?: now();
        if ($state->phase !== OnboardingState::PHASE_ACTIVATED) {
            $state->phase = OnboardingState::PHASE_VERIFIED;
        }
        $state->save();

        return $state;
    }

    public function markFirstLogin(User $user): ?OnboardingState
    {
        $state = $this->ensureForUser($user);
        $state->first_login_at = $state->first_login_at ?: now();
        $state->last_activity_at = now();
        if ($state->phase !== OnboardingState::PHASE_ACTIVATED) {
            $state->phase = OnboardingState::PHASE_FIRST_LOGIN;
        }
        $state->save();

        return $state;
    }

    public function markIntent(User $user, string $intent): OnboardingState
    {
        $state = $this->ensureForUser($user);
        $state->intent = $intent;
        $this->markStepCompleted($state, 'intent');
        $this->finalizeWizardIfCompleted($state);
        $state->save();

        return $state;
    }

    public function markCompanyProfileCompleted(User $user, Workspace $workspace): OnboardingState
    {
        $state = $this->ensureForUser($user, (string) $workspace->id);
        $this->markStepCompleted($state, 'company_profile');
        $this->finalizeWizardIfCompleted($state);
        $state->save();

        return $state;
    }

    public function markSiteConnectedCompleted(User $user): OnboardingState
    {
        $state = $this->ensureForUser($user);
        $this->markStepCompleted($state, 'connect_site');
        $this->finalizeWizardIfCompleted($state);
        $state->save();

        return $state;
    }

    public function markSubscribedForOrganization(int $organizationId, ?string $workspaceId = null): void
    {
        if ($organizationId <= 0) {
            return;
        }

        OnboardingState::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->each(function (OnboardingState $state) use ($workspaceId): void {
                $this->markStepCompleted($state, 'subscribed');
                $state->workspace_id = $workspaceId ?: $state->workspace_id;
                $state->last_activity_at = now();
                $state->save();
            });
    }

    public function recordBriefActivity(Brief $brief): void
    {
        $organizationId = $brief->clientSite?->workspace?->organization_id;
        $workspaceId = $brief->clientSite?->workspace_id;
        if (! $organizationId || ! $workspaceId) {
            return;
        }

        $this->touchOrganizationStates((int) $organizationId, (string) $workspaceId);
        $this->markFirstValueForOrganization((int) $organizationId, (string) $workspaceId);
    }

    public function recordDraftActivity(Draft $draft): void
    {
        $organizationId = $draft->clientSite?->workspace?->organization_id;
        $workspaceId = $draft->clientSite?->workspace_id;
        if (! $organizationId || ! $workspaceId) {
            return;
        }

        $this->touchOrganizationStates((int) $organizationId, (string) $workspaceId);
        $this->markFirstValueForOrganization((int) $organizationId, (string) $workspaceId);
    }

    public function recordPushActivity(Draft $draft): void
    {
        $organizationId = $draft->clientSite?->workspace?->organization_id;
        $workspaceId = $draft->clientSite?->workspace_id;
        if (! $organizationId || ! $workspaceId) {
            return;
        }

        $this->touchOrganizationStates((int) $organizationId, (string) $workspaceId);
        $this->markFirstValueForOrganization((int) $organizationId, (string) $workspaceId);
    }

    public function markEmailSent(OnboardingState $state, string $emailKey, ?CarbonInterface $sentAt = null): OnboardingState
    {
        $sent = is_array($state->emails_sent_json) ? $state->emails_sent_json : [];
        if (! array_key_exists($emailKey, $sent)) {
            $sent[$emailKey] = ($sentAt ?: now())->toIso8601String();
            $state->emails_sent_json = $sent;
            $state->last_email_sent_at = $sentAt ?: now();
            $state->save();
        }

        return $state;
    }

    public function markCold(OnboardingState $state): void
    {
        if ($state->phase === OnboardingState::PHASE_ACTIVATED || $state->phase === OnboardingState::PHASE_FIRST_LOGIN || $state->phase === OnboardingState::PHASE_VERIFIED) {
            $state->phase = OnboardingState::PHASE_COLD;
            $state->save();
        }
    }

    public function activeTrialEndsAt(OnboardingState $state): ?CarbonInterface
    {
        if (! $state->organization_id) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('organization_id', $state->organization_id)
            ->where('status', 'trialing')
            ->whereNotNull('current_period_end')
            ->orderBy('current_period_end')
            ->first();

        return $subscription?->current_period_end;
    }

    private function touchOrganizationStates(int $organizationId, string $workspaceId): void
    {
        OnboardingState::query()
            ->where('organization_id', $organizationId)
            ->update([
                'last_activity_at' => now(),
                'workspace_id' => $workspaceId,
                'updated_at' => now(),
            ]);
    }

    private function markFirstValueForOrganization(int $organizationId, string $workspaceId): void
    {
        DB::transaction(function () use ($organizationId, $workspaceId): void {
            /** @var Collection<int,OnboardingState> $states */
            $states = OnboardingState::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->get();

            foreach ($states as $state) {
                if ($state->first_value_at) {
                    continue;
                }

                $state->first_value_at = now();
                $state->phase = OnboardingState::PHASE_ACTIVATED;
                $state->workspace_id = $workspaceId;
                $state->last_activity_at = now();
                $this->markStepCompleted($state, 'first_value');
                $state->save();

                \App\Jobs\SendOnboardingEmailJob::dispatch((int) $state->user_id, 'first_value_ready');
            }
        });
    }

    private function markStepCompleted(OnboardingState $state, string $step): void
    {
        $steps = is_array($state->completed_steps_json) ? $state->completed_steps_json : [];
        if (! in_array($step, $steps, true)) {
            $steps[] = $step;
            $state->completed_steps_json = array_values($steps);
        }
    }

    private function finalizeWizardIfCompleted(OnboardingState $state): void
    {
        $steps = is_array($state->completed_steps_json) ? $state->completed_steps_json : [];
        $required = ['intent', 'company_profile', 'connect_site'];
        $allCompleted = count(array_intersect($required, $steps)) === count($required);

        if ($allCompleted && $state->phase !== OnboardingState::PHASE_ACTIVATED) {
            $state->phase = OnboardingState::PHASE_ACTIVATED;
            $state->last_activity_at = now();
        }
    }

    private function resolvePrimaryWorkspace(User $user): ?Workspace
    {
        if (! $user->organization_id) {
            return null;
        }

        return Workspace::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('created_at')
            ->first();
    }

    private function isEmailVerificationRequired(): bool
    {
        return (bool) config('argusly.onboarding.require_email_verification', false);
    }
}
