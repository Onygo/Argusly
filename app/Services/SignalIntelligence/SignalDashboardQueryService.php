<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\ClientSite;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SignalDashboardQueryService
{
    /**
     * @return array<string,mixed>
     */
    public function filters(Request $request, Workspace $workspace): array
    {
        $dateFrom = $this->parseDate($request->query('date_from'));
        $dateTo = $this->parseDate($request->query('date_to'), endOfDay: true);

        return [
            'workspace' => (string) $workspace->id,
            'site' => $this->stringOrNull($request->query('site')),
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'category' => $this->stringOrNull($request->query('category')),
            'type' => $this->stringOrNull($request->query('type')),
            'source_type' => $this->stringOrNull($request->query('source_type')),
            'status' => $this->stringOrNull($request->query('status')),
            'severity' => $this->stringOrNull($request->query('severity')),
            'confidence_min' => $this->numericOrNull($request->query('confidence_min')),
            'score_min' => $this->numericOrNull($request->query('score_min')),
            'entity_name' => $this->stringOrNull($request->query('entity_name')),
            'topic' => $this->stringOrNull($request->query('topic')),
            '_date_from_at' => $dateFrom,
            '_date_to_at' => $dateTo,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function dashboard(Workspace $workspace, array $filters): array
    {
        $detectionsBase = $this->detectionsQuery($workspace, $filters);
        $eventsBase = $this->eventsQuery($workspace, $filters);
        $detectionMetrics = $this->detectionMetrics($detectionsBase);
        $categorySummaries = $this->categorySummaries($workspace, $filters);

        return [
            'metrics' => [
                'events' => (clone $eventsBase)->count(),
                'open_detections' => $detectionMetrics['open_detections'],
                'high_priority' => $detectionMetrics['high_priority'],
                'risks' => $detectionMetrics['risks'],
                'opportunities' => $detectionMetrics['opportunities'],
                'avg_priority' => $detectionMetrics['avg_priority'],
            ],
            'recentEvents' => (clone $eventsBase)
                ->with(['signalSource', 'clientSite'])
                ->orderByDesc('observed_at')
                ->limit(25)
                ->get(),
            'detections' => (clone $detectionsBase)
                ->with(['clientSite'])
                ->orderByDesc('priority_score')
                ->orderByDesc('last_seen_at')
                ->paginate(15)
                ->withQueryString(),
            'openDetections' => (clone $detectionsBase)
                ->open()
                ->orderByDesc('priority_score')
                ->limit(10)
                ->get(),
            'highPriorityDetections' => (clone $detectionsBase)
                ->open()
                ->where('priority_score', '>=', 75)
                ->orderByDesc('priority_score')
                ->limit(10)
                ->get(),
            'brandSummary' => $categorySummaries[SignalDetection::CATEGORY_BRAND_MONITORING],
            'competitorSummary' => $categorySummaries[SignalDetection::CATEGORY_COMPETITOR_MONITORING],
            'trendSummary' => $categorySummaries[SignalDetection::CATEGORY_TREND_DETECTION],
            'riskSummary' => $categorySummaries[SignalDetection::CATEGORY_RISK_DETECTION],
            'opportunityCandidates' => (clone $detectionsBase)
                ->open()
                ->where(function (Builder $query): void {
                    $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                        ->orWhere('opportunity_score', '>=', 70);
                })
                ->orderByDesc('opportunity_score')
                ->limit(10)
                ->get(),
            'sites' => ClientSite::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('name')
                ->get(['id', 'name', 'site_url']),
            'filterOptions' => [
                'categories' => SignalDetection::categories(),
                'event_categories' => SignalCategory::values(),
                'types' => collect(array_merge(SignalType::values(), $this->knownDetectionTypes($workspace)->all()))->unique()->sort()->values()->all(),
                'source_types' => SignalSourceType::values(),
                'statuses' => SignalStatus::values(),
                'severities' => SignalSeverity::values(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Builder<SignalDetection>
     */
    public function detectionsQuery(Workspace $workspace, array $filters): Builder
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['site'] ?? null, fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category): Builder => $query->where('category', $category))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity): Builder => $query->where('severity', $severity))
            ->when($filters['confidence_min'] ?? null, fn (Builder $query, float $score): Builder => $query->where('confidence_score', '>=', $score))
            ->when($filters['score_min'] ?? null, fn (Builder $query, float $score): Builder => $query->where('priority_score', '>=', $score))
            ->when($filters['_date_from_at'] ?? null, fn (Builder $query, Carbon $from): Builder => $query->where('last_seen_at', '>=', $from))
            ->when($filters['_date_to_at'] ?? null, fn (Builder $query, Carbon $to): Builder => $query->where('first_seen_at', '<=', $to))
            ->when($filters['entity_name'] ?? null, function (Builder $query, string $entity): Builder {
                return $query->where(function (Builder $nested) use ($entity): void {
                    $nested->where('primary_entity', 'like', '%'.$entity.'%')
                        ->orWhereHas('events', fn (Builder $events): Builder => $events->where('entity_name', 'like', '%'.$entity.'%'));
                });
            })
            ->when($filters['topic'] ?? null, function (Builder $query, string $topic): Builder {
                return $query->where(function (Builder $nested) use ($topic): void {
                    $nested->where('primary_topic', 'like', '%'.$topic.'%')
                        ->orWhereHas('events', fn (Builder $events): Builder => $events->where('topic', 'like', '%'.$topic.'%'));
                });
            })
            ->when($filters['source_type'] ?? null, function (Builder $query, string $sourceType): Builder {
                return $query->whereHas('events.signalSource', fn (Builder $source): Builder => $source->where('type', $sourceType));
            });
    }

    /**
     * @param array<string,mixed> $filters
     * @return Builder<SignalEvent>
     */
    public function eventsQuery(Workspace $workspace, array $filters): Builder
    {
        return SignalEvent::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['site'] ?? null, fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category): Builder => $query->where('category', $category))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($filters['source_type'] ?? null, function (Builder $query, string $sourceType): Builder {
                return $query->whereHas('signalSource', fn (Builder $source): Builder => $source->where('type', $sourceType));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity): Builder => $query->where('severity', $severity))
            ->when($filters['confidence_min'] ?? null, fn (Builder $query, float $score): Builder => $query->where('confidence_score', '>=', $score))
            ->when($filters['score_min'] ?? null, fn (Builder $query, float $score): Builder => $query->where('signal_strength', '>=', $score))
            ->when($filters['_date_from_at'] ?? null, fn (Builder $query, Carbon $from): Builder => $query->where('observed_at', '>=', $from))
            ->when($filters['_date_to_at'] ?? null, fn (Builder $query, Carbon $to): Builder => $query->where('observed_at', '<=', $to))
            ->when($filters['entity_name'] ?? null, fn (Builder $query, string $entity): Builder => $query->where('entity_name', 'like', '%'.$entity.'%'))
            ->when($filters['topic'] ?? null, fn (Builder $query, string $topic): Builder => $query->where('topic', 'like', '%'.$topic.'%'));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function categorySummaries(Workspace $workspace, array $filters): array
    {
        $categories = [
            SignalDetection::CATEGORY_BRAND_MONITORING,
            SignalDetection::CATEGORY_COMPETITOR_MONITORING,
            SignalDetection::CATEGORY_TREND_DETECTION,
            SignalDetection::CATEGORY_RISK_DETECTION,
        ];
        $highSeverities = [SignalSeverity::HIGH->value, SignalSeverity::CRITICAL->value];
        $baseFilters = array_merge($filters, ['category' => null]);

        $aggregates = $this->detectionsQuery($workspace, $baseFilters)
            ->whereIn('category', $categories)
            ->select('category')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status NOT IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as open_count', $this->terminalStatuses())
            ->selectRaw('SUM(CASE WHEN severity IN (?, ?) THEN 1 ELSE 0 END) as high_count', $highSeverities)
            ->selectRaw('AVG(priority_score) as avg_priority')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return collect($categories)
            ->mapWithKeys(function (string $category) use ($aggregates, $workspace, $baseFilters): array {
                $row = $aggregates->get($category);
                $query = $this->detectionsQuery($workspace, array_merge($baseFilters, ['category' => $category]));

                return [
                    $category => [
                        'total' => (int) ($row?->total ?? 0),
                        'open' => (int) ($row?->open_count ?? 0),
                        'high' => (int) ($row?->high_count ?? 0),
                        'avg_priority' => round((float) ($row?->avg_priority ?? 0), 1),
                        'latest' => (clone $query)->orderByDesc('last_seen_at')->limit(5)->get(),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int,string>
     */
    private function knownDetectionTypes(Workspace $workspace): Collection
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('type')
            ->distinct()
            ->pluck('type');
    }

    /**
     * @param Builder<SignalDetection> $query
     * @return array{open_detections:int,high_priority:int,risks:int,opportunities:int,avg_priority:float}
     */
    private function detectionMetrics(Builder $query): array
    {
        $row = (clone $query)
            ->selectRaw('SUM(CASE WHEN status NOT IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as open_detections', $this->terminalStatuses())
            ->selectRaw('SUM(CASE WHEN status NOT IN (?, ?, ?, ?) AND priority_score >= 75 THEN 1 ELSE 0 END) as high_priority_count', $this->terminalStatuses())
            ->selectRaw('SUM(CASE WHEN category = ? THEN 1 ELSE 0 END) as risk_count', [SignalDetection::CATEGORY_RISK_DETECTION])
            ->selectRaw(
                'SUM(CASE WHEN status NOT IN (?, ?, ?, ?) AND (category = ? OR opportunity_score >= 70) THEN 1 ELSE 0 END) as opportunity_count',
                array_merge($this->terminalStatuses(), [SignalDetection::CATEGORY_OPPORTUNITY_DETECTION])
            )
            ->selectRaw('AVG(priority_score) as avg_priority')
            ->first();

        return [
            'open_detections' => (int) ($row?->open_detections ?? 0),
            'high_priority' => (int) ($row?->high_priority_count ?? 0),
            'risks' => (int) ($row?->risk_count ?? 0),
            'opportunities' => (int) ($row?->opportunity_count ?? 0),
            'avg_priority' => round((float) ($row?->avg_priority ?? 0), 1),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function terminalStatuses(): array
    {
        return [
            SignalStatus::PUBLISHED->value,
            SignalStatus::DISMISSED->value,
            SignalStatus::RESOLVED->value,
            SignalStatus::ARCHIVED->value,
        ];
    }

    private function parseDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
