<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    use HasUuids;

    protected $fillable = [
        'plan_id',
        'feature_key',
        'label',
        'feature_group',
        'is_highlight',
        'sort_order',
        'locale',
        'value_type',
        'value_bool',
        'value_int',
        'value_string',
        'value_json',
    ];

    protected $casts = [
        'value_bool' => 'boolean',
        'value_int' => 'integer',
        'value_json' => 'array',
        'is_highlight' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function typedValue(): mixed
    {
        return match ((string) $this->value_type) {
            'int' => $this->value_int,
            'string' => $this->value_string,
            'json' => $this->value_json,
            default => (bool) $this->value_bool,
        };
    }
}
