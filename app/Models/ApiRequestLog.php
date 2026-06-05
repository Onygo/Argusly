<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'api_key_id',
        'user_id',
        'method',
        'path',
        'ip_address',
        'user_agent',
        'response_status',
        'credits_reserved',
        'credits_used',
        'duration_ms',
        'requested_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'credits_reserved' => 'integer',
        'credits_used' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
