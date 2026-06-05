<?php

namespace App\Services\AgenticMarketing\VisibilityScoring;

use App\Models\AgenticMarketingOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AIVisibilityScoringEngine
{
    /**
     * @return array<string,mixed>
     */
    public function scoreOpportunity(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing('objective', 'content');

        $articles = $this->articlesForOpportunity($opportunity);
        $scorecards = $articles
            ->map(fn (Content $content): array => $this->scoreContent($content))
            ->values()
            ->all();

        return [
            'schema' => 'agentic_marketing.ai_visibility_scorecard.v1',
            'scope' => 'per_article',
            'article_count' => count($scorecards),
            'scores' => $scorecards,
            'summary' => $this->summary($scorecards),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function scoreContent(Content $content): array
    {
        $content->loadMissing(['currentRevision', 'currentVersion', 'drafts', 'answerBlocks', 'aiVisibilitySnapshots']);

        $html = $this->bodyHtml($content);
        $plain = $this->plainText($html);
        $entities = $this->entities($plain, $content);
        $answerBlocks = $content->answerBlocks instanceof Collection ? $content->answerBlocks : collect();
        $headings = $this->headings($html);
        $competitorOverlap = $this->competitorOverlapScore($content, $plain);
        $freshnessDecay = $this->freshnessDecayScore($content);
        $aiDiscoverability = $this->aiDiscoverabilityScore($content, $plain, $answerBlocks);
        $answerReadiness = $this->answerReadinessScore($html, $plain, $answerBlocks);
        $entityRichness = $this->entityRichnessScore($entities, $plain);
        $citationLikelihood = $this->citationLikelihoodScore($content, $html, $plain, $answerBlocks);
        $semanticCompleteness = $this->semanticCompletenessScore($content, $plain, $headings);
        $overall = (int) round((
            $aiDiscoverability
            + $answerReadiness
            + $entityRichness
            + $citationLikelihood
            + $semanticCompleteness
            + (100 - $freshnessDecay)
            + (100 - $competitorOverlap)
        ) / 7);

        return [
            'content_id' => (string) $content->id,
            'title' => (string) $content->title,
            'published_url' => $content->published_url,
            'overall_ai_visibility_score' => $this->clamp($overall),
            'ai_discoverability_score' => $aiDiscoverability,
            'answer_readiness_score' => $answerReadiness,
            'entity_richness_score' => $entityRichness,
            'citation_likelihood_score' => $citationLikelihood,
            'semantic_completeness_score' => $semanticCompleteness,
            'freshness_decay_score' => $freshnessDecay,
            'competitor_overlap_score' => $competitorOverlap,
            'signals' => [
                'word_count' => str_word_count($plain),
                'heading_count' => count($headings),
                'answer_block_count' => $answerBlocks->count(),
                'entity_count' => count($entities),
                'detected_entities' => array_slice($entities, 0, 12),
            ],
            'recommendations' => $this->recommendations(
                $answerReadiness,
                $entityRichness,
                $citationLikelihood,
                $semanticCompleteness,
                $freshnessDecay,
                $competitorOverlap
            ),
        ];
    }

    /**
     * @return Collection<int,Content>
     */
    private function articlesForOpportunity(AgenticMarketingOpportunity $opportunity): Collection
    {
        if ($opportunity->content) {
            return collect([$opportunity->content]);
        }

        $workspaceId = $opportunity->objective?->workspace_id;
        if (! $workspaceId) {
            return collect();
        }

        return Content::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    private function bodyHtml(Content $content): string
    {
        $latestDraft = $content->drafts
            ->sortByDesc(fn ($draft): string => (string) ($draft->updated_at ?: $draft->created_at))
            ->first();

        return trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: $latestDraft?->content_html
            ?: ''
        ));
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    /**
     * @return array<int,string>
     */
    private function headings(string $html): array
    {
        preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $matches);

        return array_values(array_filter(array_map(
            fn (string $heading): string => trim($this->plainText($heading)),
            $matches[1] ?? []
        )));
    }

    /**
     * @return array<int,string>
     */
    private function entities(string $plain, Content $content): array
    {
        preg_match_all('/\b([A-Z][A-Za-z0-9]+(?:\s+[A-Z][A-Za-z0-9]+)*|[A-Z]{2,})\b/u', $plain, $matches);
        $answerEntities = $content->answerBlocks instanceof Collection
            ? $content->answerBlocks->pluck('entities')->flatten()->map(fn (mixed $entity): string => (string) $entity)->all()
            : [];

        return collect(array_merge($matches[0] ?? [], $answerEntities, (array) $content->intent_keys))
            ->map(fn (mixed $entity): string => trim((string) $entity))
            ->filter(fn (string $entity): bool => mb_strlen($entity) >= 2 && ! in_array(Str::lower($entity), ['the', 'and', 'for', 'with'], true))
            ->unique(fn (string $entity): string => Str::lower($entity))
            ->values()
            ->all();
    }

    private function aiDiscoverabilityScore(Content $content, string $plain, Collection $answerBlocks): int
    {
        $stored = is_numeric($content->ai_visibility_score) ? (int) $content->ai_visibility_score : null;
        $score = $stored ?? 35;
        $score += min(20, (int) floor(str_word_count($plain) / 80));
        $score += $answerBlocks->isNotEmpty() ? 15 : 0;
        $score += $content->published_url ? 10 : 0;
        $score += $content->aiVisibilitySnapshots->sum('citation_count') > 0 ? 15 : 0;

        return $this->clamp($score);
    }

    private function answerReadinessScore(string $html, string $plain, Collection $answerBlocks): int
    {
        $score = 0;
        $score += min(35, $answerBlocks->count() * 12);
        $score += preg_match('/\b(is|are|means|refers to|helps|supports)\b/ui', mb_substr($plain, 0, 420)) === 1 ? 20 : 0;
        $score += preg_match('/faq|frequently asked questions|veelgestelde vragen/i', $html) === 1 ? 20 : 0;
        $score += preg_match('/<script[^>]+application\/ld\+json/i', $html) === 1 ? 15 : 0;
        $score += str_word_count($plain) >= 450 ? 10 : 0;

        return $this->clamp($score);
    }

    /**
     * @param array<int,string> $entities
     */
    private function entityRichnessScore(array $entities, string $plain): int
    {
        $density = str_word_count($plain) > 0 ? count($entities) / max(1, str_word_count($plain) / 100) : 0;

        return $this->clamp((int) round(min(70, count($entities) * 5) + min(30, $density * 10)));
    }

    private function citationLikelihoodScore(Content $content, string $html, string $plain, Collection $answerBlocks): int
    {
        $score = 20;
        $score += $answerBlocks->count() >= 2 ? 20 : 0;
        $score += preg_match('/<a\s/i', $html) === 1 ? 15 : 0;
        $score += preg_match('/\b(according to|source|data|research|benchmark|study|report)\b/i', $plain) === 1 ? 15 : 0;
        $score += preg_match('/20[0-9]{2}/', $plain) === 1 ? 10 : 0;
        $score += $content->schema_type ? 10 : 0;
        $score += $content->aiVisibilitySnapshots->sum('citation_count') > 0 ? 10 : 0;

        return $this->clamp($score);
    }

    private function semanticCompletenessScore(Content $content, string $plain, array $headings): int
    {
        $stored = is_numeric($content->semantic_coverage_score) ? (int) $content->semantic_coverage_score : null;
        if ($stored !== null) {
            return $this->clamp($stored);
        }

        $score = min(35, count($headings) * 7);
        $score += min(35, (int) floor(str_word_count($plain) / 60));
        $score += $content->primary_keyword && str_contains(Str::lower($plain), Str::lower($content->primary_keyword)) ? 15 : 0;
        $score += preg_match('/\b(how|why|what|when|examples|implementation|comparison)\b/i', $plain) === 1 ? 15 : 0;

        return $this->clamp($score);
    }

    private function freshnessDecayScore(Content $content): int
    {
        if (is_numeric($content->freshness_score)) {
            return $this->clamp(100 - (int) $content->freshness_score);
        }

        $days = max(0, (int) ($content->updated_at?->diffInDays(now()) ?? 180));

        return $this->clamp((int) round(min(100, $days / 2)));
    }

    private function competitorOverlapScore(Content $content, string $plain): int
    {
        $workspaceId = $content->workspace_id;
        if (! $workspaceId) {
            return 0;
        }

        $haystack = Str::lower(trim($content->title.' '.$content->primary_keyword.' '.$plain));
        $signals = CompetitorTopicSignal::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('opportunity_score')
            ->limit(20)
            ->get();

        $best = 0;
        foreach ($signals as $signal) {
            $topic = Str::lower((string) $signal->topic);
            if ($topic !== '' && str_contains($haystack, $topic)) {
                $best = max($best, (int) round(max((float) $signal->overlap_score, (float) $signal->opportunity_score)));
            }
        }

        return $this->clamp($best);
    }

    /**
     * @return array<int,string>
     */
    private function recommendations(int $answerReadiness, int $entityRichness, int $citationLikelihood, int $semanticCompleteness, int $freshnessDecay, int $competitorOverlap): array
    {
        return array_values(array_filter([
            $answerReadiness < 70 ? 'Add direct answer blocks and FAQ schema.' : null,
            $entityRichness < 65 ? 'Add more named entities, categories, tools, platforms, and examples.' : null,
            $citationLikelihood < 65 ? 'Add source-like evidence, current data, and citation-friendly summaries.' : null,
            $semanticCompleteness < 70 ? 'Expand missing subtopics and make the article structure more complete.' : null,
            $freshnessDecay > 45 ? 'Refresh stale references and update the publication context.' : null,
            $competitorOverlap > 60 ? 'Differentiate against competitor-covered angles with proof, specificity, and stronger internal links.' : null,
        ]));
    }

    /**
     * @param array<int,array<string,mixed>> $scorecards
     * @return array<string,mixed>
     */
    private function summary(array $scorecards): array
    {
        if ($scorecards === []) {
            return ['overall_average' => null, 'highest_risk' => 'no_articles'];
        }

        $average = (int) round(collect($scorecards)->avg('overall_ai_visibility_score'));
        $highestRisk = collect([
            'answer_readiness' => 100 - (int) collect($scorecards)->avg('answer_readiness_score'),
            'entity_richness' => 100 - (int) collect($scorecards)->avg('entity_richness_score'),
            'citation_likelihood' => 100 - (int) collect($scorecards)->avg('citation_likelihood_score'),
            'semantic_completeness' => 100 - (int) collect($scorecards)->avg('semantic_completeness_score'),
            'freshness_decay' => (int) collect($scorecards)->avg('freshness_decay_score'),
            'competitor_overlap' => (int) collect($scorecards)->avg('competitor_overlap_score'),
        ])->sortDesc()->keys()->first();

        return ['overall_average' => $average, 'highest_risk' => $highestRisk];
    }

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
