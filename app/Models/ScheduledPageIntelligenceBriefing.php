<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceGraphEdgeType;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceStage;
use App\Support\Intelligence\TimeWindow;
use App\Support\Intelligence\TimeWindowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ScheduledPageIntelligenceBriefing extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'report_type',
        'market_pack_key',
        'frequency',
        'day_of_week',
        'day_of_month',
        'timezone',
        'recipients_json',
        'delivery_channels_json',
        'delivery_state_json',
        'is_active',
        'last_generated_at',
        'last_failed_at',
        'last_error',
        'failure_count',
        'next_run_at',
        'scheduler_claimed_at',
        'scheduler_claim_expires_at',
        'scheduler_claim_token',
        'created_by',
    ];

    protected $casts = [
        'recipients_json' => 'array',
        'delivery_channels_json' => 'array',
        'delivery_state_json' => 'array',
        'is_active' => 'boolean',
        'last_generated_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'failure_count' => 'integer',
        'next_run_at' => 'datetime',
        'scheduler_claimed_at' => 'datetime',
        'scheduler_claim_expires_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function generatedReports(): HasMany
    {
        return $this->hasMany(PageIntelligenceReport::class, 'scheduled_page_intelligence_briefing_id')
            ->latest('generated_at');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PageIntelligenceReportDelivery::class, 'scheduled_briefing_id')
            ->latest('created_at');
    }

    public function calculateNextRunAt(?Carbon $after = null): Carbon
    {
        $timezone = $this->validTimezone();
        $local = ($after ? $after->copy() : now())->timezone($timezone);

        if ($this->frequency === self::FREQUENCY_MONTHLY) {
            $day = max(1, min(31, (int) ($this->day_of_month ?: 1)));
            $candidate = $this->monthlyCandidate($local, $day);

            if ($candidate->lessThanOrEqualTo($local)) {
                $candidate = $this->monthlyCandidate($local->copy()->addMonthNoOverflow(), $day);
            }

            return $candidate->timezone('UTC');
        }

        $day = max(0, min(6, (int) ($this->day_of_week ?? 1)));
        $candidate = $local->copy()->startOfDay()->addDays(($day - $local->dayOfWeek + 7) % 7);

        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate->addWeek();
        }

        return $candidate->timezone('UTC');
    }

    /**
     * @return array{period_start:string,period_end:string}
     */
    public function reportPeriodForRun(?Carbon $runAt = null): array
    {
        $timezone = $this->validTimezone();
        $run = ($runAt ? $runAt->copy() : ($this->next_run_at?->copy() ?? now()))->timezone($timezone)->startOfDay();
        $periodEnd = $run->copy()->subDay()->endOfDay();
        $periodStart = $this->frequency === self::FREQUENCY_MONTHLY
            ? $run->copy()->subMonthNoOverflow()->startOfDay()
            : $periodEnd->copy()->subDays(6)->startOfDay();
        $window = (new TimeWindowFactory())->custom($periodStart, $periodEnd, $timezone);

        return [
            'period_start' => $window->start->toDateString(),
            'period_end' => $window->end->toDateString(),
        ];
    }

    public function reportTimeWindowForRun(?Carbon $runAt = null): TimeWindow
    {
        $period = $this->reportPeriodForRun($runAt);

        return (new TimeWindowFactory())->custom(
            $period['period_start'],
            $period['period_end'],
            $this->validTimezone(),
        );
    }

    public function evidenceBag(?Carbon $runAt = null): EvidenceBag
    {
        $window = $this->reportTimeWindowForRun($runAt);

        return new EvidenceBag([
            EvidenceReference::briefing(
                $this->briefingKey(),
                $this->report_type,
                timeWindow: $window,
                metadata: [
                    'report_type' => $this->report_type,
                    'frequency' => $this->frequency,
                    'market_pack_key' => $this->market_pack_key,
                    'timezone' => $this->timezone,
                    'is_active' => $this->is_active,
                    'next_run_at' => $this->next_run_at?->toDateTimeString(),
                    'created_by' => $this->created_by,
                ],
            ),
        ]);
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::briefing($this->briefingKey(), $this->report_type, [
            'report_type' => $this->report_type,
            'frequency' => $this->frequency,
            'market_pack_key' => $this->market_pack_key,
            'is_active' => $this->is_active,
        ]);
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function toGraphEdges(?Carbon $runAt = null): array
    {
        $source = $this->toGraphReference();
        $bag = $this->evidenceBag($runAt);
        $reports = $this->relationLoaded('generatedReports')
            ? $this->generatedReports
            : ($this->exists ? $this->generatedReports()->get() : collect());

        return $reports
            ->filter(fn (mixed $report): bool => $report instanceof PageIntelligenceReport)
            ->map(fn (PageIntelligenceReport $report): IntelligenceGraphEdge => new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::REPORTS,
                $source,
                $report->toGraphReference(),
                evidence: $bag->toEvidence(),
                timeWindow: $this->reportTimeWindowForRun($runAt),
                metadata: [
                    'briefing_id' => $this->briefingKey(),
                    'report_type' => $this->report_type,
                    'frequency' => $this->frequency,
                ],
                stage: IntelligenceStage::INSIGHT,
            ))
            ->values()
            ->all();
    }

    public function idempotencyKeyForPeriod(string $periodStart, string $periodEnd): string
    {
        return implode(':', [
            'scheduled-page-intelligence',
            (string) $this->id,
            (string) $this->frequency,
            $periodStart,
            $periodEnd,
        ]);
    }

    private function briefingKey(): string
    {
        $key = $this->getKey();

        if ($key !== null && trim((string) $key) !== '') {
            return (string) $key;
        }

        return 'unsaved:'.sha1(json_encode([
            'workspace_id' => $this->workspace_id,
            'client_site_id' => $this->client_site_id,
            'report_type' => $this->report_type,
            'frequency' => $this->frequency,
            'market_pack_key' => $this->market_pack_key,
            'next_run_at' => $this->next_run_at?->toDateTimeString(),
        ], JSON_THROW_ON_ERROR));
    }

    private function monthlyCandidate(Carbon $date, int $day): Carbon
    {
        $candidate = $date->copy()->startOfMonth();

        return $candidate->day(min($day, $candidate->daysInMonth))->startOfDay();
    }

    private function validTimezone(): string
    {
        $timezone = trim((string) $this->timezone);

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
