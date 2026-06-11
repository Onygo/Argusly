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

        return [
            'metrics' => [
                'events' => (clone $eventsBase)->count(),
                'open_detections' => (clone $detectionsBase)->open()->count(),
                'high_priority' => (clone $detectionsBase)->open()->where('priority_score', '>=', 75)->count(),
                'risks' => (clone $detectionsBase)->where('category', SignalDetection::CATEGORY_RISK_DETECTION)->count(),
                'opportunities' => (clone $detectionsBase)
                    ->open()
                    ->where(function (Builder $query): void {
                        $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                            ->orWhere('opportunity_score', '>=', 70);
                    })
                    ->count(),
                'avg_priority' => round((float) ((clone $detectionsBase)->avg('priority_score') ?? 0), 1),
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
            'brandSummary' => $this->summaryForCategory($workspace, $filters, SignalDetection::CATEGORY_BRAND_MONITORING),
            'competitorSummary' => $this->summaryForCategory($workspace, $filters, SignalDetection::CATEGORY_COMPETITOR_MONITORING),
            'trendSummary' => $this->summaryForCategory($workspace, $filters, SignalDetection::CATEGORY_TREND_DETECTION),
            'riskSummary' => $this->summaryForCategory($workspace, $filters, SignalDetection::CATEGORY_RISK_DETECTION),
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
    private function summaryForCategory(Workspace $workspace, array $filters, string $category): array
    {
        $query = $this->detectionsQuery($workspace, array_merge($filters, ['category' => $category]));

        return [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->open()->count(),
            'high' => (clone $query)->whereIn('severity', [SignalSeverity::HIGH->value, SignalSeverity::CRITICAL->value])->count(),
            'avg_priority' => round((float) ((clone $query)->avg('priority_score') ?? 0), 1),
            'latest' => (clone $query)->orderByDesc('last_seen_at')->limit(5)->get(),
        ];
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
