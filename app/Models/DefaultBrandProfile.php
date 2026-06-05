<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DefaultBrandProfile extends Model
{
    protected $fillable = [
        'name',
        'tone',
        'style_rules',
    ];

    protected $casts = [
        'style_rules' => 'array',
    ];
}
