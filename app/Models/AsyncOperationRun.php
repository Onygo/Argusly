<?php

namespace App\Models;

use App\Enums\AsyncOperationStatus;
use App\Enums\AsyncOperationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AsyncOperationRun extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'content_destination_id',
        'api_key_id',
        'operation_type',
        'status',
        'resource_type',
        'resource_id',
        'request_payload',
        'result_payload',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'operation_type' => AsyncOperationType::class,
        'status' => AsyncOperationStatus::class,
        'request_payload' => 'array',
        'result_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class);
    }
}
