<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceGraphEdgeType;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceStage;
use App\Support\Intelligence\TimeWindow;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageIntelligenceReport extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_GENERATED = 'generated';

    public const ARTIFACT_TYPE_PDF = 'pdf';
    public const ARTIFACT_STATUS_PENDING = 'pending';
    public const ARTIFACT_STATUS_GENERATING = 'generating';
    public const ARTIFACT_STATUS_READY = 'ready';
    public const ARTIFACT_STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'market_pack_id',
        'market_pack_key',
        'report_type',
        'identity_hash',
        'idempotency_key',
        'title',
        'status',
        'snapshot_version',
        'template_version',
        'period_start',
        'period_end',
        'summary',
        'payload_json',
        'provenance_json',
        'generated_by',
        'generated_at',
        'artifact_type',
        'artifact_storage_path',
        'artifact_status',
        'artifact_generated_at',
        'artifact_checksum',
        'artifact_source_checksum',
        'artifact_failed_at',
        'artifact_error',
        'artifact_attempt_count',
        'scheduled_page_intelligence_briefing_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'snapshot_version' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'payload_json' => 'array',
        'provenance_json' => 'array',
        'generated_at' => 'datetime',
        'artifact_generated_at' => 'datetime',
        'artifact_failed_at' => 'datetime',
        'artifact_attempt_count' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class, 'market_pack_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scheduledBriefing(): BelongsTo
    {
        return $this->belongsTo(ScheduledPageIntelligenceBriefing::class, 'scheduled_page_intelligence_briefing_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PageIntelligenceReportDelivery::class, 'report_id')
            ->latest('created_at');
    }

    public function timeWindow(): ?TimeWindow
    {
        if (! $this->period_start || ! $this->period_end) {
            return null;
        }

        return TimeWindow::between($this->period_start, $this->period_end);
    }

    public function evidenceBag(): EvidenceBag
    {
        $window = $this->timeWindow();
        $references = [
            EvidenceReference::report(
                $this->reportKey(),
                $this->title,
                timeWindow: $window,
                metadata: [
                    'report_type' => $this->report_type,
                    'status' => $this->status,
                    'market_pack_key' => $this->market_pack_key,
                    'snapshot_version' => $this->snapshot_version,
                    'template_version' => $this->template_version,
                    'artifact_status' => $this->artifact_status,
                ],
                provenance: (array) $this->provenance_json,
            ),
        ];

        if ($this->scheduled_page_intelligence_briefing_id) {
            $references[] = EvidenceReference::briefing(
                $this->scheduled_page_intelligence_briefing_id,
                timeWindow: $window,
                metadata: [
                    'relationship' => 'scheduled_source',
                    'report_id' => $this->reportKey(),
                ],
            );
        }

        $references = array_merge(
            $references,
            $this->referencesFromEvidenceLinks($window),
            $this->referencesFromSourceRowIds($window),
        );

        return new EvidenceBag($references, metadata: [
            'report_id' => $this->reportKey(),
            'report_type' => $this->report_type,
            'evidence_link_count' => count((array) data_get($this->payload_json, 'evidence_links', [])),
        ]);
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::report($this->reportKey(), $this->title, [
            'report_type' => $this->report_type,
            'status' => $this->status,
            'market_pack_key' => $this->market_pack_key,
            'snapshot_version' => $this->snapshot_version,
        ]);
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function toGraphEdges(): array
    {
        $target = $this->toGraphReference();
        $bag = $this->evidenceBag();
        $edges = [];

        foreach ($bag->references as $reference) {
            $source = $reference->toGraphReference();

            if ($source->graphKey() === $target->graphKey()) {
                continue;
            }

            $edges[] = new IntelligenceGraphEdge(
                $reference->type === EvidenceReference::TYPE_BRIEFING
                    ? IntelligenceGraphEdgeType::REPORTS
                    : IntelligenceGraphEdgeType::EVIDENCES,
                $source,
                $target,
                confidence: $reference->confidence,
                evidence: $bag->toEvidence(),
                timeWindow: $this->timeWindow(),
                metadata: [
                    'report_id' => $this->reportKey(),
                    'report_type' => $this->report_type,
                    'snapshot_version' => $this->snapshot_version,
                ],
                provenance: (array) $this->provenance_json,
                stage: IntelligenceStage::INSIGHT,
            );
        }

        return collect($edges)
            ->unique(fn (IntelligenceGraphEdge $edge): string => $edge->key())
            ->values()
            ->all();
    }

    private function reportKey(): string
    {
        $key = $this->getKey();

        if ($key !== null && trim((string) $key) !== '') {
            return (string) $key;
        }

        return 'unsaved:'.sha1(json_encode([
            'identity_hash' => $this->identity_hash,
            'title' => $this->title,
            'report_type' => $this->report_type,
            'period_start' => $this->period_start?->toDateTimeString(),
            'period_end' => $this->period_end?->toDateTimeString(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int, EvidenceReference>
     */
    private function referencesFromEvidenceLinks(?TimeWindow $window): array
    {
        $references = [];

        foreach ((array) data_get($this->payload_json, 'evidence_links', []) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $metadata = ['evidence_link' => $link];
            $label = $this->stringValue($link['label'] ?? $link['title'] ?? $link['canonical_url'] ?? null);
            $pageId = $this->stringValue($link['page_id'] ?? null);

            if ($pageId !== null) {
                $references[] = EvidenceReference::resource(
                    'monitored_page',
                    $pageId,
                    $label,
                    timeWindow: $window,
                    metadata: $metadata,
                    stage: IntelligenceStage::RAW_OBSERVATION,
                );
            }

            $sourceId = $this->stringValue($link['source_id'] ?? null);
            $sourceModel = $this->stringValue($link['source_model'] ?? null);

            if ($sourceId !== null && $sourceModel !== null) {
                $references[] = EvidenceReference::pageIntelligenceInput(
                    str($sourceModel)->afterLast('\\')->snake()->toString(),
                    $sourceId,
                    $label,
                    timeWindow: $window,
                    metadata: $metadata + [
                        'source_model' => $sourceModel,
                    ],
                );
            }
        }

        return $references;
    }

    /**
     * @return array<int, EvidenceReference>
     */
    private function referencesFromSourceRowIds(?TimeWindow $window): array
    {
        $references = [];

        foreach ((array) data_get($this->provenance_json, 'source_row_ids', []) as $source => $ids) {
            foreach ((array) $ids as $id) {
                $id = $this->stringValue($id);

                if ($id === null) {
                    continue;
                }

                $references[] = match ($source) {
                    'marketing_observations' => EvidenceReference::marketingObservation($id, timeWindow: $window),
                    'page_snapshots' => EvidenceReference::pageSnapshot($id, timeWindow: $window),
                    'page_scores' => EvidenceReference::resource(EvidenceReference::TYPE_PAGE_SCORE, $id, timeWindow: $window),
                    'trends' => EvidenceReference::resource(EvidenceReference::TYPE_TREND, $id, timeWindow: $window),
                    'performance_signals', 'performance_signal_keys' => EvidenceReference::performanceSignal($id, timeWindow: $window),
                    default => EvidenceReference::pageIntelligenceInput((string) $source, $id, timeWindow: $window),
                };
            }
        }

        return $references;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
