<?php

namespace App\Services;

use App\Domain\AccessOverrides\AccessOverrideManager;
use App\Enums\AccessOverrideType;
use App\Enums\EarlyAccessSignupStatus;
use App\Models\AccessOverride;
use App\Models\EarlyAccessSignup;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\EntitlementRefreshService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExistingPilotUserService
{
    public function __construct(
        private readonly AccessOverrideManager $accessOverrides,
        private readonly AuditLogService $auditLogs,
        private readonly EntitlementRefreshService $entitlements,
        private readonly PilotQualificationService $qualification,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function add(array $payload, ?User $actor = null, ?Request $request = null): EarlyAccessSignup
    {
        return DB::transaction(function () use ($payload, $actor, $request): EarlyAccessSignup {
            $user = $this->existingUserForEmail((string) $payload['email']);

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => 'No existing user found for this email address.',
                ]);
            }

            if ($user->is_admin && ! $actor?->isSuperadmin()) {
                throw ValidationException::withMessages([
                    'email' => 'Only a superadmin can add an admin user to pilot participation.',
                ]);
            }

            $organization = $user->organization()->lockForUpdate()->first();

            if (! $organization) {
                throw ValidationException::withMessages([
                    'email' => 'This user is not linked to an organization yet.',
                ]);
            }

            $workspace = $this->workspaceFor($organization, $payload);
            $signup = $this->upsertSignup($user, $organization, $workspace, $payload, $actor);
            $this->ensureAccessOverride($user, $payload, $actor, $request);
            $this->ensurePilotSubscriptionIfNeeded($organization, $workspace, $signup);

            $this->auditLogs->log(
                actor: $actor,
                subject: $signup,
                action: 'early_access.existing_user.added',
                before: null,
                after: [
                    'activated_user_id' => $user->id,
                    'workspace_id' => (string) $workspace->id,
                    'organization_id' => $organization->id,
                    'email' => $user->email,
                ],
                request: $request
            );

            return $signup->refresh();
        });
    }

    private function existingUserForEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->first();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function workspaceFor(Organization $organization, array $payload): Workspace
    {
        $workspaceId = trim((string) ($payload['workspace_id'] ?? ''));

        if ($workspaceId !== '') {
            $workspace = Workspace::query()
                ->where('organization_id', $organization->id)
                ->whereKey($workspaceId)
                ->first();

            if (! $workspace) {
                throw ValidationException::withMessages([
                    'workspace_id' => 'Selected workspace does not belong to this user organization.',
                ]);
            }

            return $workspace;
        }

        $workspace = Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->first();

        if ($workspace) {
            return $workspace;
        }

        return Workspace::query()->create([
            'organization_id' => $organization->id,
            'name' => $organization->name,
            'display_name' => $organization->name,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function upsertSignup(User $user, Organization $organization, Workspace $workspace, array $payload, ?User $actor): EarlyAccessSignup
    {
        $signup = EarlyAccessSignup::query()
            ->where(function ($query) use ($user): void {
                $query->where('activated_user_id', $user->id)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower((string) $user->email)]);
            })
            ->where('status', EarlyAccessSignupStatus::ACTIVATED->value)
            ->latest('activated_at')
            ->first();

        $notes = trim((string) ($payload['notes'] ?? ''));
        $data = [
            'full_name' => trim((string) $user->name) ?: (string) $user->email,
            'email' => strtolower((string) $user->email),
            'company_name' => $organization->name,
            'notes' => $notes !== '' ? $notes : null,
            'source' => 'admin_existing_user',
            'priority' => 'high',
            'qualification_score' => $this->qualification->score([
                'full_name' => $user->name,
                'email' => $user->email,
                'company_name' => $organization->name,
                'source' => 'admin_existing_user',
                'priority' => 'high',
            ]),
            'assigned_admin_id' => $actor?->id,
            'status' => EarlyAccessSignupStatus::ACTIVATED,
            'submitted_at' => $signup?->submitted_at ?? now(),
            'reviewed_at' => $signup?->reviewed_at ?? now(),
            'approved_at' => $signup?->approved_at ?? now(),
            'activated_at' => $signup?->activated_at ?? now(),
            'activated_user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'rejected_at' => null,
        ];

        if ($signup) {
            $signup->forceFill($data)->save();

            return $signup;
        }

        return EarlyAccessSignup::query()->create($data);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function ensureAccessOverride(User $user, array $payload, ?User $actor, ?Request $request): void
    {
        $openOverride = AccessOverride::query()
            ->forUser($user)
            ->open()
            ->first();

        if ($openOverride) {
            return;
        }

        $this->accessOverrides->createForUser(
            targetUser: $user,
            payload: [
                'type' => AccessOverrideType::EARLY_ACCESS->value,
                'starts_at' => now()->format('Y-m-d H:i:s'),
                'ends_at' => $payload['ends_at'] ?? null,
                'reason' => 'Existing user added to Pilot Program.',
                'notes' => $payload['notes'] ?? null,
                'metadata' => ['source' => 'existing_pilot_user'],
            ],
            actor: $actor,
            request: $request,
        );
    }

    private function ensurePilotSubscriptionIfNeeded(Organization $organization, Workspace $workspace, EarlyAccessSignup $signup): void
    {
        $activeSubscription = Subscription::query()
            ->where(function ($query) use ($organization, $workspace): void {
                $query->where('organization_id', $organization->id)
                    ->orWhere('workspace_id', $workspace->id);
            })
            ->whereIn('status', ['active', 'trialing'])
            ->latest('updated_at')
            ->first();

        if ($activeSubscription) {
            $this->entitlements->refreshForWorkspace($workspace, $activeSubscription);

            return;
        }

        $plan = $this->ensureEarlyAccessPlan();

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
            'status_reason' => 'early_access_existing_user',
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => now()->addYear()->endOfDay(),
            'provider' => 'manual',
            'meta' => [
                'source' => 'early_access_existing_user',
                'early_access_signup_id' => $signup->id,
            ],
        ]);

        if (! $organization->active_subscription_id) {
            $organization->active_subscription_id = $subscription->id;
            $organization->save();
        }

        $this->entitlements->refreshForSubscription($subscription);
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
                'limits' => ['workspaces' => 1, 'sites' => 1, 'users' => 5, 'included_drafts_per_month' => 20],
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
                foreach ($templatePlan->features()->get() as $feature) {
                    PlanFeature::query()->firstOrCreate(
                        ['plan_id' => $plan->id, 'feature_key' => $feature->feature_key],
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
}
