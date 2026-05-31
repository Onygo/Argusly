<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'mention_id',
    'related_type',
    'related_id',
])]
class MentionRelationship extends Model
{
    /**
     * @return BelongsTo<Mention, $this>
     */
    public function mention(): BelongsTo
    {
        return $this->belongsTo(Mention::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
    }
}
