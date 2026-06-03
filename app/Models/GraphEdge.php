<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'source_node_id',
    'target_node_id',
    'relationship_type',
    'strength',
    'confidence',
    'metadata',
])]
class GraphEdge extends Model
{
    use HasFactory;

    public const TYPES = [
        'mentions',
        'related_to',
        'supports',
        'competes_with',
        'created_by',
        'belongs_to',
        'targets',
        'influences',
        'owns',
        'participates_in',
        'covers',
        'connected_to',
        'recommended_by',
        'detected_in',
    ];

    protected static function booted(): void
    {
        static::creating(function (GraphEdge $edge): void {
            $edge->uuid ??= (string) Str::uuid();
        });

        static::saving(function (GraphEdge $edge): void {
            if (! in_array($edge->relationship_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid graph relationship type [{$edge->relationship_type}].");
            }

            if ($edge->source_node_id === $edge->target_node_id) {
                throw new InvalidArgumentException('Graph edge endpoints must be different.');
            }

            $source = GraphNode::query()->find($edge->source_node_id);
            $target = GraphNode::query()->find($edge->target_node_id);

            if (! $source || ! $target || $source->account_id !== $edge->account_id || $target->account_id !== $edge->account_id) {
                throw new InvalidArgumentException('Graph edge endpoints must belong to the same account.');
            }

            foreach ([$source, $target] as $node) {
                if ($edge->brand_id !== null && $node->brand_id !== null && $node->brand_id !== $edge->brand_id) {
                    throw new InvalidArgumentException('Graph edge endpoints must stay inside the same brand scope.');
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

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(GraphNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(GraphNode::class, 'target_node_id');
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
            'strength' => 'float',
            'confidence' => 'float',
            'metadata' => 'array',
        ];
    }
}
