<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\LlmSetting;
use InvalidArgumentException;

class LlmSettingsService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertGlobal(array $attributes): LlmSetting
    {
        return $this->upsert(null, null, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertAccount(Account $account, array $attributes): LlmSetting
    {
        return $this->upsert($account, null, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertBrand(Account $account, Brand $brand, array $attributes): LlmSetting
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('LLM setting brand must belong to the account.');
        }

        return $this->upsert($account, $brand, $attributes);
    }

    public function settingFor(?Account $account = null, ?Brand $brand = null): ?LlmSetting
    {
        $account ??= $brand?->account;

        if ($brand !== null && $account !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('LLM setting brand must belong to the account.');
        }

        if ($brand !== null) {
            $setting = LlmSetting::query()
                ->where('account_id', $brand->account_id)
                ->where('brand_id', $brand->id)
                ->first();

            if ($setting !== null) {
                return $setting;
            }
        }

        if ($account !== null) {
            $setting = LlmSetting::query()
                ->where('account_id', $account->id)
                ->whereNull('brand_id')
                ->first();

            if ($setting !== null) {
                return $setting;
            }
        }

        return LlmSetting::query()
            ->whereNull('account_id')
            ->whereNull('brand_id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(?Account $account, ?Brand $brand, array $attributes): LlmSetting
    {
        return LlmSetting::query()->updateOrCreate(
            [
                'account_id' => $account?->id,
                'brand_id' => $brand?->id,
            ],
            $attributes,
        );
    }
}
