<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'connector_installation_id',
    'name',
    'token_hash',
    'abilities',
    'last_used_at',
    'expires_at',
    'revoked_at',
    'created_by',
])]
class ConnectorToken extends Model
{
    use HasFactory;

    public const ABILITIES = [
        'connector:read',
        'connector:write',
        'content:read',
        'content:publish',
        'events:write',
        'health:write',
    ];

    protected static function booted(): void
    {
        static::creating(function (ConnectorToken $token): void {
            $token->uuid ??= (string) Str::uuid();
        });

        static::saving(function (ConnectorToken $token): void {
            if ($token->brand_id !== null) {
                $brand = Brand::query()->find($token->brand_id);

                if (! $brand || $brand->account_id !== $token->account_id) {
                    throw new InvalidArgumentException('Connector token brand must belong to the same account.');
                }
            }

            if ($token->connector_installation_id !== null) {
                $installation = ConnectorInstallation::query()->find($token->connector_installation_id);

                if (! $installation || $installation->account_id !== $token->account_id || $installation->brand_id !== $token->brand_id) {
                    throw new InvalidArgumentException('Connector token installation must belong to the same tenant scope.');
                }
            }

            foreach ($token->abilities ?? [] as $ability) {
                if (! in_array($ability, self::ABILITIES, true)) {
                    throw new InvalidArgumentException("Invalid connector token ability [{$ability}].");
                }
            }
        });
    }

    public static function plainToken(): string
    {
        return 'argusly_ct_'.Str::random(64);
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
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

    /**
     * @return BelongsTo<ConnectorInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(ConnectorInstallation::class, 'connector_installation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
