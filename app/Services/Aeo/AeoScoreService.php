<?php

namespace App\Services\Aeo;

use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AeoScoreService
{
    /**
     * @return array{
     *   score:int,
     *   breakdown:array<string,int>,
     *   improvements:array<int,string>
     * }
     */
    public function score(Content $content): array
    {
        $content->loadMissing(['currentRevision', 'currentVersion', 'answerBlocks', 'drafts']);

        $title = trim((string) $content->title);
        $keyword = trim((string) ($content->primary_keyword ?? ''));
        $html = $this->resolveBodyHtml($content);
        $plain = $this->normalizeWhitespace(strip_tags($html));
        $first300 = mb_substr($plain, 0, 300);
        $headings = $this->extractHeadings($html);
        $answerBlocks = $content->answerBlocks instanceof Collection ? $content->answerBlocks : collect();

        $breakdown = [
            'answer_clarity' => $this->scoreAnswerClarity($first300, $title, $keyword, $answerBlocks),
            'structure' => $this->scoreStructure($html, $headings, $answerBlocks),
            'semantic_coverage' => $this->scoreSemanticCoverage($plain, $title, $keyword, $headings),
            'entity_usage' => $this->scoreEntityUsage($plain, $answerBlocks),
            'readability' => $this->scoreReadability($plain),
            'llm_formatting' => $this->scoreLlmFormatting($html, $plain, $answerBlocks),
        ];

        $score = array_sum($breakdown);
        $improvements = $this->buildImprovements($breakdown, $answerBlocks, $plain, $headings);

        return [
            'score' => max(0, min(100, $score)),
            'breakdown' => $breakdown,
            'improvements' => $improvements,
        ];
    }

    public function recalculate(Content $content): array
    {
        $result = $this->score($content);

        $content->forceFill([
            'aeo_score' => $result['score'],
            'aeo_breakdown' => [
                'breakdown' => $result['breakdown'],
                'improvements' => $result['improvements'],
            ],
        ])->saveQuietly();

        return $result;
    }

    private function resolveBodyHtml(Content $content): string
    {
        $latestDraft = $content->drafts
            ->sortByDesc(fn ($draft): string => sprintf(
                '%010d-%s',
                max(
                    $draft->updated_at?->getTimestamp() ?? 0,
                    $draft->created_at?->getTimestamp() ?? 0,
                ),
                (string) $draft->id,
            ))
            ->first();

        return trim((string) (
            $latestDraft?->content_html
            ?: $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));
    }

    /**
     * @return array<int,array{level:int,text:string}>
     */
    private function extractHeadings(string $html): array
    {
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match): array => [
                'level' => (int) ($match[1] ?? 0),
                'text' => $this->normalizeWhitespace(strip_tags((string) ($match[2] ?? ''))),
            ])
            ->filter(fn (array $heading): bool => $heading['text'] !== '')
            ->values()
            ->all();
    }

    private function scoreAnswerClarity(string $first300, string $title, string $keyword, Collection $answerBlocks): int
    {
        $score = 0;
        $haystack = Str::lower($first300);

        if ($first300 !== '' && mb_strlen($first300) >= 80) {
            $score += 6;
        }

        if (preg_match('/\b(is|are|means|refers to|betekent|is een|zijn)\b/ui', $first300) === 1) {
            $score += 7;
        }

        foreach (array_filter([$keyword, $title]) as $needle) {
            if ($needle !== '' && str_contains($haystack, Str::lower($needle))) {
                $score += 4;
                break;
            }
        }

        if ($answerBlocks->isNotEmpty()) {
            $score += 3;
        }

        return min(20, $score);
    }

    private function scoreStructure(string $html, array $headings, Collection $answerBlocks): int
    {
        $score = 0;
        $h2Count = collect($headings)->where('level', 2)->count();
        $h3Count = collect($headings)->where('level', 3)->count();

        $score += min(8, ($h2Count * 3) + ($h3Count * 2));

        if (preg_match('/<(ul|ol)[^>]*>/i', $html) === 1) {
            $score += 5;
        }

        if (preg_match('/faq|frequently asked questions|veelgestelde vragen/i', $html) === 1 || $answerBlocks->count() >= 3) {
            $score += 7;
        }

        return min(20, $score);
    }

    private function scoreSemanticCoverage(string $plain, string $title, string $keyword, array $headings): int
    {
        $terms = collect(explode(' ', Str::of($keyword !== '' ? $keyword : $title)->lower()->replaceMatches('/[^a-z0-9\s-]/i', ' ')->value()))
            ->map(fn (string $term): string => trim($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4)
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return min(20, max(6, count($headings) * 2));
        }

        $plainLower = Str::lower($plain);
        $hits = $terms->filter(fn (string $term): bool => str_contains($plainLower, $term))->count();
        $coverage = (int) round(($hits / max(1, $terms->count())) * 12);
        $completeness = min(8, count($headings) * 2);

        return min(20, $coverage + $completeness);
    }

    private function scoreEntityUsage(string $plain, Collection $answerBlocks): int
    {
        preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*|[A-Z]{2,})\b/u', $plain, $matches);
        $entities = collect($matches[0] ?? [])
            ->map(fn (string $entity): string => trim($entity))
            ->filter(fn (string $entity): bool => mb_strlen($entity) >= 2)
            ->merge(
                $answerBlocks
                    ->pluck('entities')
                    ->flatten()
                    ->map(fn (mixed $entity): string => trim((string) $entity))
            )
            ->filter()
            ->unique();

        return min(15, ($entities->count() * 2) + ($answerBlocks->isNotEmpty() ? 3 : 0));
    }

    private function scoreReadability(string $plain): int
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $plain) ?: [];
        $sentences = collect($sentences)
            ->map(fn (string $sentence): string => trim($sentence))
            ->filter()
            ->values();

        if ($sentences->isEmpty()) {
            return 0;
        }

        $avgWords = (float) $sentences
            ->map(fn (string $sentence): int => str_word_count($sentence))
            ->avg();

        if ($avgWords <= 16) {
            return 10;
        }

        if ($avgWords <= 22) {
            return 8;
        }

        if ($avgWords <= 28) {
            return 5;
        }

        return 2;
    }

    private function scoreLlmFormatting(string $html, string $plain, Collection $answerBlocks): int
    {
        $score = 0;

        if (preg_match('/<(ul|ol|table|blockquote)[^>]*>/i', $html) === 1) {
            $score += 4;
        }

        if ($answerBlocks->isNotEmpty()) {
            $score += 6;
        }

        if (preg_match('/summary|samenvatting/i', $plain) === 1) {
            $score += 3;
        }

        if (preg_match('/\?|vraag|question/i', $plain) === 1) {
            $score += 2;
        }

        return min(15, $score);
    }

    /**
     * @return array<int,string>
     */
    private function buildImprovements(array $breakdown, Collection $answerBlocks, string $plain, array $headings): array
    {
        $improvements = [];

        if (($breakdown['answer_clarity'] ?? 0) < 14) {
            $improvements[] = 'Add direct answer under H1';
        }

        if (($breakdown['structure'] ?? 0) < 14) {
            $improvements[] = 'Add structured H2/H3 sections and bullet lists';
        }

        if (($breakdown['semantic_coverage'] ?? 0) < 14) {
            $improvements[] = 'Expand keyword variants and topic coverage';
        }

        if (($breakdown['entity_usage'] ?? 0) < 10) {
            $improvements[] = 'Use more entity references for tools, brands, and concepts';
        }

        if (($breakdown['readability'] ?? 0) < 7) {
            $improvements[] = 'Shorten sentences and improve scanability';
        }

        if (($breakdown['llm_formatting'] ?? 0) < 10) {
            $improvements[] = 'Add summary, answer blocks, and list-based formatting';
        }

        if ($answerBlocks->count() < 3) {
            $improvements[] = 'Create at least 3 structured answer blocks';
        }

        if (count($headings) < 2) {
            $improvements[] = 'Use more supporting sections below the introduction';
        }

        if (! str_contains(Str::lower($plain), 'faq')) {
            $improvements[] = 'Include FAQ section';
        }

        return array_values(array_unique($improvements));
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
