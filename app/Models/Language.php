<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'native_name',
    'is_ui_enabled',
    'is_content_enabled',
    'is_default',
    'sort_order',
])]
class Language extends Model
{
    public function scopeUiEnabled(Builder $query): Builder
    {
        return $query->where('is_ui_enabled', true);
    }

    public function scopeContentEnabled(Builder $query): Builder
    {
        return $query->where('is_content_enabled', true);
    }

    protected function casts(): array
    {
        return [
            'is_ui_enabled' => 'boolean',
            'is_content_enabled' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
