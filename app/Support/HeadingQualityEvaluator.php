<?php

namespace App\Support;

use Illuminate\Support\Str;

class HeadingQualityEvaluator
{
    public const MIN_SCORE = 70;

    /**
     * @return array{score:int,passed:bool,headings:array<int,array<string,mixed>>,issues:array<int,string>}
     */
    public function evaluateResult(array $result, array $context = []): array
    {
        $headings = [];

        foreach ((array) ($result['sections'] ?? []) as $index => $section) {
            $sectionHeading = trim((string) data_get($section, 'heading', ''));
            if ($sectionHeading !== '') {
                $headings[] = [
                    'level' => 2,
                    'text' => $sectionHeading,
                    'source' => 'sections.' . $index . '.heading',
                ];
            }

            $html = (string) data_get($section, 'html', '');
            foreach ($this->extractHtmlHeadings($html) as $htmlHeading) {
                $headings[] = $htmlHeading + [
                    'source' => 'sections.' . $index . '.html',
                ];
            }
        }

        return $this->evaluateHeadings($headings, $context);
    }

    /**
     * @param array<int,array<string,mixed>> $headings
     * @return array{score:int,passed:bool,headings:array<int,array<string,mixed>>,issues:array<int,string>}
     */
    public function evaluateHeadings(array $headings, array $context = []): array
    {
        $primaryKeyword = $this->normalize((string) ($context['primary_keyword'] ?? ''));
        $secondaryKeywords = collect((array) ($context['secondary_keywords'] ?? []))
            ->map(fn (mixed $keyword): string => $this->normalize((string) $keyword))
            ->filter()
            ->values();
        $searchIntent = $this->normalize(implode(' ', (array) ($context['intent_keys'] ?? [])));

        $evaluated = collect($headings)
            ->map(function (array $heading) use ($primaryKeyword, $secondaryKeywords, $searchIntent): array {
                $text = trim((string) ($heading['text'] ?? ''));
                $score = $this->scoreHeading($text, $primaryKeyword, $secondaryKeywords->all(), $searchIntent);
                $blockingIssue = $this->blockingIssue($text);

                return array_merge($heading, [
                    'text' => $text,
                    'score' => $score,
                    'passed' => $blockingIssue === null && $score >= self::MIN_SCORE,
                    'issue' => $blockingIssue,
                ]);
            })
            ->filter(fn (array $heading): bool => $heading['text'] !== '')
            ->values();

        $issues = $evaluated
            ->filter(fn (array $heading): bool => ! (bool) $heading['passed'])
            ->map(function (array $heading): string {
                $issue = trim((string) ($heading['issue'] ?? ''));
                $reason = $issue !== '' ? $issue : 'low editorial quality score';

                return sprintf('"%s" failed heading quality (%s, score %d).', $heading['text'], $reason, (int) $heading['score']);
            })
            ->values()
            ->all();

        $score = $evaluated->isEmpty()
            ? 0
            : (int) round($evaluated->avg('score'));

        return [
            'score' => $score,
            'passed' => $evaluated->isNotEmpty() && $issues === [],
            'headings' => $evaluated->all(),
            'issues' => $issues,
        ];
    }

    public function promptGuidance(): string
    {
        return implode("\n", [
            'Heading editorial rules:',
            '- Every H2 and H3 must communicate what the section is actually about, not the role of the section.',
            '- Use descriptive, native-editor headings with meaningful topical keywords and clear search intent.',
            '- Do not use generic AI structural labels such as Introduction, Inleiding, Main Section, Hoofdsectie, Section 1, Sectie 1, Key Takeaways, Belangrijkste punten, Summary, Samenvatting, Conclusion, Conclusie, Final Thoughts, Eindgedachten, or Closing Remarks.',
            '- Do not prefix real headings with labels such as "Main Section:", "Hoofdsectie:", "Section 1:", or "Sectie 1:".',
            '- The closing section must have a contextual title, for example what the reader should do next or what the topic means strategically. Never title it simply "Conclusion" or "Conclusie".',
            '- Prefer headings like "What autonomous marketing really means" over "Definition", and "Why autonomous marketing improves marketing performance" over "Benefits".',
            '- Before returning JSON, run a heading quality pass and rewrite any heading below 70/100 for specificity, keyword relevance, natural language, editorial quality, and reader curiosity.',
        ]);
    }

    /**
     * @return array<int,array{level:int,text:string}>
     */
    private function extractHtmlHeadings(string $html): array
    {
        if ($html === '') {
            return [];
        }

        preg_match_all('/<h([2-3])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match): array => [
                'level' => (int) $match[1],
                'text' => trim(strip_tags((string) $match[2])),
            ])
            ->filter(fn (array $heading): bool => $heading['text'] !== '')
            ->values()
            ->all();
    }

    private function scoreHeading(string $heading, string $primaryKeyword, array $secondaryKeywords, string $searchIntent): int
    {
        $normalized = $this->normalize($heading);
        $wordCount = str_word_count($heading);
        $score = 20;

        $score += $wordCount >= 4 ? 20 : ($wordCount >= 3 ? 12 : 0);
        $score += $this->blockingIssue($heading) === null ? 20 : 0;
        $score += $primaryKeyword === '' ? 10 : (Str::contains($normalized, $primaryKeyword) ? 15 : 0);
        $score += $secondaryKeywords === [] ? 4 : (collect($secondaryKeywords)->contains(fn (string $keyword): bool => $keyword !== '' && Str::contains($normalized, $keyword)) ? 8 : 0);
        $score += $searchIntent !== '' && collect(explode(' ', $searchIntent))->filter()->contains(fn (string $term): bool => Str::contains($normalized, $term)) ? 5 : 0;
        $score += preg_match('/\b(why|what|how|when|waarom|wat|hoe|wanneer|pourquoi|comment|was|wie|warum|como|por que|cu[aá]ndo)\b/iu', $heading) === 1 ? 8 : 0;
        $score += $this->hasSpecificSignal($heading) ? 12 : 0;

        if ($wordCount > 14) {
            $score -= 8;
        }

        if (preg_match('/\b(section|sectie|chapter|hoofdstuk|part|deel)\b\s*\d*/iu', $heading) === 1) {
            $score -= 15;
        }

        return max(0, min(100, $score));
    }

    private function blockingIssue(string $heading): ?string
    {
        $normalized = $this->normalize($heading);

        $patterns = [
            '/^conclusion$/iu',
            '/^conclusie$/iu',
            '/^summary$/iu',
            '/^samenvatting$/iu',
            '/^introduction$/iu',
            '/^inleiding$/iu',
            '/^main section$/iu',
            '/^hoofdsectie$/iu',
            '/^section\s+[0-9]+$/iu',
            '/^sectie\s+[0-9]+$/iu',
            '/^key takeaways$/iu',
            '/^belangrijkste punten$/iu',
            '/^final thoughts$/iu',
            '/^eindgedachten$/iu',
            '/^closing remarks$/iu',
            '/^(main section|hoofdsectie|section\s+[0-9]+|sectie\s+[0-9]+)\s*:/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return 'generic structural label';
            }
        }

        return null;
    }

    private function hasSpecificSignal(string $heading): bool
    {
        return preg_match('/[0-9]|:|\b(strategy|performance|implementation|organization|marketing|visibility|automation|content|seo|b2b|strategie|prestaties|implementatie|organisatie|zichtbaarheid|automatisering|inhoud)\b/iu', $heading) === 1;
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }
}
