<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiWebhookDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'api_webhook_id',
        'workspace_id',
        'event_type',
        'event_id',
        'attempt',
        'request_headers',
        'request_body',
        'response_status',
        'response_body',
        'delivered_at',
        'failed_at',
        'next_retry_at',
        'error_message',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function webhook()
    {
        return $this->belongsTo(ApiWebhook::class, 'api_webhook_id');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
