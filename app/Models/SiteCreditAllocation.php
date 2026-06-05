<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteCreditAllocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'allocated_credits',
        'reserved_cached',
        'used_cached',
        'created_by_user_id',
        'updated_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'allocated_credits' => 'integer',
        'reserved_cached' => 'integer',
        'used_cached' => 'integer',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SiteCreditAllocationLog::class, 'client_site_id', 'client_site_id');
    }

    public function buckets(): HasMany
    {
        return $this->hasMany(SiteCreditAllocationBucket::class, 'site_credit_allocation_id');
    }

    public function getRemainingAttribute(): int
    {
        return (int) $this->allocated_credits - (int) $this->reserved_cached;
    }
}
