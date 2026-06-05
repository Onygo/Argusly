<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CreditAction extends Model
{
    use HasUuids;

    protected $table = 'credit_actions';

    protected $fillable = [
        'key',
        'category',
        'credits_cost',
        'label_nl',
        'label_en',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'credits_cost' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}
