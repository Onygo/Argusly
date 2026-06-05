<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageScrollEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'analytics_site_id',
        'url',
        'url_key',
        'session_id',
        'depth',
        'created_at',
    ];

    protected $casts = [
        'depth' => 'integer',
        'created_at' => 'datetime',
    ];
}
