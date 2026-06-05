<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentDestinationSyncAttempt extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'content_destination_id',
        'content_id',
        'content_publish_target_id',
        'sync_type',
        'trigger_source',
        'status',
        'attempt',
        'request_url',
        'idempotency_key',
        'request_headers',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'error_message',
        'started_at',
        'delivered_at',
        'failed_at',
        'next_retry_at',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'response_headers' => 'array',
        'started_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function contentPublishTarget()
    {
        return $this->belongsTo(ContentPublishTarget::class);
    }
}
