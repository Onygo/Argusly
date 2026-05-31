<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'connector_manifest_id',
    'connector_version_id',
    'capability',
    'is_enabled',
    'settings',
])]
class ConnectorCapability extends Model
{
    use HasFactory;

    public const CAPABILITIES = [
        'receive_content',
        'publish_content',
        'update_content',
        'delete_content',
        'sync_content',
        'sync_taxonomies',
        'sync_authors',
        'health_check',
        'webhooks',
        'media_upload',
        'preview_url',
    ];

    protected static function booted(): void
    {
        static::saving(function (ConnectorCapability $capability): void {
            if (! in_array($capability->capability, self::CAPABILITIES, true)) {
                throw new InvalidArgumentException('Unsupported connector capability.');
            }

            if ($capability->connector_version_id === null) {
                return;
            }

            $version = ConnectorVersion::query()->find($capability->connector_version_id);

            if (! $version || $version->connector_manifest_id !== $capability->connector_manifest_id) {
                throw new InvalidArgumentException('Connector capability version must belong to the same manifest.');
            }
        });
    }

    /**
     * @return BelongsTo<ConnectorManifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ConnectorManifest::class, 'connector_manifest_id');
    }

    /**
     * @return BelongsTo<ConnectorVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ConnectorVersion::class, 'connector_version_id');
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }
}
