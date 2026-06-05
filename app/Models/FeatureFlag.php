<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'description',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
