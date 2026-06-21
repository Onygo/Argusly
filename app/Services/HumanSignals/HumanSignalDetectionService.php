<?php

namespace App\Services\HumanSignals;

use App\Enums\HumanSignalType;
use App\Models\HumanSignal;
use App\Models\HumanSignalEvidence;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HumanSignalDetectionService
{
    public function __construct(
        private readonly HumanSignalInsightService $insights,
        private readonly HumanSignalQualityService $quality,
    ) {}

    /**
     * @return Collection<int,HumanSignal>
     */
    public function detectForWorkspace(Workspace $workspace): Collection
    {
        $candidates = collect()
            ->merge($this->fromSignalDetections($workspace))
            ->merge($this->fromLlmVisibility($workspace))
            ->merge($this->fromFaqCoverage($workspace))
            ->merge($this->fromContentPerformance($workspace))
            ->merge($this->fromCampaignLearning($workspace))
            ->merge($this->fromPublishedContent($workspace));

        return $candidates
            ->filter(fn (array $candidate): bool => filled($candidate['title'] ?? null) && filled($candidate['observation'] ?? null))
            ->map(fn (array $candidate): HumanSignal => $this->persistCandidate($workspace, $candidate))
            ->values();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromSignalDetections(Workspace $workspace): array
    {
        if (! Schema::hasTable('signal_detections')) {
            return [];
        }

        return DB::table('signal_detections')
            ->where('workspace_id', (string) $workspace->id)
            ->whereNull('deleted_at')
            ->where('last_seen_at', '>=', now()->subDays(45))
            ->orderByDesc('priority_score')
            ->limit(25)
            ->get()
            ->map(function (object $row): array {
                $type = $this->typeForSignalDetection($row);
                $topic = trim((string) ($row->primary_topic ?: $row->primary_entity ?: 'market signal'));
                $score = (float) ($row->priority_score ?: $row->opportunity_score ?: $row->impact_score ?: 50);

                return [
                    'type' => $type->value,
                    'site_id' => $row->client_site_id,
                    'title' => (string) $row->title,
                    'observation' => (string) ($row->summary ?: "Detected {$topic} as a recurring signal in Signal Intelligence."),
                    'impact' => $this->impactForType($type, $topic, $score),
                    'confidence_score' => (float) ($row->confidence_score ?: min(95, max(45, $score))),
                    'detected_at' => $row->last_seen_at ?: $row->created_at,
                    'metadata_json' => [
                        'source' => 'signal_detection',
                        'signal_detection_id' => (string) $row->id,
                        'priority_score' => $score,
                        'topic' => $topic,
                        'category' => $row->category,
                    ],
                    'evidence' => [[
                        'source_type' => 'signal_detection',
                        'source_id' => (string) $row->id,
                        'title' => (string) $row->title,
                        'summary' => (string) ($row->summary ?: $row->primary_topic ?: $row->primary_entity ?: ''),
                        'weight' => max(0.5, min(1.5, $score / 70)),
                        'metrics_json' => [
                            'priority_score' => (float) $row->priority_score,
                            'confidence_score' => (float) $row->confidence_score,
                            'impact_score' => (float) $row->impact_score,
                            'urgency_score' => (float) $row->urgency_score,
                            'risk_score' => $row->risk_score !== null ? (float) $row->risk_score : null,
                            'opportunity_score' => $row->opportunity_score !== null ? (float) $row->opportunity_score : null,
                        ],
                    ]],
                ];
            })
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromLlmVisibility(Workspace $workspace): array
    {
        if (! Schema::hasTable('llm_tracking_query_runs') || ! Schema::hasTable('llm_tracking_queries')) {
            return [];
        }

        $topicExpression = Schema::hasColumn('llm_tracking_queries', 'topic')
            ? 'COALESCE(queries.topic, queries.name, queries.query_text)'
            : 'COALESCE(queries.name, queries.query_text)';

        $rows = DB::table('llm_tracking_query_runs as runs')
            ->join('llm_tracking_queries as queries', 'queries.id', '=', 'runs.llm_tracking_query_id')
            ->where('queries.workspace_id', (string) $workspace->id)
            ->where('runs.run_at', '>=', now()->subDays(45))
            ->selectRaw("queries.client_site_id, {$topicExpression} as topic, AVG(runs.ai_visibility_score) as avg_visibility, AVG(runs.citation_score) as avg_citation, AVG(runs.competitor_share_score) as avg_competitor_share, AVG(runs.model_confidence_score) as avg_confidence, COUNT(*) as sample_count, MAX(runs.run_at) as latest_seen")
            ->groupBy('queries.client_site_id', 'topic')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('latest_seen')
            ->limit(20)
            ->get();

        return $rows->flatMap(function (object $row): array {
            $topic = trim((string) ($row->topic ?: 'AI visibility'));
            $visibility = (float) ($row->avg_visibility ?? 0);
            $citation = (float) ($row->avg_citation ?? 0);
            $competitor = (float) ($row->avg_competitor_share ?? 0);
            $confidence = max(45, min(95, (float) ($row->avg_confidence ?: 65)));
            $signals = [];

            if ($citation >= 65) {
                $signals[] = [
                    'type' => HumanSignalType::CITATION_PATTERN->value,
                    'title' => "AI citations cluster around {$topic}",
                    'observation' => "LLM tracking shows a citation score of ".round($citation, 1)." for {$topic} across {$row->sample_count} recent run(s).",
                    'impact' => "This topic already has source traction, so content can build from observed citation behavior instead of broad assumptions.",
                    'confidence_score' => $confidence,
                ];
            }

            if ($competitor >= 55) {
                $signals[] = [
                    'type' => HumanSignalType::COMPETITOR_SHIFT->value,
                    'title' => "Competitors are gaining AI answer share for {$topic}",
                    'observation' => "Recent AI visibility runs show competitor share around ".round($competitor, 1)." for {$topic}.",
                    'impact' => 'Competitive pressure is visible in AI answers, so authority work should prioritize the topic before the gap hardens.',
                    'confidence_score' => $confidence,
                ];
            } elseif ($visibility >= 70) {
                $signals[] = [
                    'type' => HumanSignalType::AUTHORITY_GROWTH->value,
                    'title' => "AI visibility is strong for {$topic}",
                    'observation' => "Average AI visibility is ".round($visibility, 1)." for {$topic} in recent tracking runs.",
                    'impact' => 'The content angle can lean into a proven authority area and extend it into adjacent questions.',
                    'confidence_score' => $confidence,
                ];
            } elseif ($visibility > 0 && $visibility <= 35) {
                $signals[] = [
                    'type' => HumanSignalType::AUTHORITY_DECLINE->value,
                    'title' => "AI visibility is weak for {$topic}",
                    'observation' => "Average AI visibility is only ".round($visibility, 1)." for {$topic} in recent tracking runs.",
                    'impact' => 'AI systems have limited usable context, making this a candidate for answer blocks, citations, and entity clarity.',
                    'confidence_score' => $confidence,
                ];
            }

            return collect($signals)->map(function (array $signal) use ($row, $topic, $visibility, $citation, $competitor): array {
                return $signal + [
                    'site_id' => $row->client_site_id,
                    'detected_at' => $row->latest_seen,
                    'metadata_json' => [
                        'source' => 'llm_tracking_query_runs',
                        'topic' => $topic,
                        'sample_count' => (int) $row->sample_count,
                    ],
                    'evidence' => [[
                        'source_type' => 'llm_tracking_query_runs',
                        'source_id' => null,
                        'title' => 'LLM tracking aggregate',
                        'summary' => "Recent tracking data for {$topic}.",
                        'metrics_json' => [
                            'avg_visibility' => round($visibility, 2),
                            'avg_citation' => round($citation, 2),
                            'avg_competitor_share' => round($competitor, 2),
                            'sample_count' => (int) $row->sample_count,
                        ],
                    ]],
                ];
            })->all();
        })->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromFaqCoverage(Workspace $workspace): array
    {
        if (! Schema::hasTable('faq_opportunity_audits')) {
            return [];
        }

        $query = DB::table('faq_opportunity_audits');
        if (Schema::hasColumn('faq_opportunity_audits', 'workspace_id')) {
            $query->where('workspace_id', (string) $workspace->id);
        }
        if (Schema::hasColumn('faq_opportunity_audits', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->where('created_at', '>=', now()->subDays(60))
            ->where('faq_opportunity_score', '>=', 65)
            ->orderByDesc('faq_opportunity_score')
            ->limit(12)
            ->get()
            ->map(function (object $row): array {
                $page = (string) data_get($row, 'page_slug', data_get($row, 'url', 'important page'));
                $score = (float) ($row->faq_opportunity_score ?? 65);

                return [
                    'type' => HumanSignalType::FAQ_GAP->value,
                    'site_id' => data_get($row, 'client_site_id'),
                    'title' => "FAQ coverage gap on {$page}",
                    'observation' => "FAQ intelligence scores {$page} at {$score}/100 opportunity, indicating missing or under-covered buyer questions.",
                    'impact' => 'AI systems and buyers may receive less context for high-intent questions until the page adds direct answers.',
                    'confidence_score' => min(92, max(55, $score)),
                    'detected_at' => $row->created_at ?? now(),
                    'metadata_json' => ['source' => 'faq_opportunity_audit', 'page' => $page],
                    'evidence' => [[
                        'source_type' => 'faq_opportunity_audit',
                        'source_id' => (string) $row->id,
                        'title' => "FAQ opportunity score {$score}",
                        'summary' => "FAQ audit indicates a coverage gap on {$page}.",
                        'metrics_json' => ['faq_opportunity_score' => $score],
                    ]],
                ];
            })
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromContentPerformance(Workspace $workspace): array
    {
        if (! Schema::hasTable('content_learning_profiles')) {
            return [];
        }

        return DB::table('content_learning_profiles')
            ->where('workspace_id', (string) $workspace->id)
            ->where(function ($query): void {
                $query->where('performance_score', '>=', 75)
                    ->orWhere('ai_visibility_score', '>=', 70)
                    ->orWhere('conversion_score', '>=', 70);
            })
            ->orderByDesc('performance_score')
            ->limit(15)
            ->get()
            ->map(function (object $row): array {
                $topic = trim((string) ($row->primary_topic ?: 'published content'));
                $best = max((float) $row->performance_score, (float) $row->ai_visibility_score, (float) $row->conversion_score);

                return [
                    'type' => $row->conversion_score >= 70 ? HumanSignalType::CONVERSION_PATTERN->value : HumanSignalType::CONTENT_PERFORMANCE->value,
                    'site_id' => $row->client_site_id,
                    'title' => "Content performance pattern around {$topic}",
                    'observation' => "{$topic} is outperforming with performance {$row->performance_score}, AI visibility {$row->ai_visibility_score}, and conversion {$row->conversion_score}.",
                    'impact' => 'The pattern can guide follow-up content, internal links, social repurposing, and campaign planning.',
                    'confidence_score' => min(95, max(55, $best)),
                    'detected_at' => $row->updated_at ?? now(),
                    'metadata_json' => ['source' => 'content_learning_profile', 'topic' => $topic],
                    'evidence' => [[
                        'source_type' => 'content_learning_profile',
                        'source_id' => (string) $row->id,
                        'title' => $topic,
                        'summary' => 'Stored learning profile shows repeatable content performance.',
                        'metrics_json' => [
                            'performance_score' => (float) $row->performance_score,
                            'ai_visibility_score' => (float) $row->ai_visibility_score,
                            'conversion_score' => (float) $row->conversion_score,
                        ],
                    ]],
                ];
            })
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromCampaignLearning(Workspace $workspace): array
    {
        if (! Schema::hasTable('campaign_learning_profiles')) {
            return [];
        }

        return DB::table('campaign_learning_profiles as profiles')
            ->leftJoin('campaigns', 'campaigns.id', '=', 'profiles.campaign_id')
            ->where('profiles.workspace_id', (string) $workspace->id)
            ->where('profiles.performance_score', '>=', 70)
            ->orderByDesc('profiles.performance_score')
            ->select('profiles.*', 'campaigns.name as campaign_name')
            ->limit(10)
            ->get()
            ->map(function (object $row): array {
                $name = trim((string) ($row->campaign_name ?: 'campaign'));

                return [
                    'type' => HumanSignalType::CAMPAIGN_PATTERN->value,
                    'site_id' => $row->client_site_id ?? null,
                    'title' => "Campaign pattern detected in {$name}",
                    'observation' => "{$name} shows a stored campaign performance score of ".round((float) $row->performance_score, 1).".",
                    'impact' => 'Campaign planning can reuse the observed angle, channel mix, or CTA pattern instead of starting from a blank prompt.',
                    'confidence_score' => min(92, max(55, (float) $row->performance_score)),
                    'detected_at' => $row->updated_at ?? now(),
                    'metadata_json' => ['source' => 'campaign_learning_profile'],
                    'evidence' => [[
                        'source_type' => 'campaign_learning_profile',
                        'source_id' => (string) $row->id,
                        'title' => $name,
                        'summary' => 'Campaign learning profile crossed the pattern threshold.',
                        'metrics_json' => ['performance_score' => (float) $row->performance_score],
                    ]],
                ];
            })
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromPublishedContent(Workspace $workspace): array
    {
        if (! Schema::hasTable('contents')) {
            return [];
        }

        $publishedColumn = Schema::hasColumn('contents', 'first_published_at')
            ? 'first_published_at'
            : (Schema::hasColumn('contents', 'published_at') ? 'published_at' : 'updated_at');
        $topicParts = [];
        foreach (['primary_keyword', 'content_type', 'type', 'title'] as $column) {
            if (Schema::hasColumn('contents', $column)) {
                $topicParts[] = $column;
            }
        }
        $topicExpression = 'COALESCE('.implode(', ', array_merge($topicParts, ["'content'"])).')';

        $rows = DB::table('contents')
            ->where('workspace_id', (string) $workspace->id)
            ->whereNull('deleted_at')
            ->whereNotNull($publishedColumn)
            ->where($publishedColumn, '>=', now()->subDays(90))
            ->selectRaw("client_site_id, {$topicExpression} as topic, COUNT(*) as content_count, MAX({$publishedColumn}) as latest_seen")
            ->groupBy('client_site_id', 'topic')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('content_count')
            ->limit(10)
            ->get();

        return $rows->map(function (object $row): array {
            $topic = trim((string) ($row->topic ?: 'recent content'));
            $count = (int) $row->content_count;

            return [
                'type' => HumanSignalType::TOPIC_OPPORTUNITY->value,
                'site_id' => $row->client_site_id,
                'title' => "Published content cluster forming around {$topic}",
                'observation' => "{$count} recently published item(s) share {$topic} as a content theme.",
                'impact' => 'This cluster can become a stronger authority path with internal links, FAQ expansion, and campaign sequencing.',
                'confidence_score' => min(85, 45 + ($count * 10)),
                'detected_at' => $row->latest_seen,
                'metadata_json' => ['source' => 'published_content', 'topic' => $topic, 'content_count' => $count],
                'evidence' => [[
                    'source_type' => 'published_content',
                    'source_id' => null,
                    'title' => "{$count} recent content items",
                    'summary' => "Published content volume indicates a topic pattern around {$topic}.",
                    'metrics_json' => ['content_count' => $count],
                ]],
            ];
        })->all();
    }

    private function persistCandidate(Workspace $workspace, array $candidate): HumanSignal
    {
        $type = HumanSignalType::tryFrom((string) ($candidate['type'] ?? '')) ?? HumanSignalType::CUSTOM;
        $detectedAt = $candidate['detected_at'] ?? now();
        $metadata = (array) ($candidate['metadata_json'] ?? []);
        $metadata['quality'] = $this->quality->scoreCandidate($candidate);

        $hash = hash('sha256', implode('|', [
            (string) $workspace->id,
            $type->value,
            strtolower(trim((string) ($candidate['title'] ?? ''))),
            strtolower(trim((string) data_get($metadata, 'topic', ''))),
            substr((string) $detectedAt, 0, 10),
        ]));

        $signal = HumanSignal::query()->updateOrCreate(
            ['workspace_id' => (string) $workspace->id, 'dedupe_hash' => $hash],
            [
                'organization_id' => $workspace->organization_id,
                'site_id' => $candidate['site_id'] ?? null,
                'type' => $type->value,
                'title' => Str::limit((string) $candidate['title'], 220, ''),
                'observation' => (string) $candidate['observation'],
                'impact' => (string) ($candidate['impact'] ?? ''),
                'confidence_score' => max(0, min(100, (float) ($candidate['confidence_score'] ?? 50))),
                'status' => HumanSignal::STATUS_DETECTED,
                'detected_at' => $detectedAt,
                'expires_at' => $candidate['expires_at'] ?? now()->addDays(90),
                'metadata_json' => $metadata,
            ]
        );

        foreach ((array) ($candidate['evidence'] ?? []) as $evidence) {
            HumanSignalEvidence::query()->updateOrCreate(
                [
                    'human_signal_id' => (string) $signal->id,
                    'source_type' => (string) ($evidence['source_type'] ?? 'unknown'),
                    'source_id' => $evidence['source_id'] ?? null,
                ],
                [
                    'title' => $evidence['title'] ?? null,
                    'summary' => $evidence['summary'] ?? null,
                    'weight' => (float) ($evidence['weight'] ?? 1),
                    'metrics_json' => $evidence['metrics_json'] ?? [],
                    'metadata_json' => $evidence['metadata_json'] ?? [],
                ]
            );
        }

        $this->insights->generateForSignal($signal->refresh());

        return $signal->refresh();
    }

    private function typeForSignalDetection(object $row): HumanSignalType
    {
        $category = (string) $row->category;
        $type = (string) $row->type;

        return match (true) {
            str_contains($category, 'competitor') || str_contains($type, 'competitor') => HumanSignalType::COMPETITOR_SHIFT,
            str_contains($category, 'risk') || str_contains($type, 'declin') => HumanSignalType::AUTHORITY_DECLINE,
            str_contains($category, 'trend') => HumanSignalType::EMERGING_TOPIC,
            str_contains($type, 'content_gap') || str_contains($category, 'opportunity') => HumanSignalType::CONTENT_GAP,
            str_contains($type, 'brand') || str_contains($type, 'citation') => HumanSignalType::VISIBILITY_TREND,
            default => HumanSignalType::CUSTOM,
        };
    }

    private function impactForType(HumanSignalType $type, string $topic, float $score): string
    {
        return match ($type) {
            HumanSignalType::COMPETITOR_SHIFT => "Competitor movement around {$topic} raises pressure to strengthen authority before AI systems normalize competitor-led answers.",
            HumanSignalType::CONTENT_GAP, HumanSignalType::FAQ_GAP => "Missing coverage around {$topic} can reduce answer readiness and weaken opportunity prioritization.",
            HumanSignalType::AUTHORITY_DECLINE => "Declining authority around {$topic} can make content less likely to be cited or trusted in AI answers.",
            HumanSignalType::EMERGING_TOPIC, HumanSignalType::TOPIC_OPPORTUNITY => "{$topic} is becoming actionable enough to justify content, campaign, or FAQ expansion.",
            default => "The observed score of ".round($score, 1)." makes {$topic} useful as source material for planning and generation.",
        };
    }
}
