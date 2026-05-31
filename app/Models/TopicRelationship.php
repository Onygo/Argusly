<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'parent_topic_id',
    'child_topic_id',
    'relationship_type',
])]
class TopicRelationship extends Model
{
    use HasFactory;

    public const TYPES = ['related', 'parent', 'child', 'supports', 'competes'];

    protected static function booted(): void
    {
        static::creating(function (TopicRelationship $relationship): void {
            $relationship->uuid ??= (string) Str::uuid();
        });

        static::saving(function (TopicRelationship $relationship): void {
            if (! in_array($relationship->relationship_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid topic relationship type [{$relationship->relationship_type}].");
            }

            if ($relationship->parent_topic_id === $relationship->child_topic_id) {
                throw new InvalidArgumentException('A topic cannot relate to itself.');
            }

            $parent = Topic::query()->find($relationship->parent_topic_id);
            $child = Topic::query()->find($relationship->child_topic_id);

            if (! $parent || ! $child || $parent->account_id !== $child->account_id) {
                throw new InvalidArgumentException('Related topics must belong to the same account scope.');
            }

            if ($parent->brand_id !== null && $child->brand_id !== null && $parent->brand_id !== $child->brand_id) {
                throw new InvalidArgumentException('Related brand topics must belong to the same brand scope.');
            }
        });
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function parentTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'parent_topic_id');
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function childTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'child_topic_id');
    }
}
