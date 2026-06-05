<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialDistributionAuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'social_account_id',
        'social_post_variant_id',
        'social_publication_id',
        'actor_id',
        'event',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(SocialPostVariant::class, 'social_post_variant_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(SocialPublication::class, 'social_publication_id');
    }
}
