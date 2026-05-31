<?php

namespace App\Models\Concerns;

use App\Contracts\CurrentAccountContract;
use App\Models\Scopes\CurrentAccountScope;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToAccount
{
    protected static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new CurrentAccountScope);

        static::creating(function ($model): void {
            if ($model->account_id === null) {
                $model->account_id = app(CurrentAccountContract::class)->id();
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->withoutGlobalScope(CurrentAccountScope::class)
            ->where($this->qualifyColumn('account_id'), $accountId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentAccount(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('account_id'), app(CurrentAccountContract::class)->id());
    }
}
