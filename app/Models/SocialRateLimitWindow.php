<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialRateLimitWindow extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'social_account_id',
        'platform',
        'bucket',
        'request_limit',
        'remaining',
        'resets_at',
        'limited_until',
        'metadata',
    ];

    protected $casts = [
        'platform' => SocialPlatform::class,
        'request_limit' => 'integer',
        'remaining' => 'integer',
        'resets_at' => 'datetime',
        'limited_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
