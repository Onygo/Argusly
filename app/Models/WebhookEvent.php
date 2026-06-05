<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payload',
        'headers',
        'source_ip',
        'received_at',
        'handled_at',
        'handler_result',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'received_at' => 'datetime',
        'handled_at' => 'datetime',
        'handler_result' => 'array',
    ];
}
