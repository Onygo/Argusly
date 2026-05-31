<?php

namespace App\Models\Concerns;

use App\Contracts\CurrentBrandContract;
use App\Models\Scopes\CurrentBrandScope;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToBrand
{
    protected static function bootBelongsToBrand(): void
    {
        static::addGlobalScope(new CurrentBrandScope);

        static::creating(function ($model): void {
            if ($model->brand_id === null) {
                $model->brand_id = app(CurrentBrandContract::class)->id();
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBrand(Builder $query, int $brandId): Builder
    {
        return $query->withoutGlobalScope(CurrentBrandScope::class)
            ->where($this->qualifyColumn('brand_id'), $brandId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('brand_id'), app(CurrentBrandContract::class)->id());
    }
}
