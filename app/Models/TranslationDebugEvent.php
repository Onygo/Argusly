<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationDebugEvent extends Model
{
    protected $fillable = [
        'trace_id',
        'content_id',
        'locale',
        'event_type',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
