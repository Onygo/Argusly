<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'domain_event_id',
    'event_uuid',
    'projector',
    'status',
    'error',
    'started_at',
    'completed_at',
])]
class DomainEventProjectorRun extends Model
{
    use HasFactory;

    public const STATUSES = ['running', 'completed', 'failed'];

    /**
     * @return BelongsTo<DomainEvent, $this>
     */
    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
