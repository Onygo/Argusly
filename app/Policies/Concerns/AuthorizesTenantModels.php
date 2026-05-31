<?php

namespace App\Policies\Concerns;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\SourceConnection;
use App\Models\SourceSync;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait AuthorizesTenantModels
{
    protected function allows(User $user, string $permission, ?Model $model = null, bool $requireCurrentBrand = true): bool
    {
        if ($model === null) {
            return Gate::forUser($user)->allows($permission);
        }

        $context = $this->context($model);
        $context['account_id'] ??= app(CurrentAccountContract::class)->id($user);

        if ($context['brand_id'] !== null && $requireCurrentBrand && app(CurrentBrandContract::class)->id($user) !== $context['brand_id']) {
            return false;
        }

        return app(PermissionService::class)->userCan($user, $permission, $context);
    }

    /**
     * @return array{account_id?: int|null, brand_id?: int|null}
     */
    protected function context(Model $model): array
    {
        if ($model instanceof SourceConnection) {
            $model->loadMissing('source');

            return [
                'account_id' => $model->source?->account_id,
                'brand_id' => $model->source?->brand_id,
            ];
        }

        if ($model instanceof SourceSync) {
            $model->loadMissing('source');

            return [
                'account_id' => $model->source?->account_id,
                'brand_id' => $model->source?->brand_id,
            ];
        }

        return [
            'account_id' => $model->getAttribute('account_id'),
            'brand_id' => $model->getAttribute('brand_id'),
        ];
    }
}
