<?php

namespace App\Services\Research;

use App\Enums\ResearchFindingType;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Collection;
use Throwable;

class ResearchSummaryService
{
    public function __construct(
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * @return array{summary:array<string,mixed>,human_summary:string}
     */
    public function generateForProject(ResearchProject $project): array
    {
        $findings = $project->findings()
            ->orderByDesc('confidence_score')
            ->orderBy('created_at')
            ->get();

        $selected = $this->selectFindingsForSummary($findings);

        if ($selected->isEmpty()) {
            return [
                'summary' => $this->buildEmptySummary($project),
                'human_summary' => 'No usable findings were extracted for this project yet.',
            ];
        }

        $grouped = $this->groupByType($selected);

        try {
            $llmSummary = $this->generateLlmSummary($project, $grouped);
        } catch (Throwable) {
            $llmSummary = null;
        }

        $summary = [
            'generated_at' => now()->toIso8601String(),
            'project_id' => (string) $project->id,
            'selected_finding_count' => $selected->count(),
            'finding_counts' => [
                'insights' => $grouped['insights']->count(),
                'statistics' => $grouped['statistics']->count(),
                'quotes' => $grouped['quotes']->count(),
                'entities' => $grouped['entities']->count(),
                'questions' => $grouped['questions']->count(),
            ],
            'highlights' => [
                'insights' => $grouped['insights']->take(5)->values()->all(),
                'statistics' => $grouped['statistics']->take(5)->values()->all(),
                'quotes' => $grouped['quotes']->take(5)->values()->all(),
                'entities' => $grouped['entities']->take(8)->values()->all(),
                'questions' => $grouped['questions']->take(8)->values()->all(),
            ],
            'brief_enrichment' => [
                'recommended_angles' => (array) data_get($llmSummary, 'brief_enrichment.angles', []),
                'risk_notes' => (array) data_get($llmSummary, 'brief_enrichment.risks', []),
                'keyword_clusters' => (array) data_get($llmSummary, 'brief_enrichment.keyword_clusters', []),
            ],
            'model_summary' => $llmSummary,
        ];

        $humanSummary = $this->buildHumanSummary($summary, $llmSummary);

        return [
            'summary' => $summary,
            'human_summary' => $humanSummary,
        ];
    }

    public function persistSummary(ResearchProject $project): ResearchProject
    {
        $payload = $this->generateForProject($project);

        $project->update([
            'summary' => $payload['summary'],
            'human_summary' => $payload['human_summary'],
        ]);

        return $project->fresh();
    }

    /**
     * @param Collection<int,ResearchFinding> $findings
     * @return Collection<int,ResearchFinding>
     */
    private function selectFindingsForSummary(Collection $findings): Collection
    {
        $minConfidence = (float) config('research.summary.min_confidence', 0.65);
        $max = max(10, (int) config('research.summary.max_findings', 60));

        $selected = $findings
            ->filter(fn (ResearchFinding $finding): bool => (bool) $finding->is_selected)
            ->values();

        $highConfidence = $findings
            ->filter(fn (ResearchFinding $finding): bool => (float) ($finding->confidence_score ?? 0) >= $minConfidence)
            ->values();

        $result = $selected->isNotEmpty()
            ? $selected->merge($highConfidence)
            : $highConfidence;

        if ($result->isEmpty()) {
            $result = $findings->take(min($max, 20));
        }

        return $result
            ->unique(fn (ResearchFinding $finding): string => (string) $finding->id)
            ->take($max)
            ->values();
    }

    /**
     * @param Collection<int,ResearchFinding> $findings
     * @return array{insights:Collection<int,string>,statistics:Collection<int,string>,quotes:Collection<int,string>,entities:Collection<int,string>,questions:Collection<int,string>}
     */
    private function groupByType(Collection $findings): array
    {
        $grouped = $findings
            ->groupBy(fn (ResearchFinding $finding): string => (string) ($finding->finding_type?->value ?? $finding->finding_type));

        $toStrings = static fn (Collection $bucket): Collection => $bucket
            ->map(fn (ResearchFinding $finding): string => trim((string) $finding->finding_text))
            ->filter()
            ->values();

        return [
            'insights' => $toStrings($grouped->get(ResearchFindingType::INSIGHT->value, collect())),
            'statistics' => $toStrings($grouped->get(ResearchFindingType::STATISTIC->value, collect())),
            'quotes' => $toStrings($grouped->get(ResearchFindingType::QUOTE->value, collect())),
            'entities' => $toStrings($grouped->get(ResearchFindingType::ENTITY->value, collect())),
            'questions' => $toStrings($grouped->get(ResearchFindingType::QUESTION->value, collect())),
        ];
    }

    /**
     * @param array{insights:Collection<int,string>,statistics:Collection<int,string>,quotes:Collection<int,string>,entities:Collection<int,string>,questions:Collection<int,string>} $grouped
     * @return array<string,mixed>
     */
    private function generateLlmSummary(ResearchProject $project, array $grouped): array
    {
        $userPayload = [
            'project_name' => (string) $project->name,
            'insights' => $grouped['insights']->take(20)->values()->all(),
            'statistics' => $grouped['statistics']->take(20)->values()->all(),
            'quotes' => $grouped['quotes']->take(15)->values()->all(),
            'entities' => $grouped['entities']->take(20)->values()->all(),
            'questions' => $grouped['questions']->take(20)->values()->all(),
        ];

        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', implode("\n", [
                        'You are a research summary engine.',
                        'Return strict JSON only.',
                        'Stay grounded in supplied findings.',
                    ])),
                    new LlmMessage('user', implode("\n", [
                        'Build a concise summary object with keys:',
                        'executive_summary (string), key_insights (array), statistics (array), quotes (array), entities (array), open_questions (array), brief_enrichment (object).',
                        'brief_enrichment must include: angles (array), risks (array), keyword_clusters (array).',
                        'Findings JSON:',
                        json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ])),
                ],
                temperature: 0.0,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'research_summary',
                    'modality' => 'text',
                    'workspaceId' => (string) $project->workspace_id,
                    'siteId' => (string) ($project->client_site_id ?? ''),
                    'researchProjectId' => (string) $project->id,
                ],
            ),
            [
                'type' => 'object',
                'required' => ['executive_summary'],
            ],
            [
                'feature' => 'research_summary',
            ],
        );

        $json = is_array($response->json) ? $response->json : [];
        $json['_provider'] = $response->providerName;
        $json['_model'] = $response->modelUsed;
        $json['_request_id'] = $response->requestId;

        return $json;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed>|null $llmSummary
     */
    private function buildHumanSummary(array $summary, ?array $llmSummary): string
    {
        $lines = [];

        $executive = trim((string) data_get($llmSummary, 'executive_summary', ''));
        if ($executive !== '') {
            $lines[] = $executive;
        }

        $insights = (array) data_get($llmSummary, 'key_insights', data_get($summary, 'highlights.insights', []));
        if ($insights !== []) {
            $lines[] = '';
            $lines[] = 'Key insights:';
            foreach (array_slice($insights, 0, 5) as $row) {
                $text = trim((string) $row);
                if ($text !== '') {
                    $lines[] = '- ' . $text;
                }
            }
        }

        $questions = (array) data_get($llmSummary, 'open_questions', data_get($summary, 'highlights.questions', []));
        if ($questions !== []) {
            $lines[] = '';
            $lines[] = 'Open questions:';
            foreach (array_slice($questions, 0, 4) as $row) {
                $text = trim((string) $row);
                if ($text !== '') {
                    $lines[] = '- ' . $text;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEmptySummary(ResearchProject $project): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'project_id' => (string) $project->id,
            'selected_finding_count' => 0,
            'finding_counts' => [
                'insights' => 0,
                'statistics' => 0,
                'quotes' => 0,
                'entities' => 0,
                'questions' => 0,
            ],
            'highlights' => [
                'insights' => [],
                'statistics' => [],
                'quotes' => [],
                'entities' => [],
                'questions' => [],
            ],
            'brief_enrichment' => [
                'recommended_angles' => [],
                'risk_notes' => [],
                'keyword_clusters' => [],
            ],
            'model_summary' => null,
        ];
    }
}
