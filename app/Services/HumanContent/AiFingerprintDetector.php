<?php

namespace App\Services\HumanContent;

use App\Support\HeadingQualityEvaluator;
use Illuminate\Support\Str;

class AiFingerprintDetector
{
    public const VERSION = 'ai-fingerprint-detector.v1';

    public function __construct(
        private readonly HeadingQualityEvaluator $headingQualityEvaluator,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function detect(string $html, string $locale = 'en'): array
    {
        $locale = $this->normalizeLocale($locale);
        $plain = $this->normalizeWhitespace(strip_tags($html));
        $lower = Str::lower($plain);
        $headings = $this->headings($html);
        $paragraphs = $this->paragraphs($html);
        $sections = $this->sectionWordCounts($html);
        $findings = [];

        $findings = array_merge($findings, $this->detectGenericHeadings($headings));
        $findings = array_merge($findings, $this->detectPhraseLibrary($lower, $locale));
        $findings = array_merge($findings, $this->detectPredictableOpening($paragraphs, $locale));
        $findings = array_merge($findings, $this->detectPredictableEnding($paragraphs, $locale));
        $findings = array_merge($findings, $this->detectRepeatedSectionStructures($html));
        $findings = array_merge($findings, $this->detectUniformParagraphLengths($paragraphs));
        $findings = array_merge($findings, $this->detectEqualSectionSizes($sections));
        $findings = array_merge($findings, $this->detectListOveruse($html));
        $findings = array_merge($findings, $this->detectRepeatedCtaLanguage($lower, $locale));
        $findings = array_merge($findings, $this->detectDensityPattern($plain, $locale, 'definition_heavy_writing'));
        $findings = array_merge($findings, $this->detectDensityPattern($plain, $locale, 'summary_heavy_writing'));
        $findings = array_merge($findings, $this->detectFaqOveruse($html, $locale));
        $findings = array_merge($findings, $this->detectDensityPattern($plain, $locale, 'recommendation_overuse'));
        $findings = array_merge($findings, $this->detectDensityPattern($plain, $locale, 'overly_balanced_phrasing'));
        $findings = array_merge($findings, $this->detectEmptyFiller($lower, $locale));

        $score = $this->riskScore($findings);

        return [
            'version' => self::VERSION,
            'locale' => $locale,
            'score' => $score,
            'severity' => $this->severity($score),
            'passed' => $score <= 45,
            'pattern_count' => count($findings),
            'findings' => $findings,
            'humanization_actions' => collect($findings)
                ->pluck('humanization_action')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'signals' => [
                'heading_count' => count($headings),
                'paragraph_count' => count($paragraphs),
                'section_count' => count($sections),
                'bullet_list_count' => preg_match_all('/<ul\b/i', $html),
                'numbered_list_count' => preg_match_all('/<ol\b/i', $html),
            ],
        ];
    }

    /**
     * @param array<int,string> $headings
     * @return array<int,array<string,mixed>>
     */
    private function detectGenericHeadings(array $headings): array
    {
        $evaluation = $this->headingQualityEvaluator->evaluateHeadings(
            collect($headings)->map(fn (string $heading): array => ['level' => 2, 'text' => $heading])->all(),
        );

        return collect((array) ($evaluation['headings'] ?? []))
            ->filter(fn (array $heading): bool => ! (bool) ($heading['passed'] ?? false) && trim((string) ($heading['issue'] ?? '')) !== '')
            ->map(fn (array $heading): array => $this->finding(
                type: 'generic_headings',
                severity: 'high',
                message: 'Generic structural heading detected.',
                evidence: (string) ($heading['text'] ?? ''),
                count: 1,
                action: 'Replace structural labels with section titles that express the argument or decision in that section.',
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectPhraseLibrary(string $lower, string $locale): array
    {
        $findings = [];
        foreach (['generic_transitions', 'chatgpt_vocabulary', 'marketing_cliches'] as $type) {
            $matches = $this->phraseMatches($lower, $this->phrases($locale, $type));
            if ($matches !== []) {
                $findings[] = $this->finding(
                    type: $type,
                    severity: count($matches) >= 3 ? 'high' : 'medium',
                    message: $this->labelFor($type) . ' found.',
                    evidence: implode(', ', array_slice($matches, 0, 5)),
                    count: count($matches),
                    action: 'Replace broad stock phrasing with concrete nouns, constraints, examples, or observed business consequences.',
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<int,string> $paragraphs
     * @return array<int,array<string,mixed>>
     */
    private function detectPredictableOpening(array $paragraphs, string $locale): array
    {
        $opening = Str::lower((string) ($paragraphs[0] ?? ''));
        $matches = $this->phraseMatches($opening, $this->phrases($locale, 'predictable_openings'));

        return $matches === [] ? [] : [$this->finding(
            type: 'predictable_openings',
            severity: 'high',
            message: 'Predictable article opening detected.',
            evidence: implode(', ', $matches),
            count: count($matches),
            action: 'Rewrite the opening around the reader tension, thesis, or a specific field observation.',
        )];
    }

    /**
     * @param array<int,string> $paragraphs
     * @return array<int,array<string,mixed>>
     */
    private function detectPredictableEnding(array $paragraphs, string $locale): array
    {
        $ending = Str::lower((string) end($paragraphs));
        $matches = $this->phraseMatches($ending, $this->phrases($locale, 'predictable_endings'));

        return $matches === [] ? [] : [$this->finding(
            type: 'predictable_endings',
            severity: 'medium',
            message: 'Predictable article ending detected.',
            evidence: implode(', ', $matches),
            count: count($matches),
            action: 'Close with a concrete implication, decision, or next move instead of a template-like recap.',
        )];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectRepeatedSectionStructures(string $html): array
    {
        preg_match_all('/<h[2-3]\b[^>]*>.*?<\/h[2-3]>\s*(<p\b[^>]*>.*?<\/p>\s*)?(<(ul|ol)\b.*?<\/\3>\s*)?/is', $html, $matches);
        $structures = collect($matches[0] ?? [])
            ->map(function (string $section): string {
                $shape = [];
                preg_match_all('/<(h[2-3]|p|ul|ol)\b/i', $section, $tags);
                foreach ($tags[1] ?? [] as $tag) {
                    $shape[] = strtolower((string) $tag);
                }

                return implode('-', $shape);
            })
            ->filter()
            ->values();

        $mostCommon = $structures->countBy()->max() ?: 0;
        if ($structures->count() >= 4 && $mostCommon >= 3) {
            return [$this->finding(
                type: 'repeated_section_structures',
                severity: 'medium',
                message: 'Several sections use the same heading/paragraph/list shape.',
                evidence: 'Repeated section shape count: ' . $mostCommon,
                count: (int) $mostCommon,
                action: 'Vary section movement: alternate explanation, example, implication, objection, and recommendation.',
            )];
        }

        return [];
    }

    /**
     * @param array<int,string> $paragraphs
     * @return array<int,array<string,mixed>>
     */
    private function detectUniformParagraphLengths(array $paragraphs): array
    {
        $lengths = collect($paragraphs)->map(fn (string $paragraph): int => str_word_count($paragraph))->filter();
        if ($lengths->count() < 4) {
            return [];
        }

        $average = max(1, (float) $lengths->avg());
        $spread = (int) $lengths->max() - (int) $lengths->min();
        if ($spread <= max(10, (int) round($average * 0.25))) {
            return [$this->finding(
                type: 'uniform_paragraph_lengths',
                severity: 'medium',
                message: 'Paragraph lengths are unusually uniform.',
                evidence: 'Paragraph word-count spread: ' . $spread,
                count: $lengths->count(),
                action: 'Deliberately vary pacing with short judgment paragraphs, longer explanation, and concise examples.',
            )];
        }

        return [];
    }

    /**
     * @param array<int,int> $sections
     * @return array<int,array<string,mixed>>
     */
    private function detectEqualSectionSizes(array $sections): array
    {
        $sizes = collect($sections)->filter(fn (int $words): bool => $words > 0);
        if ($sizes->count() < 4) {
            return [];
        }

        $spread = (int) $sizes->max() - (int) $sizes->min();
        if ($spread <= 45) {
            return [$this->finding(
                type: 'equal_section_sizes',
                severity: 'medium',
                message: 'Sections are close to equal size, which can read like a template.',
                evidence: 'Section word-count spread: ' . $spread,
                count: $sizes->count(),
                action: 'Let important sections breathe and compress supporting sections.',
            )];
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectListOveruse(string $html): array
    {
        $findings = [];
        $bulletLists = preg_match_all('/<ul\b/i', $html);
        $numberedLists = preg_match_all('/<ol\b/i', $html);

        if ($bulletLists >= 4) {
            $findings[] = $this->finding('too_many_bullet_lists', 'medium', 'Too many bullet lists detected.', (string) $bulletLists, $bulletLists, 'Convert some lists into narrative explanation or worked examples.');
        }

        if ($numberedLists >= 3) {
            $findings[] = $this->finding('too_many_numbered_lists', 'medium', 'Too many numbered lists detected.', (string) $numberedLists, $numberedLists, 'Use numbered lists only for real sequences or decision steps.');
        }

        return $findings;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectRepeatedCtaLanguage(string $lower, string $locale): array
    {
        $matches = $this->phraseMatches($lower, $this->phrases($locale, 'cta_language'));

        return count($matches) < 2 ? [] : [$this->finding(
            type: 'repeated_cta_language',
            severity: 'medium',
            message: 'CTA language repeats across the draft.',
            evidence: implode(', ', $matches),
            count: count($matches),
            action: 'Keep one clear CTA and make supporting recommendations context-specific.',
        )];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectDensityPattern(string $plain, string $locale, string $type): array
    {
        $matches = $this->regexMatches($plain, $this->regexes($locale, $type));
        $wordCount = max(1, str_word_count($plain));
        $threshold = match ($type) {
            'definition_heavy_writing' => max(3, (int) floor($wordCount / 180)),
            'summary_heavy_writing' => 3,
            'recommendation_overuse' => max(7, (int) floor($wordCount / 90)),
            'overly_balanced_phrasing' => 3,
            default => 3,
        };

        if (count($matches) < $threshold) {
            return [];
        }

        return [$this->finding(
            type: $type,
            severity: count($matches) >= $threshold + 3 ? 'high' : 'medium',
            message: $this->labelFor($type) . ' detected.',
            evidence: implode(', ', array_slice($matches, 0, 5)),
            count: count($matches),
            action: match ($type) {
                'definition_heavy_writing' => 'Move from definitions to operating consequences, examples, and decision criteria.',
                'summary_heavy_writing' => 'Remove recap-heavy sentences and replace them with new implications or sharper takeaways.',
                'recommendation_overuse' => 'Reduce repeated advice verbs and reserve recommendations for moments with evidence or tradeoffs.',
                'overly_balanced_phrasing' => 'Replace symmetrical hedging with a clear editorial judgment and named caveats.',
                default => 'Rewrite the affected passages with specific evidence and natural variation.',
            },
        )];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectFaqOveruse(string $html, string $locale): array
    {
        $headings = collect($this->headings($html))->map(fn (string $heading): string => Str::lower($heading));
        $faqHeadingCount = $headings->filter(fn (string $heading): bool => str_contains($heading, 'faq') || str_contains($heading, 'veelgestelde vragen'))->count();
        $questionHeadingCount = $headings->filter(fn (string $heading): bool => str_ends_with(trim($heading), '?'))->count();

        if (($faqHeadingCount >= 2) || ($faqHeadingCount >= 1 && $questionHeadingCount >= 5)) {
            return [$this->finding(
                type: 'faq_overuse',
                severity: 'medium',
                message: 'FAQ structure is overused.',
                evidence: "FAQ headings: {$faqHeadingCount}; question headings: {$questionHeadingCount}",
                count: $faqHeadingCount + $questionHeadingCount,
                action: 'Keep only high-intent questions and fold the rest into editorial sections.',
            )];
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectEmptyFiller(string $lower, string $locale): array
    {
        $matches = $this->phraseMatches($lower, $this->phrases($locale, 'empty_filler'));

        return count($matches) < 2 ? [] : [$this->finding(
            type: 'empty_filler',
            severity: 'high',
            message: 'Empty filler language detected.',
            evidence: implode(', ', array_slice($matches, 0, 5)),
            count: count($matches),
            action: 'Cut filler and replace it with named situations, metrics, examples, or concrete decisions.',
        )];
    }

    /**
     * @return array<int,string>
     */
    private function phraseMatches(string $lower, array $phrases): array
    {
        return collect($phrases)
            ->filter(fn (string $phrase): bool => str_contains($lower, Str::lower($phrase)))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function regexMatches(string $plain, array $patterns): array
    {
        $matches = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $plain, $found);
            foreach ($found[0] ?? [] as $match) {
                $matches[] = $this->normalizeWhitespace((string) $match);
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @return array<int,string>
     */
    private function phrases(string $locale, string $type): array
    {
        $libraries = [
            'en' => [
                'generic_transitions' => ['moreover', 'furthermore', 'in addition', 'on the other hand', 'as a result', 'in conclusion', 'to sum up'],
                'chatgpt_vocabulary' => ['delve into', 'unlock the power', 'it is important to note', 'robust solution', 'seamless experience', 'ever-evolving', 'dynamic landscape', 'leverage', 'transformative'],
                'marketing_cliches' => ['game changer', 'digital landscape', 'drive growth', 'take your business to the next level', 'stay ahead of the curve', 'maximize your potential'],
                'predictable_openings' => ['in today\'s digital landscape', 'in today\'s fast-paced world', 'as businesses continue to', 'in an era where'],
                'predictable_endings' => ['in conclusion', 'to sum up', 'ultimately', 'by embracing', 'the future is bright'],
                'cta_language' => ['book a demo', 'contact us today', 'get started today', 'learn more', 'take the next step'],
                'empty_filler' => ['it goes without saying', 'needless to say', 'at the end of the day', 'the fact of the matter is', 'when all is said and done'],
            ],
            'nl' => [
                'generic_transitions' => ['bovendien', 'daarnaast', 'aan de andere kant', 'als gevolg daarvan', 'kortom', 'samenvattend', 'tot slot'],
                'chatgpt_vocabulary' => ['duik in', 'ontgrendel de kracht', 'het is belangrijk om op te merken', 'robuuste oplossing', 'naadloze ervaring', 'voortdurend veranderende', 'dynamisch landschap', 'benutten'],
                'marketing_cliches' => ['gamechanger', 'digitale landschap', 'groei stimuleren', 'til je bedrijf naar een hoger niveau', 'blijf de concurrentie voor', 'maximaliseer je potentieel'],
                'predictable_openings' => ['in het huidige digitale landschap', 'in de snel veranderende wereld', 'nu bedrijven steeds meer', 'in een tijd waarin'],
                'predictable_endings' => ['kortom', 'samenvattend', 'uiteindelijk', 'door te omarmen', 'de toekomst ziet er rooskleurig uit'],
                'cta_language' => ['boek een demo', 'neem vandaag contact op', 'ga vandaag nog aan de slag', 'lees meer', 'zet de volgende stap'],
                'empty_filler' => ['het spreekt voor zich', 'onnodig te zeggen', 'aan het eind van de dag', 'feit is dat', 'alles bij elkaar genomen'],
            ],
        ];

        return $libraries[$locale][$type] ?? $libraries['en'][$type] ?? [];
    }

    /**
     * @return array<int,string>
     */
    private function regexes(string $locale, string $type): array
    {
        $libraries = [
            'en' => [
                'definition_heavy_writing' => ['/\b\w+\s+(is|are|refers to|can be defined as|means)\b/i'],
                'summary_heavy_writing' => ['/\b(in summary|to summarize|overall|in conclusion|the key takeaway is|as mentioned)\b/i'],
                'recommendation_overuse' => ['/\b(should|must|need to|recommend|it is recommended|best practice|consider)\b/i'],
                'overly_balanced_phrasing' => ['/\b(not only\b.*?\bbut also|whether\b.*?\bor|on the one hand\b.*?\bon the other hand|while\b.*?\balso)\b/i'],
            ],
            'nl' => [
                'definition_heavy_writing' => ['/\b\w+\s+(is|zijn|betekent|wordt gedefinieerd als|verwijst naar)\b/iu'],
                'summary_heavy_writing' => ['/\b(kortom|samenvattend|al met al|concluderend|de belangrijkste conclusie is|zoals genoemd)\b/iu'],
                'recommendation_overuse' => ['/\b(moet|moeten|zou moeten|aanbevolen|best practice|overweeg|advies)\b/iu'],
                'overly_balanced_phrasing' => ['/\b(niet alleen\b.*?\bmaar ook|of\b.*?\bof|aan de ene kant\b.*?\baan de andere kant|terwijl\b.*?\book)\b/iu'],
            ],
        ];

        return $libraries[$locale][$type] ?? $libraries['en'][$type] ?? [];
    }

    /**
     * @return array<int,string>
     */
    private function headings(string $html): array
    {
        preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $heading): string => $this->normalizeWhitespace(strip_tags($heading)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function paragraphs(string $html): array
    {
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches);
        $paragraphs = collect($matches[1] ?? [])
            ->map(fn (string $paragraph): string => $this->normalizeWhitespace(strip_tags($paragraph)))
            ->filter()
            ->values()
            ->all();

        return $paragraphs !== [] ? $paragraphs : [$this->normalizeWhitespace(strip_tags($html))];
    }

    /**
     * @return array<int,int>
     */
    private function sectionWordCounts(string $html): array
    {
        $parts = preg_split('/<h[2-3]\b[^>]*>.*?<\/h[2-3]>/is', $html) ?: [];

        return collect(array_slice($parts, 1))
            ->map(fn (string $section): int => str_word_count($this->normalizeWhitespace(strip_tags($section))))
            ->filter(fn (int $words): bool => $words > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $type, string $severity, string $message, string $evidence, int $count, string $action): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'evidence' => $evidence,
            'count' => $count,
            'recommendation' => $action,
            'humanization_action' => $action,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     */
    private function riskScore(array $findings): int
    {
        $score = 12;
        foreach ($findings as $finding) {
            $weight = match ((string) ($finding['severity'] ?? 'low')) {
                'high' => 14,
                'medium' => 9,
                default => 5,
            };
            $score += $weight + min(8, max(0, (int) ($finding['count'] ?? 1) - 1) * 2);
        }

        return max(0, min(100, $score));
    }

    private function severity(int $score): string
    {
        return match (true) {
            $score >= 70 => 'high',
            $score >= 45 => 'medium',
            $score >= 25 => 'low',
            default => 'none',
        };
    }

    private function labelFor(string $type): string
    {
        return Str::headline(str_replace('_', ' ', $type));
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = Str::lower(Str::substr(trim($locale), 0, 2));

        return in_array($locale, ['en', 'nl'], true) ? $locale : 'en';
    }

    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
