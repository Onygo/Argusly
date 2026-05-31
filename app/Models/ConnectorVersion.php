<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'connector_manifest_id',
    'version',
    'status',
    'minimum_argusly_version',
    'checksum',
    'release_notes',
    'config_schema',
    'api_schema',
    'metadata',
])]
class ConnectorVersion extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<ConnectorManifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ConnectorManifest::class, 'connector_manifest_id');
    }

    /**
     * @return HasMany<ConnectorCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(ConnectorCapability::class);
    }

    /**
     * @return HasMany<ConnectorInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(ConnectorInstallation::class);
    }

    /**
     * @param  Builder<ConnectorVersion>  $query
     * @return Builder<ConnectorVersion>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'config_schema' => 'array',
            'api_schema' => 'array',
            'metadata' => 'array',
        ];
    }
}
