<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'key',
    'name',
    'description',
    'scope',
    'enabled',
    'rules',
    'starts_at',
    'ends_at',
    'created_by',
    'updated_by',
])]
class FeatureFlag extends Model
{
    use HasFactory;

    public const SCOPES = ['platform', 'workspace', 'brand', 'pilot'];

    protected static function booted(): void
    {
        static::creating(function (FeatureFlag $flag): void {
            $flag->uuid ??= (string) Str::uuid();
        });

        static::saving(function (FeatureFlag $flag): void {
            if (! in_array($flag->scope, self::SCOPES, true)) {
                throw new InvalidArgumentException("Invalid feature flag scope [{$flag->scope}].");
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'rules' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
