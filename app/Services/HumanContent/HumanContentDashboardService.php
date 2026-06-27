<?php

namespace App\Services\HumanContent;

use App\Models\Draft;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HumanContentDashboardService
{
    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function forOrganization(Organization $organization, array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);
        $workspaceIds = $organization->workspaces()->pluck('workspaces.id')->map(fn ($id): string => (string) $id)->all();

        if ($workspaceIds === []) {
            return $this->emptyPayload($normalized);
        }

        $cacheKey = 'human-content-dashboard:' . $organization->id . ':' . sha1(json_encode($normalized + ['workspace_ids' => $workspaceIds]) ?: '');

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($workspaceIds, $normalized): array {
            $drafts = $this->scoredDrafts($workspaceIds, $normalized);

            if ($drafts->isEmpty()) {
                return $this->emptyPayload($normalized);
            }

            $rows = $drafts
                ->map(fn (Draft $draft): array => $this->row($draft))
                ->filter(fn (array $row): bool => $row['has_score'])
                ->values();

            if ($rows->isEmpty()) {
                return $this->emptyPayload($normalized);
            }

            return [
                'filters' => $normalized,
                'generated_at' => now()->toIso8601String(),
                'sample_size' => $rows->count(),
                'averages' => $this->averages($rows),
                'trend' => $this->trend($rows),
                'most_repetitive' => $this->rank($rows, 'corpus_diversity_risk_score', 'desc', 8),
                'most_original' => $this->rank($rows, 'originality_score', 'desc', 8),
                'most_human' => $this->rank($rows, 'human_content_score', 'desc', 8),
                'blocked_articles' => $rows
                    ->filter(fn (array $row): bool => $row['blocked'])
                    ->sortByDesc('updated_at_timestamp')
                    ->take(12)
                    ->values()
                    ->all(),
                'common_fingerprints' => $this->commonFingerprints($rows),
            ];
        });
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $period = (int) ($filters['period'] ?? 30);
        if (! in_array($period, [7, 30, 90, 180, 365], true)) {
            $period = 30;
        }

        return [
            'workspace_id' => trim((string) ($filters['workspace_id'] ?? '')),
            'site_id' => trim((string) ($filters['site_id'] ?? '')),
            'locale' => trim((string) ($filters['locale'] ?? '')),
            'content_type' => trim((string) ($filters['content_type'] ?? '')),
            'period' => $period,
        ];
    }

    /**
     * @param array<int,string> $workspaceIds
     * @param array<string,mixed> $filters
     * @return Collection<int,Draft>
     */
    private function scoredDrafts(array $workspaceIds, array $filters): Collection
    {
        $since = now()->subDays((int) $filters['period'])->startOfDay();
        $workspaceFilter = (string) $filters['workspace_id'];
        $siteFilter = (string) $filters['site_id'];
        $localeFilter = (string) $filters['locale'];
        $typeFilter = (string) $filters['content_type'];

        return Draft::query()
            ->with(['content:id,workspace_id,client_site_id,title,type,language,primary_keyword', 'content.workspace:id,name,display_name', 'content.clientSite:id,name', 'brief:id,title'])
            ->where('updated_at', '>=', $since)
            ->whereNotNull('meta')
            ->whereHas('content', function ($query) use ($workspaceIds, $workspaceFilter, $siteFilter, $localeFilter, $typeFilter): void {
                $query->whereIn('workspace_id', $workspaceIds)
                    ->when($workspaceFilter !== '', fn ($q) => $q->where('workspace_id', $workspaceFilter))
                    ->when($siteFilter !== '', fn ($q) => $q->where('client_site_id', $siteFilter))
                    ->when($localeFilter !== '', fn ($q) => $q->where('language', $localeFilter))
                    ->when($typeFilter !== '', fn ($q) => $q->where('type', $typeFilter));
            })
            ->latest('updated_at')
            ->limit(600)
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function row(Draft $draft): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $score = is_array(data_get($meta, 'human_content.after')) ? data_get($meta, 'human_content.after') : [];
        $locale = (string) ($draft->content?->language?->value ?? $draft->getRawOriginal('language') ?? '');
        if ($locale !== '' && data_get($meta, "human_content.locales.{$locale}.after")) {
            $score = (array) data_get($meta, "human_content.locales.{$locale}.after");
        }

        $humanScore = $this->number(data_get($meta, 'human_content_score_after', data_get($score, 'human_content_score')));

        return [
            'id' => (string) $draft->id,
            'content_id' => (string) $draft->content_id,
            'title' => (string) ($draft->title ?: $draft->content?->title ?: $draft->brief?->title ?: 'Untitled draft'),
            'workspace' => (string) ($draft->content?->workspace?->display_name ?: $draft->content?->workspace?->name ?: ''),
            'site' => (string) ($draft->content?->clientSite?->name ?: ''),
            'locale' => $locale,
            'content_type' => (string) ($draft->content?->type ?? ''),
            'updated_at' => optional($draft->updated_at)->toDateString(),
            'updated_at_timestamp' => $draft->updated_at?->getTimestamp() ?? 0,
            'human_content_score' => $humanScore,
            'editorial_quality_score' => $this->number(data_get($score, 'editorial_quality_score')),
            'originality_score' => $this->number(data_get($score, 'originality_score')),
            'ai_fingerprint_score' => $this->number(data_get($meta, 'ai_fingerprint_score_after', data_get($score, 'ai_fingerprint_score'))),
            'narrative_flow_score' => $this->number(data_get($score, 'narrative_flow_score')),
            'human_voice_score' => $this->number(data_get($score, 'human_voice_score')),
            'uniqueness_score' => $this->number(data_get($score, 'uniqueness_score')),
            'corpus_diversity_risk_score' => $this->number(data_get($score, 'corpus_diversity_risk_score', data_get($score, 'corpus_diversity.risk_score'))),
            'gate_status' => (string) data_get($meta, 'publish_gate_status', data_get($meta, 'human_content_gate.status', '')),
            'gate_reasons' => (array) data_get($meta, 'human_content_gate.reasons', []),
            'fingerprint_findings' => $this->fingerprintFindings($meta),
            'has_score' => $humanScore !== null,
            'blocked' => (string) data_get($meta, 'publish_gate_status', data_get($meta, 'human_content_gate.status')) === HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW,
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array<string,int|null>
     */
    private function averages(Collection $rows): array
    {
        $keys = [
            'human_content_score',
            'editorial_quality_score',
            'originality_score',
            'ai_fingerprint_score',
            'narrative_flow_score',
            'human_voice_score',
        ];

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->average($rows, $key)])
            ->all();
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function trend(Collection $rows): array
    {
        return $rows
            ->groupBy('updated_at')
            ->sortKeys()
            ->map(fn (Collection $day, string $date): array => [
                'date' => $date,
                'human_content_score' => $this->average($day, 'human_content_score'),
                'editorial_quality_score' => $this->average($day, 'editorial_quality_score'),
                'ai_fingerprint_score' => $this->average($day, 'ai_fingerprint_score'),
                'count' => $day->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function rank(Collection $rows, string $key, string $direction, int $limit): array
    {
        $filtered = $rows->filter(fn (array $row): bool => is_numeric($row[$key] ?? null));

        return ($direction === 'asc' ? $filtered->sortBy($key) : $filtered->sortByDesc($key))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function commonFingerprints(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $row): array => $row['fingerprint_findings'])
            ->groupBy(fn (array $finding): string => (string) ($finding['type'] ?? 'ai_fingerprint'))
            ->map(fn (Collection $findings, string $type): array => [
                'type' => $type,
                'label' => Str::headline(str_replace('_', ' ', $type)),
                'count' => $findings->count(),
                'examples' => $findings
                    ->pluck('message')
                    ->merge($findings->pluck('evidence'))
                    ->map(fn (mixed $value): string => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->take(3)
                    ->values()
                    ->all(),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    private function average(Collection $rows, string $key): ?int
    {
        $values = $rows->pluck($key)->filter(fn (mixed $value): bool => is_numeric($value));

        return $values->isEmpty() ? null : (int) round((float) $values->avg());
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<int,array<string,mixed>>
     */
    private function fingerprintFindings(array $meta): array
    {
        $findings = data_get($meta, 'fingerprint_findings', data_get($meta, 'human_content.after.ai_fingerprint.findings', []));

        return collect(is_array($findings) ? $findings : [])
            ->map(function (mixed $finding): array {
                if (is_array($finding)) {
                    return $finding;
                }

                return [
                    'type' => 'ai_fingerprint',
                    'message' => (string) $finding,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function emptyPayload(array $filters): array
    {
        return [
            'filters' => $filters,
            'generated_at' => now()->toIso8601String(),
            'sample_size' => 0,
            'averages' => [
                'human_content_score' => null,
                'editorial_quality_score' => null,
                'originality_score' => null,
                'ai_fingerprint_score' => null,
                'narrative_flow_score' => null,
                'human_voice_score' => null,
            ],
            'trend' => [],
            'most_repetitive' => [],
            'most_original' => [],
            'most_human' => [],
            'blocked_articles' => [],
            'common_fingerprints' => [],
        ];
    }
}
