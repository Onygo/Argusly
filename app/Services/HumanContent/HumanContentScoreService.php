<?php

namespace App\Services\HumanContent;

use App\Models\BrandVoice;
use App\Models\Content;
use App\Models\Draft;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Models\WriterProfile;
use Illuminate\Support\Str;

class HumanContentScoreService
{
    public const VERSION = 'human-content-score.v1';

    private const DIMENSIONS = [
        'editorial_quality_score',
        'originality_score',
        'narrative_flow_score',
        'human_voice_score',
        'expertise_score',
        'insight_density_score',
        'evidence_usage_score',
        'rhythm_score',
        'curiosity_score',
        'ai_fingerprint_score',
        'uniqueness_score',
    ];

    public function __construct(
        private ?AiFingerprintDetector $aiFingerprintDetector = null,
        private ?CorpusDiversityService $corpusDiversity = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scoreForDraft(Draft $draft): array
    {
        return $this->scoreForDraftHtml($draft, (string) $draft->content_html, (string) $draft->title);
    }

    /**
     * @return array<string,mixed>
     */
    public function scoreForDraftHtml(Draft $draft, string $html, ?string $title = null): array
    {
        $draft->loadMissing([
            'brief.researchProjects.findings',
            'content.workspace',
            'content.brandVoice',
            'content.writerProfile',
            'clientSite.workspace',
        ]);

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $brief = $draft->brief;
        $content = $draft->content;
        $workspace = $content?->workspace ?: $draft->clientSite?->workspace;

        return $this->score(
            html: $html,
            title: (string) ($title ?: $draft->title),
            briefMetadata: [
                'primary_keyword' => (string) ($brief?->primary_keyword ?? data_get($meta, 'primary_keyword', '')),
                'secondary_keywords' => (array) ($brief?->secondary_keywords ?? data_get($meta, 'secondary_keywords', [])),
                'audience' => (string) ($brief?->target_audience ?: $brief?->audience ?: data_get($meta, 'audience', '')),
                'search_intent' => (string) ($brief?->search_intent ?? data_get($meta, 'search_intent', '')),
                'funnel_stage' => (string) ($brief?->funnel_stage ?? data_get($meta, 'funnel_stage', '')),
                'key_points' => (array) ($brief?->key_points ?? data_get($meta, 'key_points', [])),
                'language' => (string) ($brief?->language ?? data_get($meta, 'language', 'en')),
            ],
            editorialPlan: (array) data_get($meta, 'editorial_plan', []),
            brandVoice: $this->brandVoiceContext($content?->brandVoice),
            writerProfile: $this->writerProfileContext($content?->writerProfile),
            researchSummary: $this->researchContext($brief?->researchProjects ?? collect()),
            recentRelatedContent: $this->recentRelatedContent($draft, $workspace?->id, (string) ($brief?->primary_keyword ?? data_get($meta, 'primary_keyword', $draft->title))),
            corpusDiversity: $this->corpusDiversityService()->analyzeDraft($draft, $html, (string) ($title ?: $draft->title)),
        );
    }

    /**
     * @param array<string,mixed> $briefMetadata
     * @param array<string,mixed> $editorialPlan
     * @param array<string,mixed> $brandVoice
     * @param array<string,mixed> $writerProfile
     * @param array<string,mixed> $researchSummary
     * @param array<int,array<string,string>> $recentRelatedContent
     * @param array<string,mixed> $corpusDiversity
     * @return array<string,mixed>
     */
    public function score(
        string $html,
        string $title,
        array $briefMetadata = [],
        array $editorialPlan = [],
        array $brandVoice = [],
        array $writerProfile = [],
        array $researchSummary = [],
        array $recentRelatedContent = [],
        array $corpusDiversity = [],
    ): array {
        $text = $this->normalizeWhitespace(strip_tags($html));
        $paragraphs = $this->paragraphs($html);
        $headings = $this->headings($html);
        $wordCount = str_word_count($text);
        $aiFingerprint = $this->fingerprintDetector()->detect($html, (string) data_get($briefMetadata, 'language', 'en'));
        $genericSignals = $this->fingerprintSignals($aiFingerprint);
        $evidenceSignals = $this->evidenceSignals($text, $researchSummary);
        $exampleCount = $this->countMatches($text, '/\b(for example|e\.g\.|case|scenario|when a team|in practice|for instance|such as)\b/i');
        $nuanceCount = $this->countMatches($text, '/\b(however|but|although|tradeoff|caveat|risk|constraint|depends|on the other hand)\b/i');
        $actionCount = $this->countMatches($text, '/\b(should|recommend|next step|decide|choose|evaluate|prioritize|measure|review|apply)\b/i');
        $questionCount = substr_count($text, '?') + $this->countMatches($text, '/\b(why|how|what if|the question is)\b/i');
        $planAlignment = $this->planAlignment($text, $title, $editorialPlan);
        $rhythm = $this->rhythmSignals($paragraphs, $headings, $html);
        $corpusDiversity = $corpusDiversity !== []
            ? $corpusDiversity
            : $this->corpusDiversityService()->analyze($html, $title, $recentRelatedContent);
        $uniquenessPenalty = max(
            (int) data_get($corpusDiversity, 'penalty', 0),
            $this->relatedContentOverlapPenalty($title, $text, $recentRelatedContent)
        );

        $scores = [
            'editorial_quality_score' => $this->clamp(48 + $planAlignment + min(12, $nuanceCount * 3) + min(10, $actionCount * 2) - min(20, count($genericSignals) * 4)),
            'originality_score' => $this->clamp(58 + min(16, $planAlignment) + min(10, $exampleCount * 3) - $uniquenessPenalty - min(14, count($genericSignals) * 3)),
            'narrative_flow_score' => $this->clamp(45 + $rhythm['flow_bonus'] + min(12, count($headings) * 2) + min(8, $nuanceCount * 2)),
            'human_voice_score' => $this->clamp(46 + min(14, $exampleCount * 4) + min(12, $actionCount * 2) + $this->voiceFitBonus($text, $brandVoice, $writerProfile) - min(18, count($genericSignals) * 4)),
            'expertise_score' => $this->clamp(44 + min(18, $evidenceSignals * 5) + min(14, $nuanceCount * 3) + $this->keyPointBonus($text, (array) data_get($briefMetadata, 'key_points', []))),
            'insight_density_score' => $this->clamp(42 + min(20, $this->insightSignals($text) * 4) + min(12, $nuanceCount * 2) - ($wordCount > 0 && $wordCount < 450 ? 8 : 0)),
            'evidence_usage_score' => $this->clamp(38 + min(30, $evidenceSignals * 7) + min(12, $exampleCount * 3)),
            'rhythm_score' => $this->clamp(42 + $rhythm['score_bonus']),
            'curiosity_score' => $this->clamp(40 + min(20, $questionCount * 3) + min(12, $this->countMatches($text, '/\b(not .* but|surprising|usually miss|hidden|what changes)\b/i') * 4)),
            'ai_fingerprint_score' => $this->clamp((int) data_get($aiFingerprint, 'score', 18) - min(12, $exampleCount * 2) - min(8, $nuanceCount)),
            'uniqueness_score' => $this->clamp(62 + min(14, $planAlignment) + min(10, $exampleCount * 3) - $uniquenessPenalty),
        ];

        $humanContentScore = $this->aggregateHumanScore($scores);
        $pass = $humanContentScore >= 70
            && $scores['editorial_quality_score'] >= 65
            && $scores['evidence_usage_score'] >= 55
            && $scores['ai_fingerprint_score'] <= 45;

        return [
            'version' => self::VERSION,
            'status' => $pass ? 'pass' : 'fail',
            'passed' => $pass,
            'human_content_score' => $humanContentScore,
            ...$scores,
            'dimension_breakdown' => $this->dimensionBreakdown($scores),
            'findings' => $this->findings($scores, $genericSignals, $evidenceSignals, $planAlignment, $rhythm, $aiFingerprint, $corpusDiversity),
            'recommendations' => $this->recommendations($scores, $pass, $corpusDiversity),
            'suggested_humanization_actions' => $this->humanizationActions($scores, $genericSignals, $aiFingerprint, $corpusDiversity),
            'ai_fingerprint' => $aiFingerprint,
            'corpus_diversity' => $corpusDiversity,
            'signals' => [
                'word_count' => $wordCount,
                'heading_count' => count($headings),
                'paragraph_count' => count($paragraphs),
                'example_count' => $exampleCount,
                'nuance_count' => $nuanceCount,
                'evidence_signal_count' => $evidenceSignals,
                'generic_signal_count' => count($genericSignals),
                'ai_fingerprint_pattern_count' => (int) data_get($aiFingerprint, 'pattern_count', 0),
                'plan_alignment' => $planAlignment,
                'related_content_overlap_penalty' => $uniquenessPenalty,
                'corpus_diversity_score' => (int) data_get($corpusDiversity, 'score', 100),
                'corpus_diversity_risk_score' => (int) data_get($corpusDiversity, 'risk_score', 0),
            ],
        ];
    }

    /**
     * @param array<string,int> $scores
     */
    private function aggregateHumanScore(array $scores): int
    {
        $positive = collect($scores)
            ->except('ai_fingerprint_score')
            ->avg();

        return $this->clamp((int) round((float) $positive - max(0, ((int) $scores['ai_fingerprint_score'] - 25) * 0.45)));
    }

    /**
     * @param array<string,int> $scores
     * @return array<string,array<string,mixed>>
     */
    private function dimensionBreakdown(array $scores): array
    {
        return collect(self::DIMENSIONS)
            ->mapWithKeys(fn (string $key): array => [$key => [
                'score' => $scores[$key],
                'band' => $this->band($scores[$key], $key === 'ai_fingerprint_score'),
                'direction' => $key === 'ai_fingerprint_score' ? 'lower_is_better' : 'higher_is_better',
            ]])
            ->all();
    }

    /**
     * @param array<string,int> $scores
     * @param array<int,string> $genericSignals
     * @param array<string,mixed> $rhythm
     * @return array<int,string>
     */
    private function findings(array $scores, array $genericSignals, int $evidenceSignals, int $planAlignment, array $rhythm, array $aiFingerprint, array $corpusDiversity): array
    {
        return collect([
            $planAlignment >= 14 ? 'The draft reflects the Editorial Plan and carries a recognizable thesis.' : 'The draft only weakly reflects the Editorial Plan or central thesis.',
            $evidenceSignals >= 3 ? 'The draft uses concrete evidence signals, examples, or research-backed observations.' : 'Evidence is thin; claims need more examples, data, or observed constraints.',
            $scores['ai_fingerprint_score'] > 45 ? 'The draft contains AI-like generic phrasing or overly uniform structure.' : 'The draft avoids most common AI fingerprint signals.',
            (int) ($rhythm['paragraph_variance'] ?? 0) > 18 ? 'Paragraph and section rhythm varies enough to feel edited.' : 'The rhythm is still uniform and would benefit from deliberate pacing.',
            $genericSignals !== [] ? 'Generic signals found: ' . implode(', ', array_slice($genericSignals, 0, 4)) . '.' : null,
            ...collect((array) data_get($aiFingerprint, 'findings', []))
                ->take(3)
                ->map(fn (array $finding): string => trim((string) ($finding['message'] ?? '') . ' ' . (string) ($finding['evidence'] ?? '')))
                ->filter()
                ->all(),
            ...collect((array) data_get($corpusDiversity, 'findings', []))
                ->take(3)
                ->map(fn (array $finding): string => trim((string) ($finding['message'] ?? '') . ' ' . (string) ($finding['evidence'] ?? '')))
                ->filter()
                ->all(),
        ])->filter()->values()->all();
    }

    /**
     * @param array<string,int> $scores
     * @return array<int,string>
     */
    private function recommendations(array $scores, bool $pass, array $corpusDiversity = []): array
    {
        return collect([
            $pass ? 'Keep the current editorial direction and polish only for precision.' : 'Run a humanization pass before publication.',
            $scores['evidence_usage_score'] < 65 ? 'Add a concrete example, observed pattern, metric, or research-backed proof point to each major claim.' : null,
            $scores['expertise_score'] < 65 ? 'Add practitioner judgment: tradeoffs, constraints, and what an experienced operator would watch for.' : null,
            $scores['rhythm_score'] < 65 ? 'Vary paragraph length and section pacing; mix explanation, examples, and recommendations.' : null,
            $scores['ai_fingerprint_score'] > 45 ? 'Replace generic AI phrasing with specific nouns, situations, and business consequences.' : null,
            ...((array) data_get($corpusDiversity, 'recommendations', [])),
        ])->filter()->values()->all();
    }

    /**
     * @param array<string,int> $scores
     * @param array<int,string> $genericSignals
     * @return array<int,string>
     */
    private function humanizationActions(array $scores, array $genericSignals, array $aiFingerprint, array $corpusDiversity = []): array
    {
        return collect([
            'Rewrite the opening around the central thesis and reader tension instead of broad category context.',
            $scores['evidence_usage_score'] < 70 ? 'Insert one specific example, metric, or field observation in the weakest section.' : null,
            $scores['curiosity_score'] < 65 ? 'Add one question, contrast, or expectation reversal that gives the reader a reason to continue.' : null,
            $scores['human_voice_score'] < 65 ? 'Add a practical consultant sentence that says what the reader should do and why.' : null,
            $genericSignals !== [] ? 'Remove or rewrite these generic phrases: ' . implode(', ', array_slice($genericSignals, 0, 3)) . '.' : null,
            ...((array) data_get($aiFingerprint, 'humanization_actions', [])),
            ...((array) data_get($corpusDiversity, 'humanization_actions', [])),
        ])->filter()->values()->all();
    }

    /**
     * @param array<string,mixed> $aiFingerprint
     * @return array<int,string>
     */
    private function fingerprintSignals(array $aiFingerprint): array
    {
        return collect((array) data_get($aiFingerprint, 'findings', []))
            ->map(function (array $finding): string {
                $evidence = trim((string) ($finding['evidence'] ?? ''));
                $type = trim((string) ($finding['type'] ?? 'ai_fingerprint'));

                return $evidence !== '' ? "{$type}: {$evidence}" : $type;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $editorialPlan
     */
    private function planAlignment(string $text, string $title, array $editorialPlan): int
    {
        $planText = implode(' ', array_filter([
            (string) data_get($editorialPlan, 'central_thesis', ''),
            (string) data_get($editorialPlan, 'unique_angle', ''),
            (string) data_get($editorialPlan, 'reader_misconception', ''),
            (string) data_get($editorialPlan, 'expected_reader_takeaway', ''),
            (string) data_get($editorialPlan, 'primary_pattern.name', ''),
        ]));

        $terms = collect(explode(' ', Str::lower($planText . ' ' . $title)))
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B.,:;!?()[]{}\"'"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 6)
            ->unique()
            ->take(12);

        if ($terms->isEmpty()) {
            return 0;
        }

        $haystack = Str::lower($text);
        $matches = $terms->filter(fn (string $term): bool => str_contains($haystack, $term))->count();

        return min(22, (int) round(($matches / max(1, $terms->count())) * 22));
    }

    private function evidenceSignals(string $text, array $researchSummary): int
    {
        $signals = $this->countMatches($text, '/\b(\d+[%x]?|data|research|observed|evidence|study|survey|benchmark|pattern|tracked|measured)\b/i');
        $researchTerms = collect((array) data_get($researchSummary, 'insights', []))
            ->flatMap(fn (string $insight): array => explode(' ', $insight))
            ->map(fn (string $term): string => Str::lower(trim($term, ".,:;!?()[]{}\"'")))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 7)
            ->unique()
            ->take(8);

        $haystack = Str::lower($text);
        $signals += $researchTerms->filter(fn (string $term): bool => str_contains($haystack, $term))->count();

        return $signals;
    }

    private function insightSignals(string $text): int
    {
        return $this->countMatches($text, '/\b(means|because|therefore|reveals|suggests|indicates|matters|changes|tradeoff|constraint|decision)\b/i');
    }

    /**
     * @param array<int,string> $paragraphs
     * @param array<int,string> $headings
     * @return array<string,int>
     */
    private function rhythmSignals(array $paragraphs, array $headings, string $html): array
    {
        $lengths = collect($paragraphs)->map(fn (string $p): int => str_word_count($p))->filter();
        $variance = $lengths->count() > 1 ? (int) ($lengths->max() - $lengths->min()) : 0;
        $hasList = preg_match('/<(ul|ol)\b/i', $html) === 1;
        $scoreBonus = min(20, $variance) + min(12, count($headings) * 2) + ($hasList ? 8 : 0);

        return [
            'paragraph_variance' => $variance,
            'flow_bonus' => min(18, (int) round($scoreBonus * 0.55)),
            'score_bonus' => min(42, $scoreBonus),
        ];
    }

    private function uniformityPenalty(array $paragraphs, array $headings): int
    {
        $lengths = collect($paragraphs)->map(fn (string $p): int => str_word_count($p))->filter();
        $variance = $lengths->count() > 1 ? (int) ($lengths->max() - $lengths->min()) : 0;

        return ($variance < 12 ? 14 : 0) + (count($headings) <= 2 ? 8 : 0);
    }

    /**
     * @param array<int,string> $headings
     * @return array<int,string>
     */
    private function genericSignals(string $text, array $headings): array
    {
        $signals = [];
        foreach ([
            'in today\'s digital landscape',
            'unlock the power',
            'game changer',
            'delve into',
            'robust solution',
            'seamless experience',
            'it is important to note',
            'in conclusion',
            'overall',
        ] as $phrase) {
            if (str_contains(Str::lower($text), $phrase)) {
                $signals[] = $phrase;
            }
        }

        foreach ($headings as $heading) {
            if (preg_match('/^(introduction|main section|key takeaways|summary|conclusion|final thoughts)$/i', trim($heading)) === 1) {
                $signals[] = 'generic heading: ' . $heading;
            }
        }

        return array_values(array_unique($signals));
    }

    private function voiceFitBonus(string $text, array $brandVoice, array $writerProfile): int
    {
        $tone = Str::lower((string) data_get($brandVoice, 'tone', '') . ' ' . (string) data_get($writerProfile, 'summary', ''));
        $bonus = 0;
        if ($tone !== '' && str_contains($tone, 'practical')) {
            $bonus += $this->countMatches($text, '/\b(practical|in practice|apply|use|decision)\b/i') > 0 ? 5 : 0;
        }
        if ($tone !== '' && str_contains($tone, 'direct')) {
            $bonus += $this->countMatches($text, '/\b(should|need|avoid|choose)\b/i') > 0 ? 4 : 0;
        }

        return $bonus;
    }

    private function keyPointBonus(string $text, array $keyPoints): int
    {
        $haystack = Str::lower($text);

        return min(10, collect($keyPoints)
            ->flatMap(fn (mixed $point): array => explode(' ', (string) $point))
            ->map(fn (string $term): string => Str::lower(trim($term, ".,:;!?()[]{}\"'")))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 7)
            ->unique()
            ->filter(fn (string $term): bool => str_contains($haystack, $term))
            ->count() * 2);
    }

    private function fingerprintDetector(): AiFingerprintDetector
    {
        return $this->aiFingerprintDetector ??= app(AiFingerprintDetector::class);
    }

    private function corpusDiversityService(): CorpusDiversityService
    {
        return $this->corpusDiversity ??= app(CorpusDiversityService::class);
    }

    private function relatedContentOverlapPenalty(string $title, string $text, array $recentRelatedContent): int
    {
        $currentTerms = collect(explode(' ', Str::lower($title . ' ' . Str::limit($text, 500, ''))))
            ->map(fn (string $term): string => trim($term, ".,:;!?()[]{}\"'"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 6)
            ->unique();

        $maxOverlap = 0;
        foreach ($recentRelatedContent as $content) {
            $terms = collect(explode(' ', Str::lower((string) ($content['title'] ?? '') . ' ' . (string) ($content['primary_keyword'] ?? ''))))
                ->map(fn (string $term): string => trim($term, ".,:;!?()[]{}\"'"))
                ->filter(fn (string $term): bool => mb_strlen($term) >= 6)
                ->unique();
            $maxOverlap = max($maxOverlap, $terms->intersect($currentTerms)->count());
        }

        return min(18, $maxOverlap * 4);
    }

    private function brandVoiceContext(?BrandVoice $brandVoice): array
    {
        return [
            'tone' => (string) ($brandVoice?->tone_of_voice ?? $brandVoice?->default_tone ?? ''),
            'style' => (string) ($brandVoice?->writing_style ?? $brandVoice?->style_guide ?? ''),
            'do_not' => (string) ($brandVoice?->dont_rules ?? ''),
        ];
    }

    private function writerProfileContext(?WriterProfile $writerProfile): array
    {
        return [
            'summary' => (string) ($writerProfile?->tone_summary ?? $writerProfile?->writing_style_summary ?? ''),
            'structure' => (string) ($writerProfile?->structure_summary ?? ''),
        ];
    }

    private function researchContext(mixed $projects): array
    {
        $project = collect($projects instanceof \Illuminate\Support\Collection ? $projects : [])->sortByDesc('updated_at')->first();
        if (! $project instanceof ResearchProject) {
            return ['insights' => []];
        }

        $summary = is_array($project->summary) ? $project->summary : [];

        return [
            'insights' => collect($project->findings ?? [])
                ->map(fn (ResearchFinding $finding): string => (string) $finding->finding_text)
                ->merge((array) data_get($summary, 'highlights.insights', []))
                ->merge((array) data_get($summary, 'highlights.statistics', []))
                ->filter()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    private function recentRelatedContent(Draft $draft, ?string $workspaceId, string $topic): array
    {
        if (! $workspaceId) {
            return [];
        }

        $terms = collect(explode(' ', Str::lower($topic)))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 5)
            ->take(5);

        return Content::query()
            ->where('workspace_id', $workspaceId)
            ->when($draft->content_id, fn ($query) => $query->where('id', '!=', (string) $draft->content_id))
            ->latest('updated_at')
            ->limit(30)
            ->get(['title', 'primary_keyword'])
            ->filter(function (Content $content) use ($terms): bool {
                $haystack = Str::lower((string) $content->title . ' ' . (string) $content->primary_keyword);

                return $terms->isEmpty() || $terms->contains(fn (string $term): bool => str_contains($haystack, $term));
            })
            ->take(5)
            ->map(fn (Content $content): array => [
                'title' => (string) $content->title,
                'primary_keyword' => (string) $content->primary_keyword,
            ])
            ->values()
            ->all();
    }

    private function headings(string $html): array
    {
        preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $html, $matches);

        return collect($matches[1] ?? [])->map(fn (string $h): string => trim(strip_tags($h)))->filter()->values()->all();
    }

    private function paragraphs(string $html): array
    {
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches);
        $paragraphs = collect($matches[1] ?? [])->map(fn (string $p): string => $this->normalizeWhitespace(strip_tags($p)))->filter()->values()->all();

        return $paragraphs !== [] ? $paragraphs : [$this->normalizeWhitespace(strip_tags($html))];
    }

    private function sentences(string $text): array
    {
        return collect(preg_split('/(?<=[.!?])\s+/', $text) ?: [])->map(fn (string $s): string => trim($s))->filter()->values()->all();
    }

    private function countMatches(string $text, string $pattern): int
    {
        preg_match_all($pattern, $text, $matches);

        return count($matches[0] ?? []);
    }

    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function band(int $score, bool $lowerIsBetter = false): string
    {
        if ($lowerIsBetter) {
            return $score <= 25 ? 'low' : ($score <= 45 ? 'moderate' : 'high');
        }

        return $score >= 80 ? 'strong' : ($score >= 65 ? 'usable' : ($score >= 45 ? 'weak' : 'poor'));
    }

    private function clamp(int|float $score): int
    {
        return max(0, min(100, (int) round($score)));
    }
}
