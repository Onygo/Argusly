<?php

namespace App\Services\DraftComparison;

use App\Models\Brief;
use App\Models\DraftComparison;
use App\Models\Workspace;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Entitlements\FeatureGate;
use RuntimeException;

class DraftComparisonFeatureGate
{
    public const FEATURE_ENABLED = 'draft_compare_enabled';

    public const FEATURE_MAX_MODELS = 'draft_compare_max_models';

    public const FEATURE_HYBRID_ENABLED = 'draft_compare_hybrid_enabled';

    public const FEATURE_SCORING_ENABLED = 'draft_compare_scoring_enabled';

    public const FEATURE_PREMIUM_MODELS_ENABLED = 'draft_compare_premium_models_enabled';

    public const FEATURE_WINNER_WEIGHTS = 'draft_compare_winner_weights';

    public function __construct(
        private readonly PlanEntitlementService $planEntitlements,
        private readonly FeatureGate $featureGate,
    ) {
    }

    /**
     * @return array{
     *   enabled:bool,
     *   max_models:int,
     *   hybrid_enabled:bool,
     *   scoring_enabled:bool,
     *   premium_models_enabled:bool,
     *   allowed_modes:array<int,string>,
     *   compare_mode_enabled:bool,
     *   blocked_reason:?string
     * }
     */
    public function capabilitiesForBrief(Brief $brief): array
    {
        $brief->loadMissing('clientSite.workspace');

        return $this->capabilitiesForWorkspace($brief->clientSite?->workspace);
    }

    /**
     * @return array{
     *   enabled:bool,
     *   max_models:int,
     *   hybrid_enabled:bool,
     *   scoring_enabled:bool,
     *   premium_models_enabled:bool,
     *   allowed_modes:array<int,string>,
     *   compare_mode_enabled:bool,
     *   blocked_reason:?string
     * }
     */
    public function capabilitiesForComparison(DraftComparison $comparison): array
    {
        $comparison->loadMissing('brief.clientSite.workspace', 'clientSite.workspace');

        $workspace = $comparison->brief?->clientSite?->workspace ?: $comparison->clientSite?->workspace;

        return $this->capabilitiesForWorkspace($workspace);
    }

    /**
     * @return array{
     *   enabled:bool,
     *   max_models:int,
     *   hybrid_enabled:bool,
     *   scoring_enabled:bool,
     *   premium_models_enabled:bool,
     *   allowed_modes:array<int,string>,
     *   compare_mode_enabled:bool,
     *   blocked_reason:?string
     * }
     */
    public function capabilitiesForWorkspace(?Workspace $workspace): array
    {
        $configuredMax = max(1, (int) config('credits.draft_compare.max_models', 6));
        $absoluteMax = max($configuredMax, (int) config('credits.draft_compare.absolute_max_models', 8));

        $defaults = [
            'enabled' => (bool) config('credits.draft_compare.entitlements.defaults.enabled', true),
            'max_models' => $configuredMax,
            'hybrid_enabled' => (bool) config('credits.draft_compare.entitlements.defaults.hybrid_enabled', true),
            'scoring_enabled' => (bool) config('credits.draft_compare.entitlements.defaults.scoring_enabled', true),
            'premium_models_enabled' => (bool) config('credits.draft_compare.entitlements.defaults.premium_models_enabled', true),
        ];

        if (! $workspace) {
            return $this->normalizeCapabilities($defaults, $absoluteMax);
        }

        $entitlements = $this->planEntitlements->getWorkspaceEntitlements($workspace);

        return $this->normalizeCapabilities([
            'enabled' => $this->toBool(data_get($entitlements, 'draft_compare_enabled'), $defaults['enabled']),
            'max_models' => max(1, (int) data_get($entitlements, 'compare_max_models', $defaults['max_models'])),
            'hybrid_enabled' => $this->toBool(data_get($entitlements, 'hybrid_drafts_enabled'), $defaults['hybrid_enabled']),
            'scoring_enabled' => $this->toBool(data_get($entitlements, 'draft_compare_scoring_enabled'), $defaults['scoring_enabled']),
            'premium_models_enabled' => $this->toBool(data_get($entitlements, 'draft_compare_premium_models_enabled'), $defaults['premium_models_enabled']),
        ], $absoluteMax);
    }

    public function assertCompareEnabledForBrief(Brief $brief): void
    {
        $capabilities = $this->capabilitiesForBrief($brief);

        if (! $capabilities['enabled']) {
            throw new RuntimeException($this->disabledMessage());
        }
    }

    public function assertHybridEnabledForComparison(DraftComparison $comparison): void
    {
        $capabilities = $this->capabilitiesForComparison($comparison);

        if (! $capabilities['enabled']) {
            throw new RuntimeException($this->disabledMessage());
        }

        if (! $capabilities['hybrid_enabled']) {
            throw new RuntimeException('Hybrid draft generation is not available on your current plan. Upgrade to unlock hybrid synthesis.');
        }
    }

