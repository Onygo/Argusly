<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceCreditWallet extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'balance_cached',
        'reserved_cached',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'balance_cached' => 'integer',
        'reserved_cached' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WorkspaceCreditTransaction::class);
    }

    public function getAvailableAttribute(): int
    {
        return (int) $this->balance_cached - (int) $this->reserved_cached;
    }
}
