<?php

namespace App\Services\Llm;

use App\Data\Llm\LlmRequest;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\LlmBudgetExceededException;
use App\Exceptions\LlmPolicyException;
use App\Models\Account;
use App\Models\Brand;
use App\Models\CreditTransaction;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\LlmSetting;
use App\Services\CreditService;
use App\Services\LlmSettingsService;
use Illuminate\Database\Eloquent\Builder;

class LlmRuntimeGuard
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly LlmSettingsService $settings,
    ) {}

    public function ensureAllowed(LlmRequest $request): void
    {
        $account = $this->account($request);
        $brand = $this->brand($request, $account);
        $setting = $this->settings->settingFor($account, $brand);
        $policy = $setting?->settings ?? [];

        $this->ensureProviderAndModelPolicy($request, $policy);
        $this->ensureActiveCatalogEntries($request);
        $this->ensureCreditBudget($request, $account, $brand, $setting);
    }

    private function ensureProviderAndModelPolicy(LlmRequest $request, array $policy): void
    {
        $allowedProviders = $this->list($policy['allowed_providers'] ?? null);
        $deniedProviders = $this->list($policy['denied_providers'] ?? null);
        $allowedModels = $this->list($policy['allowed_models'] ?? null);
        $deniedModels = $this->list($policy['denied_models'] ?? null);

        if ($allowedProviders !== [] && ! in_array($request->provider, $allowedProviders, true)) {
            throw new LlmPolicyException("LLM provider [{$request->provider}] is not allowed for this tenant.");
        }

        if (in_array($request->provider, $deniedProviders, true)) {
            throw new LlmPolicyException("LLM provider [{$request->provider}] is denied for this tenant.");
        }

        if ($allowedModels !== [] && ! in_array($request->model, $allowedModels, true)) {
            throw new LlmPolicyException("LLM model [{$request->model}] is not allowed for this tenant.");
        }

        if (in_array($request->model, $deniedModels, true)) {
            throw new LlmPolicyException("LLM model [{$request->model}] is denied for this tenant.");
        }
    }

    private function ensureActiveCatalogEntries(LlmRequest $request): void
    {
        $provider = LlmProvider::query()
            ->where('provider', $request->provider)
            ->first();

        if (! $provider) {
            return;
        }

        if ($provider->status !== 'active') {
            throw new LlmPolicyException("LLM provider [{$request->provider}] is not active.");
        }

        $model = LlmModel::query()
            ->where('provider_id', $provider->id)
            ->where('model', $request->model)
            ->first();

        if ($model && $model->status !== 'active') {
            throw new LlmPolicyException("LLM model [{$request->model}] is not active.");
        }
    }

    private function ensureCreditBudget(LlmRequest $request, ?Account $account, ?Brand $brand, ?LlmSetting $setting): void
    {
        if (($request->metadata['credits_precharged'] ?? false) === true) {
            return;
        }

        if (! $account) {
            return;
        }

        $costKey = config("llm.credit_cost_keys.{$this->purpose($request)}");

        if (! is_string($costKey) || $costKey === '') {
            return;
        }

        $required = $this->credits->cost($costKey);
        $available = $this->credits->balance($account);

        if ($available < $required) {
            throw new InsufficientCreditsException($required, $available);
        }

        $policy = $setting?->settings ?? [];
        $this->ensureScopedBudget('workspace', $policy['monthly_credit_budget'] ?? null, $account, null, null, $required);
        $this->ensureScopedBudget('brand', $policy['brand_monthly_credit_budget'] ?? null, $account, $brand, null, $required);
        $this->ensureScopedBudget('user', $policy['user_monthly_credit_budget'] ?? null, $account, $brand, $request->metadata['user_id'] ?? null, $required);
    }

    private function ensureScopedBudget(string $scope, mixed $budget, Account $account, ?Brand $brand, mixed $userId, int $required): void
    {
        if (! is_numeric($budget) || (int) $budget <= 0) {
            return;
        }

        $budget = (int) $budget;
        $used = (int) CreditTransaction::query()
            ->where('account_id', $account->id)
            ->where('amount', '<', 0)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNotNull('metadata->llm_request_id')
            ->when($brand, fn (Builder $query) => $query->where('metadata->brand_id', $brand->id))
            ->when(is_numeric($userId), fn (Builder $query) => $query->where('user_id', (int) $userId))
            ->sum('amount');

        $used = abs($used);

        if (($used + $required) > $budget) {
            throw new LlmBudgetExceededException($scope, $budget, $used, $required);
        }
    }

    private function account(LlmRequest $request): ?Account
    {
        $accountId = $request->metadata['account_id'] ?? null;

        return is_numeric($accountId) ? Account::query()->find((int) $accountId) : null;
    }

    private function brand(LlmRequest $request, ?Account $account): ?Brand
    {
        $brandId = $request->metadata['brand_id'] ?? null;

        if (! $account || ! is_numeric($brandId)) {
            return null;
        }

        return Brand::query()->where('account_id', $account->id)->find((int) $brandId);
    }

    private function purpose(LlmRequest $request): string
    {
        $purpose = $request->metadata['purpose'] ?? null;

        return is_string($purpose) ? $purpose : 'agent_task';
    }

    /**
     * @return array<int, string>
     */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)->filter(fn ($item) => is_string($item) && $item !== '')->values()->all();
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
