<?php

namespace App\Services;

use App\Enums\EarlyAccessSignupStatus;
use App\Jobs\SendOnboardingEmailJob;
use App\Models\EarlyAccessInvite;
use App\Models\EarlyAccessSignup;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\EntitlementRefreshService;
use App\Services\Onboarding\OnboardingStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EarlyAccessActivationService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly EntitlementRefreshService $entitlements,
        private readonly OnboardingStateService $onboarding
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function activateFromInvite(EarlyAccessInvite $invite, array $payload, ?Request $request = null): User
    {
        /** @var User $user */
        $user = DB::transaction(function () use ($invite, $payload, $request): User {
            $invite = EarlyAccessInvite::query()
                ->with('signup')
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invite->accepted_at !== null || $invite->isExpired()) {
                $this->throwActivationError('This invite is no longer valid.');
            }

            /** @var EarlyAccessSignup $signup */
            $signup = EarlyAccessSignup::query()
                ->whereKey($invite->early_access_signup_id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $this->statusFor($signup);
            if ($status === EarlyAccessSignupStatus::ACTIVATED) {
                $this->throwActivationError('This signup has already been activated.');
            }

            if (! in_array($status, [EarlyAccessSignupStatus::APPROVED, EarlyAccessSignupStatus::INVITED], true)) {
                $this->throwActivationError('This signup is not ready for activation.');
            }

            $organization = $this->createOrganizationForSignup($signup, $invite);
            $workspace = $this->createWorkspaceForSignup($signup, $organization);

            $existingUser = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $invite->email)])
                ->lockForUpdate()
                ->first();

            if ($existingUser && (int) ($existingUser->organization_id ?? 0) > 0 && (int) $existingUser->organization_id !== (int) $organization->id) {
                $this->throwActivationError('This email already belongs to an existing organization account.');
            }

            $user = $existingUser ?: new User();
            $user->forceFill([
                'name' => trim((string) ($payload['name'] ?? $signup->full_name)),
                'email' => strtolower(trim((string) $invite->email)),
                'password' => Hash::make((string) $payload['password']),
                'organization_id' => $organization->id,
                'role' => 'owner',
                'active' => true,
                'approved_at' => $user->approved_at ?? now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'email_code_hash' => null,
                'email_code_expires_at' => null,
                'email_code_verified_at' => now(),
                'email_code_sent_at' => null,
                'email_code_attempts' => 0,
                'email_code_last_attempt_at' => null,
                'is_admin' => false,
                'admin_role' => null,
            ]);
            $user->save();

            $organization->primary_user_id = $user->id;
            $organization->active_subscription_id = null;
            $organization->save();

            $plan = $this->ensureEarlyAccessPlan();
            $subscription = $this->provisionSubscription($organization, $workspace, $plan, $signup);

            $organization->active_subscription_id = $subscription->id;
            $organization->save();

            $this->entitlements->refreshForSubscription($subscription);

            $before = [
                'status' => $status->value,
                'activated_user_id' => $signup->activated_user_id,
                'workspace_id' => (string) ($signup->workspace_id ?? ''),
            ];

            $signup->status = EarlyAccessSignupStatus::ACTIVATED;
            $signup->activated_at = now();
            $signup->activated_user_id = $user->id;
            $signup->workspace_id = $workspace->id;
            $signup->rejected_at = null;
            $signup->save();

            $invite->accepted_at = now();
            $invite->save();

            $this->auditLogs->log(
                actor: null,
                subject: $signup,
                action: 'early_access.signup.activated',
                before: $before,
                after: [
                    'status' => $this->statusFor($signup)->value,
                    'activated_user_id' => $user->id,
                    'workspace_id' => (string) $workspace->id,
                    'subscription_id' => (string) $subscription->id,
                    'plan_key' => (string) ($plan->key ?? ''),
                ],
                request: $request
            );

            return $user;
        });

        $workspaceId = (string) Workspace::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('created_at')
            ->value('id');

        $state = $this->onboarding->markRegistered($user, $workspaceId !== '' ? $workspaceId : null);
        $this->onboarding->markVerified($user);
        if (! $state->wasEmailSent('welcome')) {
            SendOnboardingEmailJob::dispatch($user->id, 'welcome');
        }

        return $user;
    }

    private function createOrganizationForSignup(EarlyAccessSignup $signup, EarlyAccessInvite $invite): Organization
    {
        $organization = Organization::query()->create([
            'name' => $this->organizationNameForSignup($signup),
            'slug' => $this->uniqueOrganizationSlug($this->organizationNameForSignup($signup)),
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $invite->invited_by,
        ]);

        return $organization;
    }

    private function createWorkspaceForSignup(EarlyAccessSignup $signup, Organization $organization): Workspace
    {
        $workspaceName = $this->workspaceNameForSignup($signup);

        return Workspace::query()->create([
            'name' => $workspaceName,
            'display_name' => $workspaceName,
            'organization_id' => $organization->id,
        ]);
    }

    private function ensureEarlyAccessPlan(): Plan
    {
        $plan = Plan::query()
            ->where('key', 'early_access')
            ->orWhere('slug', 'early-access')
            ->first();

        if (! $plan) {
            $plan = Plan::query()->create([
                'id' => (string) Str::uuid(),
                'key' => 'early_access',
                'slug' => 'early-access',
                'name' => 'Early Access',
                'description_short' => 'Private early access workspace plan.',
                'interval' => 'month',
                'price_monthly_cents' => 0,
                'monthly_price_cents' => 0,
                'price_cents' => 0,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 300,
                'included_credits_per_interval' => 300,
                'seat_limit' => 5,
                'is_active' => false,
                'is_popular' => false,
                'sort_order' => 999,
                'limits' => [
                    'workspaces' => 1,
                    'sites' => 1,
                    'users' => 5,
                    'included_drafts_per_month' => 20,
                ],
            ]);
        }

        $limits = is_array($plan->limits) ? $plan->limits : [];
        $limits['workspaces'] = 1;
        $limits['sites'] = 1;
        $limits['users'] = 5;
        $limits['included_drafts_per_month'] = (int) ($limits['included_drafts_per_month'] ?? 20);

        $plan->forceFill([
            'seat_limit' => 5,
            'limits' => $limits,
        ])->save();

        if (! $plan->features()->exists()) {
            $templatePlan = Plan::query()
                ->where('key', 'growth')
                ->orWhere('slug', 'growth')
                ->first();

            if ($templatePlan) {
                $templatePlan->loadMissing('features');

                foreach ($templatePlan->features as $feature) {
                    PlanFeature::query()->firstOrCreate(
                        [
                            'plan_id' => $plan->id,
                            'feature_key' => $feature->feature_key,
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'value_type' => $feature->value_type,
                            'value_bool' => $feature->value_bool,
                            'value_int' => $feature->value_int,
                            'value_string' => $feature->value_string,
                            'value_json' => $feature->value_json,
                        ]
                    );
                }
            }
        }

        return $plan;
    }

    private function provisionSubscription(
        Organization $organization,
        Workspace $workspace,
        Plan $plan,
        EarlyAccessSignup $signup
    ): Subscription {
        $subscription = Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'plan_id' => $plan->id,
            'interval' => (string) ($plan->interval ?: 'month'),
            'price_cents' => 0,
            'currency' => (string) ($plan->currency ?: 'EUR'),
            'included_credits_per_interval' => (int) ($plan->included_credits_per_interval ?: $plan->included_credits),
            'seat_limit' => (int) max(1, ($plan->seat_limit ?: data_get($plan->limits, 'users', 1))),
            'status' => 'active',
            'status_reason' => 'early_access',
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => now()->addYear()->endOfDay(),
            'provider' => 'manual',
            'meta' => [
                'source' => 'early_access',
                'early_access_signup_id' => $signup->id,
            ],
        ]);

        return $subscription;
    }

    private function organizationNameForSignup(EarlyAccessSignup $signup): string
    {
        $companyName = trim((string) ($signup->company_name ?? ''));
        if ($companyName !== '') {
            return $companyName;
        }

        return trim((string) ($signup->full_name ?? '')) !== ''
            ? trim((string) $signup->full_name) . ' Workspace'
            : 'Early Access Organization';
    }

    private function workspaceNameForSignup(EarlyAccessSignup $signup): string
    {
        $companyName = trim((string) ($signup->company_name ?? ''));
        if ($companyName !== '') {
            return $companyName;
        }

        return trim((string) ($signup->full_name ?? '')) !== ''
            ? trim((string) $signup->full_name) . ' Workspace'
            : 'Early Access Workspace';
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : 'early-access-' . Str::lower(Str::random(6));
        $candidate = $slug;
        $suffix = 1;

        while (Organization::query()->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $slug . '-' . $suffix;
        }

        return $candidate;
    }

    private function statusFor(EarlyAccessSignup $signup): EarlyAccessSignupStatus
    {
        return $signup->status instanceof EarlyAccessSignupStatus
            ? $signup->status
            : EarlyAccessSignupStatus::from((string) $signup->status);
    }

    private function throwActivationError(string $message): never
    {
        throw ValidationException::withMessages([
            'invite' => $message,
        ]);
    }
}
