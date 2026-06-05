<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublishAttempt extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'social_post_id',
        'status',
        'request_payload',
        'response_payload',
        'response_status',
        'error_message',
        'attempted_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'response_status' => 'integer',
        'attempted_at' => 'datetime',
    ];

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }
}
