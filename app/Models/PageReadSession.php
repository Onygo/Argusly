<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageReadSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'analytics_site_id',
        'url',
        'url_key',
        'session_id',
        'read_seconds',
        'created_at',
    ];

    protected $casts = [
        'read_seconds' => 'integer',
        'created_at' => 'datetime',
    ];
}
