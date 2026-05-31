<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'source_id',
    'status',
    'started_at',
    'completed_at',
    'records_found',
    'error',
])]
class SourceSync extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const STATUSES = ['planned', 'running', 'completed', 'failed', 'skipped'];

    protected static function booted(): void
    {
        static::creating(function (SourceSync $sync): void {
            $sync->uuid ??= (string) Str::uuid();
            $sync->status ??= 'planned';
        });
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'records_found' => 'integer',
        ];
    }
}
