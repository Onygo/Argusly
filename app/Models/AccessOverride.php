<?php

namespace App\Models;

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\CarbonInterface;

class AccessOverride extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'type',
        'status',
        'starts_at',
        'ends_at',
        'reason',
        'notes',
        'created_by_user_id',
        'ended_by_user_id',
        'ended_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccessOverrideType::class,
            'status' => AccessOverrideStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
            'user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'ended_by_user_id' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AccessOverrideStatus::ACTIVE->value,
            AccessOverrideStatus::SCHEDULED->value,
        ]);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function effectiveStatus(?CarbonInterface $now = null): AccessOverrideStatus
    {
        $now = $now ?: now();
        $storedStatus = $this->status instanceof AccessOverrideStatus
            ? $this->status
            : AccessOverrideStatus::from((string) $this->status);

        if ($storedStatus === AccessOverrideStatus::CANCELLED) {
            return AccessOverrideStatus::CANCELLED;
        }

        if ($storedStatus === AccessOverrideStatus::EXPIRED) {
            return AccessOverrideStatus::EXPIRED;
        }

        if ($this->ends_at && $this->ends_at->lessThanOrEqualTo($now)) {
            return AccessOverrideStatus::EXPIRED;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return AccessOverrideStatus::SCHEDULED;
        }

        return AccessOverrideStatus::ACTIVE;
    }

    public function isBillingBypassActive(?CarbonInterface $now = null): bool
    {
        return $this->effectiveStatus($now) === AccessOverrideStatus::ACTIVE;
    }

    public function uiMessage(?CarbonInterface $now = null): string
    {
        $status = $this->effectiveStatus($now);

        return match ($status) {
            AccessOverrideStatus::ACTIVE => $this->ends_at
                ? sprintf('%s active until %s.', $this->type->label(), $this->ends_at->format('Y-m-d H:i'))
                : sprintf('%s active without an end date.', $this->type->label()),
            AccessOverrideStatus::SCHEDULED => sprintf(
                '%s starts on %s.',
                $this->type->label(),
                optional($this->starts_at)->format('Y-m-d H:i') ?? 'now'
            ),
            AccessOverrideStatus::EXPIRED => sprintf(
                '%s expired%s.',
                $this->type->label(),
                $this->ends_at ? ' on ' . $this->ends_at->format('Y-m-d H:i') : ''
            ),
            AccessOverrideStatus::CANCELLED => sprintf(
                '%s was cancelled%s.',
                $this->type->label(),
                $this->ended_at ? ' on ' . $this->ended_at->format('Y-m-d H:i') : ''
            ),
        };
    }
}
