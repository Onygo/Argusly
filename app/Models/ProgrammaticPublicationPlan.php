<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ProgrammaticPublicationPlan extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULING = 'scheduling';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const CADENCE_MANUAL = 'manual';
    public const CADENCE_DAILY = 'daily';
    public const CADENCE_EVERY_2_DAYS = 'every_2_days';
    public const CADENCE_WEEKLY = 'weekly';
    public const CADENCE_CUSTOM_INTERVAL_DAYS = 'custom_interval_days';

    protected $fillable = [
        'workspace_id',
        'growth_program_id',
        'name',
        'description',
        'status',
        'planned_start_at',
        'planned_end_at',
        'cadence',
        'destination_id',
        'total_items',
        'approved_items',
        'scheduled_items',
        'published_items',
        'metadata',
    ];

    protected $casts = [
        'planned_start_at' => 'datetime',
        'planned_end_at' => 'datetime',
        'total_items' => 'integer',
        'approved_items' => 'integer',
        'scheduled_items' => 'integer',
        'published_items' => 'integer',
        'metadata' => 'array',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_APPROVED,
            self::STATUS_SCHEDULING,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHING,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    public static function cadences(): array
    {
        return [
            self::CADENCE_MANUAL,
            self::CADENCE_DAILY,
            self::CADENCE_EVERY_2_DAYS,
            self::CADENCE_WEEKLY,
            self::CADENCE_CUSTOM_INTERVAL_DAYS,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function growthProgram(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(ContentDestination::class, 'destination_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProgrammaticPublicationPlanItem::class);
    }

    public function approve(): self
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            throw new InvalidArgumentException('Only draft publication plans can be approved.');
        }

        $this->forceFill(['status' => self::STATUS_APPROVED])->save();
        $this->items()->where('status', ProgrammaticPublicationPlanItem::STATUS_PLANNED)->update(['status' => ProgrammaticPublicationPlanItem::STATUS_APPROVED]);

        return $this->refreshCounters();
    }

    public function cancel(): self
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException('Completed or cancelled publication plans cannot be cancelled again.');
        }

        $this->forceFill(['status' => self::STATUS_CANCELLED])->save();
        $this->items()->whereNotIn('status', [
            ProgrammaticPublicationPlanItem::STATUS_PUBLISHED,
            ProgrammaticPublicationPlanItem::STATUS_CANCELLED,
        ])->update(['status' => ProgrammaticPublicationPlanItem::STATUS_CANCELLED]);

        return $this->refresh();
    }

    public function refreshCounters(): self
    {
        $items = $this->items()->get();
        $plannedDates = $items->whereNotNull('planned_publish_at');

        $this->forceFill([
            'total_items' => $items->count(),
            'approved_items' => $items->where('status', ProgrammaticPublicationPlanItem::STATUS_APPROVED)->count(),
            'scheduled_items' => $items->where('status', ProgrammaticPublicationPlanItem::STATUS_SCHEDULED)->count(),
            'published_items' => $items->where('status', ProgrammaticPublicationPlanItem::STATUS_PUBLISHED)->count(),
            'planned_start_at' => $plannedDates->min('planned_publish_at') ?: $this->planned_start_at,
            'planned_end_at' => $plannedDates->max('planned_publish_at') ?: $this->planned_end_at,
        ])->save();

        return $this->refresh();
    }
}
