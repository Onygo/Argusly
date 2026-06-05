<?php

namespace App\Services;

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Domain\AccessOverrides\AccessOverrideResolver;
use App\Services\OrganizationAccessService;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class SubscriptionService
{
    public function __construct(
        private readonly AccessOverrideResolver $overrides,
        private readonly OrganizationAccessService $access,
    ) {
    }

    public function getActiveForOrganization(Organization $organization): ?Subscription
    {
        if ($organization->active_subscription_id) {
            $subscription = Subscription::query()
                ->whereKey($organization->active_subscription_id)
                ->first();

            if ($subscription && $this->isSubscriptionActive($subscription)) {
                return $subscription;
            }
        }

        return Subscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('updated_at')
            ->first();
    }

    public function getCurrentForOrganization(Organization $organization): ?Subscription
    {
        if ($organization->active_subscription_id) {
            $subscription = Subscription::query()
                ->whereKey($organization->active_subscription_id)
                ->first();

            if ($subscription && $this->isSubscriptionCurrent($subscription)) {
                return $subscription;
            }
        }

        return Subscription::query()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $query): void {
                $query->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due']);
            })
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'trialing' THEN 2 WHEN 'pending_mandate' THEN 3 WHEN 'past_due' THEN 4 ELSE 9 END")
            ->orderByDesc('updated_at')
            ->first();
    }

    public function isOrganizationActiveCustomer(Organization $organization): bool
    {
        return $this->getActiveForOrganization($organization) !== null
            || $this->access->isEarlyBirdActive($organization);
    }

    public function hasBillingAccessForUser(User $user): bool
    {
        $organization = $user->organization;

        if (! $organization) {
            return false;
        }

        return $this->getActiveForOrganization($organization) !== null
            || $this->access->isEarlyBirdActive($organization)
            || $this->allowsBillingBypassForUser($user);
    }

    public function allowsBillingBypassForUser(?User $user): bool
    {
        if (! $user || $user->is_admin) {
            return false;
        }

        return $this->overrides->allowsBillingBypass($user);
    }

    public function assertOrganizationCanBuyCredits(
        Organization $organization,
        User|int|string|null $actor = null,
    ): ?Subscription
    {
        $subscription = $this->getActiveForOrganization($organization);

        if (! $subscription) {
            if ($this->access->isEarlyBirdActive($organization)) {
                return null;
            }

            $actorUser = $this->resolveActor($actor, $organization);
            if ($actorUser && $this->allowsBillingBypassForUser($actorUser)) {
                return null;
            }

            throw new RuntimeException('Active plan subscription required before buying credit packs.');
        }

        return $subscription;
    }

    public function assertClientSiteCanUseGeneration(
        string $clientSiteId,
        User|int|string|null $actor = null,
    ): ?Subscription
    {
        $site = ClientSite::query()->with('workspace.organization')->find($clientSiteId);
        if (! $site || ! $site->workspace || ! $site->workspace->organization) {
            throw new RuntimeException('Unable to resolve organization for client site.');
        }

        $subscription = $this->getActiveForOrganization($site->workspace->organization);

        if (! $subscription) {
            if ($this->access->isEarlyBirdActive($site->workspace->organization)) {
                return null;
            }

            $actorUser = $this->resolveActor($actor, $site->workspace->organization);
            if ($actorUser && $this->allowsBillingBypassForUser($actorUser)) {
                return null;
            }

            throw new RuntimeException('Generation requires an active plan subscription.');
        }

        return $subscription;
    }

    public function assertSeatLimitAvailable(
        Organization $organization,
        User|int|string|null $actor = null,
    ): void
    {
        $subscription = $this->assertOrganizationCanBuyCredits($organization, $actor);

        if (! $subscription) {
            return;
        }

        $seatLimit = (int) ($subscription->seat_limit ?? 0);
        if ($seatLimit <= 0) {
            $seatLimit = 1;
        }

        $activeUsers = (int) $organization->users()->where('active', true)->count();

        if ($activeUsers >= $seatLimit) {
            throw new RuntimeException('Seat limit reached for the active plan. Upgrade plan to add users.');
        }
    }

    public function syncOrganizationActiveSubscription(Organization $organization): ?Subscription
    {
        $active = $this->getCurrentForOrganization($organization);

        if ((string) ($organization->active_subscription_id ?? '') !== (string) ($active?->id ?? '')) {
            $organization->active_subscription_id = $active?->id;
            $organization->save();
        }

        return $active;
    }

    public function isSubscriptionActive(Subscription $subscription): bool
    {
        if (! in_array((string) $subscription->status, ['active', 'trialing'], true)) {
            return false;
        }

        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            return false;
        }

        return true;
    }

    public function isSubscriptionCurrent(Subscription $subscription): bool
    {
        if (! in_array((string) $subscription->status, ['active', 'trialing', 'pending_mandate', 'past_due'], true)) {
            return false;
        }

        if ($subscription->status === 'canceled') {
            return false;
        }

        return true;
    }

    private function resolveActor(User|int|string|null $actor, ?Organization $organization = null): ?User
    {
        if ($actor instanceof User) {
            return $organization && (int) $actor->organization_id !== (int) $organization->id
                ? null
                : $actor;
        }

        if ($actor === null || $actor === '') {
            return null;
        }

        $query = User::query()->whereKey((int) $actor);

        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        return $query->first();
    }
}
