<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'node_type',
    'source_type',
    'source_id',
    'label',
    'metadata',
])]
class GraphNode extends Model
{
    use HasFactory;

    public const TYPES = [
        'brand',
        'topic',
        'entity',
        'mention',
        'competitor',
        'contact',
        'organization',
        'creator',
        'campaign',
        'content',
        'narrative',
        'recommendation',
        'agent',
    ];

    protected static function booted(): void
    {
        static::creating(function (GraphNode $node): void {
            $node->uuid ??= (string) Str::uuid();
        });

        static::saving(function (GraphNode $node): void {
            if (! in_array($node->node_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid graph node type [{$node->node_type}].");
            }

            if ($node->brand_id !== null) {
                $brand = Brand::query()->find($node->brand_id);

                if (! $brand || $brand->account_id !== $node->account_id) {
                    throw new InvalidArgumentException('Graph node brand must belong to the same account.');
                }
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(GraphEdge::class, 'source_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(GraphEdge::class, 'target_node_id');
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand): Builder
    {
        return $query->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $scope) => $scope->where(fn (Builder $brandScope) => $brandScope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $scope) => $scope->whereNull('brand_id'),
            );
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
