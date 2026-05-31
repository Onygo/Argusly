<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mention_id',
    'entity_name',
    'entity_type',
])]
class MentionEntity extends Model
{
    /**
     * @return BelongsTo<Mention, $this>
     */
    public function mention(): BelongsTo
    {
        return $this->belongsTo(Mention::class);
    }
}
