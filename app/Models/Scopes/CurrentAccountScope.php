<?php

namespace App\Models\Scopes;

use App\Contracts\CurrentAccountContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CurrentAccountScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $accountId = app(CurrentAccountContract::class)->id();

        if ($accountId !== null) {
            $builder->where($model->qualifyColumn('account_id'), $accountId);

            return;
        }

        if (! app()->runningInConsole()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
