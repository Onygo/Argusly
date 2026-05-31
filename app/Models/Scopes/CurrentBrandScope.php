<?php

namespace App\Models\Scopes;

use App\Contracts\CurrentBrandContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CurrentBrandScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $brandId = app(CurrentBrandContract::class)->id();

        if ($brandId !== null) {
            $builder->where($model->qualifyColumn('brand_id'), $brandId);

            return;
        }

        if (! app()->runningInConsole()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
