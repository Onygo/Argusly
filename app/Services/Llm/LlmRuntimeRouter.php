<?php

namespace App\Services\Llm;

use App\Data\Llm\LlmRequest;
use App\Models\Account;
use App\Models\Brand;
use App\Services\LlmResolver;

class LlmRuntimeRouter
{
    public function __construct(private readonly LlmResolver $resolver) {}

    public function route(LlmRequest $request, string $type = 'chat'): LlmRequest
    {
        $account = $this->account($request);
        $brand = $this->brand($request, $account);

        $shouldResolve = ($request->metadata['route_with_settings'] ?? false) === true
            || in_array($request->provider, ['auto', 'tenant', 'settings'], true)
            || in_array($request->model, ['auto', 'tenant', 'settings'], true);

        if (! $shouldResolve) {
            return $request;
        }

        $resolved = $this->resolver->resolve($account, $brand, $type);

        $provider = $shouldResolve ? $resolved['provider']['provider'] : $request->provider;
        $model = $shouldResolve ? $resolved['model']['model'] : $request->model;

        return $request->withRuntime(
            provider: $provider,
            model: $model,
            temperature: $request->temperature ?? (is_numeric($resolved['temperature']) ? (float) $resolved['temperature'] : null),
            maxTokens: $request->maxTokens ?? (is_numeric($resolved['max_tokens']) ? (int) $resolved['max_tokens'] : null),
            metadata: [
                ...($request->metadata ?? []),
                'llm_source' => $resolved['source'],
                'fallback_provider' => $request->metadata['fallback_provider'] ?? ($resolved['fallback_provider']['provider'] ?? null),
                'fallback_model' => $request->metadata['fallback_model'] ?? ($resolved['fallback_model']['model'] ?? null),
                'llm_policy' => $resolved['settings'] ?? null,
            ],
        );
    }

    private function account(LlmRequest $request): ?Account
    {
        $accountId = $request->metadata['account_id'] ?? null;

        return is_numeric($accountId) ? Account::query()->find((int) $accountId) : null;
    }

    private function brand(LlmRequest $request, ?Account $account): ?Brand
    {
        $brandId = $request->metadata['brand_id'] ?? null;

        if (! is_numeric($brandId) || ! $account) {
            return null;
        }

        return Brand::query()
            ->where('account_id', $account->id)
            ->find((int) $brandId);
    }
}
