<?php

namespace App\Services\Agents;

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Models\AgentRun;
use App\Models\ClientSite;
use App\Models\Content;
use Illuminate\Support\Collection;

class SiteOptimizationOverviewBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ClientSite $site, int $maxItems = 5): array
    {
        $refreshRuns = $this->latestScheduledRuns($site, ContentRefreshAgent::KEY);
        $localizationRuns = $this->latestScheduledRuns($site, LocalizationAgent::KEY);

        $refreshCandidates = $refreshRuns
            ->map(function (AgentRun $run): array {
                $content = $run->content;
                $score = (int) data_get($run->output_payload, 'raw_payload.refresh_score', 0);
                $reasons = collect((array) data_get($run->output_payload, 'raw_payload.reasons', []))
                    ->pluck('title')
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'content_id' => (string) ($run->content_id ?? ''),
                    'title' => trim((string) ($content?->title ?? 'Untitled content')),
                    'href' => $content ? route('app.content.show', ['content' => $content, 'tab' => 'overview']) : null,
                    'score' => $score,
                    'urgency' => (string) data_get($run->output_payload, 'raw_payload.urgency_level', 'low'),
                    'reasons' => $reasons,
                    'summary' => trim((string) ($run->summary ?? '')),
                    'finished_at' => $run->finished_at,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] > 0 || $candidate['reasons'] !== [])
            ->sortByDesc('score')
            ->values();

        $localizationCandidates = $localizationRuns
            ->map(function (AgentRun $run): array {
                $content = $run->content;
                $recommendations = collect((array) data_get($run->output_payload, 'suggestions', []))
                    ->map(fn (array $item): string => trim((string) ($item['title'] ?? '')))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'content_id' => (string) ($run->content_id ?? ''),
                    'title' => trim((string) ($content?->title ?? 'Untitled content')),
                    'href' => $content ? route('app.content.show', ['content' => $content, 'tab' => 'overview']) : null,
                    'issue_count' => count($recommendations),
                    'recommendations' => $recommendations,
                    'summary' => trim((string) ($run->summary ?? '')),
                    'finished_at' => $run->finished_at,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['issue_count'] > 0)
            ->sortByDesc('issue_count')
            ->values();

        $lastScannedAt = collect([$refreshRuns, $localizationRuns])
            ->flatten(1)
            ->map(fn (AgentRun $run) => $run->finished_at ?? $run->created_at)
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'has_data' => $refreshRuns->isNotEmpty() || $localizationRuns->isNotEmpty(),
            'last_scanned_at' => $lastScannedAt,
            'refresh_candidate_count' => $refreshCandidates->count(),
            'localization_issue_count' => (int) $localizationCandidates->sum('issue_count'),
            'localized_content_count' => $localizationCandidates->count(),
            'top_refresh_candidates' => $refreshCandidates->take($maxItems)->values()->all(),
            'top_localization_items' => $localizationCandidates->take($maxItems)->values()->all(),
        ];
    }

    /**
     * @return Collection<int, AgentRun>
     */
    private function latestScheduledRuns(ClientSite $site, string $agentKey): Collection
    {
        $latestPerContent = AgentRun::query()
            ->selectRaw('content_id, MAX(created_at) as latest_created_at')
            ->where('site_id', (string) $site->id)
            ->where('agent_key', $agentKey)
            ->where('trigger_type', 'scheduled')
            ->whereNotNull('content_id')
            ->groupBy('content_id');

        return AgentRun::query()
            ->with('content')
            ->joinSub($latestPerContent, 'latest_runs', function ($join): void {
                $join->on('agent_runs.content_id', '=', 'latest_runs.content_id')
                    ->on('agent_runs.created_at', '=', 'latest_runs.latest_created_at');
            })
            ->orderByDesc('agent_runs.created_at')
            ->get(['agent_runs.*'])
            ->filter(fn (AgentRun $run): bool => $run->content instanceof Content)
            ->values();
    }
}
