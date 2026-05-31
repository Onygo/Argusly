<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'connector_installation_id',
    'account_id',
    'brand_id',
    'level',
    'event',
    'status',
    'message',
    'context',
    'occurred_at',
])]
class ConnectorLog extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ConnectorLog $log): void {
            if ($log->connector_installation_id === null) {
                return;
            }

            $installation = ConnectorInstallation::query()->find($log->connector_installation_id);

            if (! $installation || $installation->account_id !== $log->account_id || $installation->brand_id !== $log->brand_id) {
                throw new InvalidArgumentException('Connector log installation must belong to the same tenant.');
            }
        });
    }

    /**
     * @return BelongsTo<ConnectorInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(ConnectorInstallation::class, 'connector_installation_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