    public function scoringEnabledForComparison(DraftComparison $comparison): bool
    {
        return (bool) $this->capabilitiesForComparison($comparison)['scoring_enabled'];
    }

    /**
     * @param array<int,array{key?:string,model?:string}> $options
     * @return array<int,array{key?:string,model?:string,is_premium:bool}>
     */
    public function filterModelOptionsForCapabilities(array $options, array $capabilities): array
    {
        if (! (bool) ($capabilities['enabled'] ?? true)) {
            return [];
        }

        $premiumEnabled = (bool) ($capabilities['premium_models_enabled'] ?? true);

        return collect($options)
            ->map(function (array $option): array {
                $key = (string) ($option['key'] ?? '');
                $model = (string) ($option['model'] ?? '');
                $isPremium = $this->isPremiumModelKey($key !== '' ? $key : $model);

                return array_merge($option, ['is_premium' => $isPremium]);
            })
            ->filter(fn (array $option): bool => $premiumEnabled || ! ((bool) ($option['is_premium'] ?? false)))
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $modelKeys
     */
    public function containsPremiumModelSelection(array $modelKeys): bool
    {
        foreach ($modelKeys as $modelKey) {
            if ($this->isPremiumModelKey((string) $modelKey)) {
                return true;
            }
        }

        return false;
    }

    public function isPremiumModelKey(string $modelKey): bool
    {
        $normalized = strtolower(trim($modelKey));
        if ($normalized === '') {
            return false;
        }

        if (str_contains($normalized, ':')) {
            [, $model] = explode(':', $normalized, 2);
            $normalized = trim($model);
        }

        $patterns = collect((array) config('credits.draft_compare.premium_model_patterns', []))
            ->map(fn (mixed $pattern): string => strtolower(trim((string) $pattern)))
            ->filter()
            ->values();

        if ($patterns->isEmpty()) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $normalized)) {
                    return true;
                }

                continue;
            }

            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function disabledMessage(): string
    {
        return 'Draft Compare is not available on your current plan. Upgrade your package to unlock multi-model comparison.';
    }

    /**
     * Returns optional per-workspace winner-weight overrides.
     *
     * This is intentionally additive and currently unused unless a workspace
     * entitlement/plan feature provides JSON weights. It keeps room for
     * tenant-specific recommendation tuning without changing the core model.
     *
     * @return array<string,float>
     */
    public function winnerWeightsForComparison(DraftComparison $comparison): array
    {
        $comparison->loadMissing('brief.clientSite.workspace', 'clientSite.workspace');
        $workspace = $comparison->brief?->clientSite?->workspace ?: $comparison->clientSite?->workspace;

        $value = $this->featureGate->value($workspace, self::FEATURE_WINNER_WEIGHTS, []);
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $metric => $weight) {
            $metricKey = trim((string) $metric);
            if ($metricKey === '' || ! is_numeric($weight)) {
                continue;
            }

            $normalized[$metricKey] = max(0.0, (float) $weight);
        }

        return $normalized;
    }

    /**
     * @param array{enabled:bool,max_models:int,hybrid_enabled:bool,scoring_enabled:bool,premium_models_enabled:bool} $capabilities
     * @return array{
     *   enabled:bool,
     *   max_models:int,
     *   hybrid_enabled:bool,
     *   scoring_enabled:bool,
     *   premium_models_enabled:bool,
     *   allowed_modes:array<int,string>,
     *   compare_mode_enabled:bool,
     *   blocked_reason:?string
     * }
     */
    private function normalizeCapabilities(array $capabilities, int $absoluteMax): array
    {
        $enabled = (bool) $capabilities['enabled'];

        $rawMaxModels = (int) $capabilities['max_models'];
        if ($rawMaxModels < 0) {
            $rawMaxModels = $absoluteMax;
        }

        $maxModels = max(1, min($absoluteMax, $rawMaxModels));

        $allowedModes = [];
        if ($enabled) {
            if ($maxModels >= 2) {
                $allowedModes[] = 'compare_two';
            }
            if ($maxModels >= 3) {
                $allowedModes[] = 'compare_multi';
            }
        }

        $compareModeEnabled = $enabled && $maxModels >= 2;

        return [
            'enabled' => $enabled,
            'max_models' => $maxModels,
            'hybrid_enabled' => $enabled && (bool) $capabilities['hybrid_enabled'],
            'scoring_enabled' => $enabled && (bool) $capabilities['scoring_enabled'],
            'premium_models_enabled' => $enabled && (bool) $capabilities['premium_models_enabled'],
            'allowed_modes' => $allowedModes,
            'compare_mode_enabled' => $compareModeEnabled,
            'blocked_reason' => $enabled ? null : $this->disabledMessage(),
        ];
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }

            return ! in_array($normalized, ['0', 'false', 'off', 'no'], true);
        }

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }
}
