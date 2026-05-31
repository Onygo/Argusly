<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'key',
    'type',
    'name',
    'description',
    'homepage_url',
    'documentation_url',
    'status',
    'is_system',
    'metadata',
])]
class ConnectorManifest extends Model
{
    use HasFactory;

    public const TYPES = [
        'wordpress',
        'laravel',
        'api',
        'webhook',
        'headless',
        'shopify',
        'webflow',
        'ghost',
    ];

    protected static function booted(): void
    {
        static::creating(function (ConnectorManifest $manifest): void {
            $manifest->uuid ??= (string) Str::uuid();
            $manifest->status ??= 'active';
        });
    }

    /**
     * @return HasMany<ConnectorVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ConnectorVersion::class);
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

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
