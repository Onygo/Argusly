<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'source_id',
    'integration_connection_id',
    'status',
    'settings',
])]
class SourceConnection extends Model
{
    use HasFactory;

    public const STATUSES = ['configured', 'active', 'paused', 'error'];

    protected static function booted(): void
    {
        static::creating(function (SourceConnection $connection): void {
            $connection->uuid ??= (string) Str::uuid();
            $connection->status ??= 'configured';
        });

        static::saving(function (SourceConnection $connection): void {
            if (! in_array($connection->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid source connection status [{$connection->status}].");
            }

            if ($connection->integration_connection_id !== null) {
                $source = Source::query()->find($connection->source_id);
                $integration = IntegrationConnection::query()->with('integration')->find($connection->integration_connection_id);

                if (! $source || ! $integration) {
                    throw new InvalidArgumentException('Source connection endpoints must exist.');
                }

                if ($integration->account_id !== null && $source->account_id !== null && $integration->account_id !== $source->account_id) {
                    throw new InvalidArgumentException('Integration connection must belong to the same account as the source.');
                }

                if ($integration->brand_id !== null && $source->brand_id !== null && $integration->brand_id !== $source->brand_id) {
                    throw new InvalidArgumentException('Integration connection must belong to the same brand as the source.');
                }

                if ($integration->integration?->key !== $source->provider) {
                    throw new InvalidArgumentException('Integration connection provider must match the source provider.');
                }
            }
        });
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
