<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use App\Services\OrganizationAccessService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Cache;

class PlanEntitlementService
{
    public const FEATURE_DRAFT_COMPARE_ENABLED = 'draft_compare_enabled';

    public const FEATURE_DRAFT_COMPARE_MAX_MODELS = 'draft_compare_max_models';

    public const FEATURE_DRAFT_COMPARE_HYBRID_ENABLED = 'draft_compare_hybrid_enabled';

    public const FEATURE_DRAFT_COMPARE_SCORING_ENABLED = 'draft_compare_scoring_enabled';

    public const FEATURE_DRAFT_COMPARE_PREMIUM_MODELS_ENABLED = 'draft_compare_premium_models_enabled';

    public const FEATURE_TRANSLATION_ENABLED = 'translation_enabled';

    public const FEATURE_CREDIT_PACK_ACCESS = 'credit_pack_purchase_enabled';

    public function __construct(
        private readonly FeatureGate $featureGate,
        private readonly SubscriptionService $subscriptions,
        private readonly OrganizationAccessService $access,
    ) {
    }

    /**
     * @return array{
     *   workspace_id:string,
     *   subscription_id:?string,
     *   plan_key:?string,
     *   plan_name:?string,
     *   compare_max_models:int,
     *   hybrid_drafts_enabled:bool,
     *   monthly_credits:int,
     *   credit_pack_access:bool,
     *   translation_enabled:bool,
     *   draft_compare_enabled:bool,
     *   draft_compare_scoring_enabled:bool,
     *   draft_compare_premium_models_enabled:bool,
     *   refreshed_at:string
     * }
     */
    public function getWorkspaceEntitlements(Workspace $workspace): array
    {
        $ttlSeconds = max(0, (int) config('billing.entitlements.cache_ttl_seconds', 0));

        if ($ttlSeconds <= 0) {
            return $this->resolveEntitlements($workspace);
        }

        return Cache::remember(
            $this->cacheKey((string) $workspace->id),
            now()->addSeconds($ttlSeconds),
            fn (): array => $this->resolveEntitlements($workspace->fresh(['organization']) ?? $workspace),
        );
    }

    public function forgetWorkspace(Workspace|string $workspace): void
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;

        if ($workspaceId === '') {
            return;
        }

        Cache::forget($this->cacheKey($workspaceId));
    }

    private function cacheKey(string $workspaceId): string
    {
        return sprintf('billing:workspace:%s:entitlements:v1', $workspaceId);
    }

    /**
     * @return array{
     *   workspace_id:string,
     *   subscription_id:?string,
     *   plan_key:?string,
     *   plan_name:?string,
     *   compare_max_models:int,
     *   hybrid_drafts_enabled:bool,
     *   monthly_credits:int,
     *   credit_pack_access:bool,
     *   translation_enabled:bool,
     *   draft_compare_enabled:bool,
     *   draft_compare_scoring_enabled:bool,
     *   draft_compare_premium_models_enabled:bool,
     *   refreshed_at:string
     * }
     */
    private function resolveEntitlements(Workspace $workspace): array
    {
        $workspace->loadMissing('organization');

        $subscription = $this->resolveSubscription($workspace);
        $earlyBird = $workspace->organization ? $this->access->isEarlyBirdActive($workspace->organization) : false;
        $earlyBirdEntitlements = $earlyBird && $workspace->organization
            ? $this->access->earlyBirdEntitlements($workspace->organization)
            : [];
        $plan = $subscription?->plan;

        $defaultMax = max(1, (int) config('credits.draft_compare.max_models', 6));
        $absoluteMax = max($defaultMax, (int) config('credits.draft_compare.absolute_max_models', 8));

        $compareEnabledDefault = (bool) config('credits.draft_compare.entitlements.defaults.enabled', true);
        $hybridEnabledDefault = (bool) config('credits.draft_compare.entitlements.defaults.hybrid_enabled', true);
        $scoringEnabledDefault = (bool) config('credits.draft_compare.entitlements.defaults.scoring_enabled', true);
        $premiumEnabledDefault = (bool) config('credits.draft_compare.entitlements.defaults.premium_models_enabled', true);

        $compareEnabled = $this->toBool(
            $this->featureGate->value($workspace, self::FEATURE_DRAFT_COMPARE_ENABLED, $compareEnabledDefault),
            $compareEnabledDefault,
        );

        $compareMaxModels = max(
            1,
            min(
                $absoluteMax,
                (int) ($this->featureGate->value($workspace, self::FEATURE_DRAFT_COMPARE_MAX_MODELS, $defaultMax) ?: $defaultMax),
            ),
        );

        $hybridEnabled = $this->toBool(
            $this->featureGate->value($workspace, self::FEATURE_DRAFT_COMPARE_HYBRID_ENABLED, $hybridEnabledDefault),
            $hybridEnabledDefault,
        );

        $scoringEnabled = $this->toBool(
            $this->featureGate->value($workspace, self::FEATURE_DRAFT_COMPARE_SCORING_ENABLED, $scoringEnabledDefault),
            $scoringEnabledDefault,
        );

        $premiumEnabled = $this->toBool(
            $this->featureGate->value($workspace, self::FEATURE_DRAFT_COMPARE_PREMIUM_MODELS_ENABLED, $premiumEnabledDefault),
            $premiumEnabledDefault,
        );

        $creditPackAccess = $this->toBool(
            $this->featureGate->value($workspace, self::FEATURE_CREDIT_PACK_ACCESS, $subscription !== null),
            $subscription !== null,
        );

        $translationEnabled = $this->toBool(
            $this->featureGate->value($workspace, self::FEATURE_TRANSLATION_ENABLED, true),
            true,
        );

        return [
            'workspace_id' => (string) $workspace->id,
            'subscription_id' => $subscription ? (string) $subscription->id : null,
            'plan_key' => $earlyBird
                ? (string) ($earlyBirdEntitlements['plan_key'] ?? 'early_bird')
                : ($plan ? (string) ($plan->key ?: $plan->slug) : null),
            'plan_name' => $earlyBird
                ? (string) ($earlyBirdEntitlements['plan_name'] ?? 'Early Bird')
                : ($plan ? (string) $plan->name : null),
            'compare_max_models' => $compareMaxModels,
            'hybrid_drafts_enabled' => $compareEnabled && $hybridEnabled,
            'monthly_credits' => $earlyBird
                ? max(0, (int) ($earlyBirdEntitlements['included_credits'] ?? 0))
                : max(0, (int) ($plan?->monthlyCredits() ?? $subscription?->included_credits_per_interval ?? 0)),
            'credit_pack_access' => $creditPackAccess,
            'translation_enabled' => $translationEnabled,
            'draft_compare_enabled' => $compareEnabled,
            'draft_compare_scoring_enabled' => $compareEnabled && $scoringEnabled,
            'draft_compare_premium_models_enabled' => $compareEnabled && $premiumEnabled,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    private function resolveSubscription(Workspace $workspace): ?Subscription
    {
        $byWorkspace = Subscription::query()
            ->with('plan')
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('updated_at')
            ->first();

        if ($byWorkspace) {
            return $byWorkspace;
        }

        if (! $workspace->organization) {
            return null;
        }

        return $this->subscriptions->getActiveForOrganization($workspace->organization)?->loadMissing('plan');
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));

        return ! in_array($normalized, ['', '0', 'false', 'off', 'no'], true);
    }
}
