<?php

namespace App\Services\Visibility;

use App\Models\Brand;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityResult;
use App\Models\VisibilityScore;
use Illuminate\Support\Str;

class VisibilityScoreCalculator
{
    public function __construct(
        private readonly CitationExtractor $citations,
        private readonly CitationClassificationService $citationClassification,
        private readonly SourcePresenceAnalyzer $sources,
        private readonly CompetitorPresenceAnalyzer $competitors,
        private readonly AiAttentionScoreService $attention,
        private readonly VisibilityTrendBuilder $trends,
    ) {}

    public function calculateForResult(VisibilityResult $result): VisibilityScore
    {
        $result->loadMissing(['brandModel', 'visibilityCheck']);
        $brand = $result->brandModel;
        $run = $this->providerRunForResult($result);
        $answer = (string) ($run?->normalized_answer ?: $result->metadata['answer'] ?? '');

        $answerMetrics = $this->answerPresence($brand, $answer);
        $citations = $run ? $this->citations->extractForRun($run, $brand) : collect();
        $citations = $run ? $this->citationClassification->classifyRun($run) : $citations;
        $sourceMetrics = $this->sources->analyze($brand, $citations);
        $competitorMetrics = $run
            ? $this->competitors->analyze($run, $brand)
            : ['competitor_presence_score' => 0, 'competitor_mentions' => 0, 'competitors' => []];

        $metrics = [
            ...$answerMetrics,
            ...$sourceMetrics,
            ...$competitorMetrics,
        ];
        $metrics['ai_attention_score'] = $this->attention->score($metrics);

        $score = VisibilityScore::query()->updateOrCreate(
            [
                'visibility_result_id' => $result->id,
                'provider' => $result->provider,
                'model' => $run?->model,
                'prompt_hash' => $this->promptHash($result, $run),
            ],
            [
                'account_id' => $result->account_id,
                'brand_id' => $result->brand_id,
                'visibility_check_id' => $result->visibility_check_id,
                'answer_presence_score' => $metrics['answer_presence_score'],
                'citation_score' => $metrics['citation_score'],
                'source_presence_score' => $metrics['source_presence_score'],
                'authority_score' => $metrics['authority_score'],
                'competitor_presence_score' => $metrics['competitor_presence_score'],
                'ai_attention_score' => $metrics['ai_attention_score'],
                'summary' => $this->summary($brand, $metrics),
                'raw_metrics_json' => [
                    ...$metrics,
                    'provider_run_id' => $run?->id,
                    'visibility_result_id' => $result->id,
                ],
            ],
        );

        $this->trends->buildForBrand($brand);
        $this->trends->buildForBrand($brand, $result->provider);

        return $score;
    }

    private function providerRunForResult(VisibilityResult $result): ?VisibilityProviderRun
    {
        $resultId = $result->id;

        return VisibilityProviderRun::query()
            ->where('account_id', $result->account_id)
            ->where('brand_id', $result->brand_id)
            ->where('provider', $result->provider)
            ->when($result->visibility_check_id, fn ($query) => $query->where('visibility_check_id', $result->visibility_check_id))
            ->where(function ($query) use ($resultId): void {
                $query->where('metadata->result_id', $resultId)
                    ->orWhereNull('metadata->result_id');
            })
            ->latest('captured_at')
            ->first();
    }

    /**
     * @return array{answer_presence_score: int, brand_mentions: int, brand_prominence: int}
     */
    private function answerPresence(Brand $brand, string $answer): array
    {
        $answer = trim($answer);

        if ($answer === '') {
            return [
                'answer_presence_score' => 0,
                'brand_mentions' => 0,
                'brand_prominence' => 0,
            ];
        }

        $lowerAnswer = Str::lower($answer);
        $lowerBrand = Str::lower($brand->name);
        $mentions = substr_count($lowerAnswer, $lowerBrand);

        if ($mentions === 0) {
            return [
                'answer_presence_score' => 0,
                'brand_mentions' => 0,
                'brand_prominence' => 0,
            ];
        }

        $position = strpos($lowerAnswer, $lowerBrand);
        $length = max(1, strlen($lowerAnswer));
        $prominence = 100 - min(100, (int) round(($position / $length) * 100));
        $score = min(100, 45 + ($mentions * 15) + (int) round($prominence * 0.35));

        return [
            'answer_presence_score' => $score,
            'brand_mentions' => $mentions,
            'brand_prominence' => $prominence,
        ];
    }

    private function promptHash(VisibilityResult $result, ?VisibilityProviderRun $run): string
    {
        return hash('sha256', implode('|', [
            $result->provider,
            $run?->model ?? '',
            $result->query,
            $run?->query ?? '',
        ]));
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function summary(Brand $brand, array $metrics): string
    {
        if (($metrics['answer_presence_score'] ?? 0) === 0) {
            return "{$brand->name} is absent from this AI answer.";
        }

        if (($metrics['competitor_presence_score'] ?? 0) > 0) {
            return "{$brand->name} is present, with competitor attention also detected.";
        }

        if (($metrics['owned_sources'] ?? 0) > 0) {
            return "{$brand->name} is present with owned source support.";
        }

        return "{$brand->name} is present in this AI answer.";
    }
}
