<?php

namespace App\Services\Subscriptions;

use App\Models\Account;
use App\Models\SubscriptionModule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ModuleAccessService
{
    public function accountHasModule(Account $account, string $moduleKey): bool
    {
        return $this->accountHasModuleId($account->id, $moduleKey);
    }

    public function accountHasModuleId(int $accountId, string $moduleKey): bool
    {
        return $this->accountHasAnyModuleId($accountId, [$moduleKey]);
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function accountHasAnyModule(Account $account, array $moduleKeys): bool
    {
        return $this->accountHasAnyModuleId($account->id, $moduleKeys);
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function accountHasAnyModuleId(int $accountId, array $moduleKeys): bool
    {
        $moduleKeys = $this->normalizeModuleKeys($moduleKeys);

        if ($moduleKeys === []) {
            return false;
        }

        return SubscriptionModule::query()
            ->active()
            ->where('account_id', $accountId)
            ->whereHas('account', fn ($query) => $query->where('status', 'active'))
            ->whereHas('module', fn ($query) => $query->whereIn('key', $moduleKeys)->where('is_active', true))
            ->whereHas('subscription', fn ($query) => $query->active())
            ->exists();
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function userCanAccessAnyModule(User $user, Account $account, array $moduleKeys, string $permission): bool
    {
        return $this->accountHasAnyModule($account, $moduleKeys)
            && Gate::forUser($user)->allows($permission, ['account_id' => $account->id]);
    }

    /**
     * @return array<int, string>
     */
    public function activeModuleKeys(Account $account): array
    {
        return SubscriptionModule::query()
            ->active()
            ->where('account_id', $account->id)
            ->whereHas('subscription', fn ($query) => $query->active())
            ->with('module')
            ->get()
            ->pluck('module.key')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $moduleKeys
     * @return array<int, string>
     */
    private function normalizeModuleKeys(array $moduleKeys): array
    {
        return collect($moduleKeys)
            ->flatMap(fn (string $moduleKey) => preg_split('/[|,]/', $moduleKey) ?: [])
            ->map(fn (string $moduleKey) => trim($moduleKey))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
