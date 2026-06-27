<?php

namespace App\Services\HumanContent;

use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\WriterProfile;
use Illuminate\Support\Str;

class HumanizationService
{
    public const VERSION = 'humanization.v1';

    public function shouldHumanize(array $humanContentScore): bool
    {
        return ! (bool) data_get($humanContentScore, 'passed', false)
            || (int) data_get($humanContentScore, 'editorial_quality_score', 100) < 65
            || (int) data_get($humanContentScore, 'human_content_score', 100) < 70
            || (int) data_get($humanContentScore, 'ai_fingerprint_score', 0) > 45;
    }

    /**
     * @param array<int,string> $humanFindings
     * @param array<int,array<string,mixed>> $aiFingerprintFindings
     * @param array<int,array<string,mixed>> $corpusDiversityFindings
     * @param array<string,mixed> $editorialPlan
     * @param array<string,mixed> $brandVoice
     * @param array<string,mixed> $writerProfile
     * @return array<string,mixed>
     */
    public function humanize(
        string $html,
        array $humanFindings = [],
        array $aiFingerprintFindings = [],
        array $editorialPlan = [],
        ?Brief $brief = null,
        array $brandVoice = [],
        array $writerProfile = [],
        array $corpusDiversityFindings = [],
    ): array {
        $before = [
            'hrefs' => $this->hrefs($html),
            'json_ld' => $this->jsonLdScripts($html),
            'numbers' => $this->numbers($html),
            'entities' => $this->entities($html),
        ];

        $aiTypes = collect($aiFingerprintFindings)->pluck('type')->filter()->values();
        $corpusTypes = collect($corpusDiversityFindings)->pluck('type')->filter()->values();
        $types = $aiTypes->merge($corpusTypes)->unique()->values()->all();
        $topic = $this->topic($brief, $editorialPlan);
        $notes = collect($corpusDiversityFindings)
            ->pluck('humanization_action')
            ->merge(collect($corpusDiversityFindings)->pluck('recommendation'))
            ->filter()
            ->values()
            ->all();
        $improved = $html;

        if (array_intersect($types, ['generic_headings', 'predictable_openings', 'predictable_endings', 'heading_similarity', 'opening_similarity', 'ending_similarity', 'structure_similarity', 'section_count_similarity', 'narrative_pattern_similarity']) !== []) {
            [$improved, $headingNotes] = $this->rewriteGenericHeadings($improved, $topic, $editorialPlan);
            $notes = array_merge($notes, $headingNotes);
        }

        if (array_intersect($types, ['chatgpt_vocabulary', 'marketing_cliches', 'generic_transitions', 'empty_filler', 'predictable_openings', 'predictable_endings']) !== []) {
            [$improved, $phraseNotes] = $this->replaceStockLanguage($improved);
            $notes = array_merge($notes, $phraseNotes);
        }

        if (array_intersect($types, ['uniform_paragraph_lengths', 'equal_section_sizes', 'repeated_section_structures', 'summary_heavy_writing', 'paragraph_rhythm_similarity']) !== []) {
            [$improved, $rhythmNotes] = $this->varyParagraphRhythm($improved);
            $notes = array_merge($notes, $rhythmNotes);
        }

        if (array_intersect($types, ['recommendation_overuse', 'overly_balanced_phrasing', 'definition_heavy_writing', 'example_similarity', 'argument_similarity', 'cta_similarity']) !== []) {
            [$improved, $judgmentNotes] = $this->sharpenEditorialJudgment($improved, $topic);
            $notes = array_merge($notes, $judgmentNotes);
        }

        $notes = collect($notes)->filter()->unique()->values()->all();
        $validation = $this->validatePreservation($before, $improved);
        if (! (bool) data_get($validation, 'passed', false)) {
            return [
                'version' => self::VERSION,
                'changed' => false,
                'improved_html' => $html,
                'change_summary' => 'Humanization skipped because preservation validation failed.',
                'before_after_notes' => $notes,
                'preserved_validation' => $validation,
            ];
        }

        $changed = $this->normalize($html) !== $this->normalize($improved);

        return [
            'version' => self::VERSION,
            'changed' => $changed,
            'improved_html' => $changed ? $improved : $html,
            'change_summary' => $changed
                ? 'Applied targeted humanization edits to headings, stock phrasing, rhythm, and editorial judgment while preserving links and factual markers.'
                : 'No targeted humanization edits were needed.',
            'before_after_notes' => $notes,
            'preserved_validation' => $validation,
            'context' => [
                'topic' => $topic,
                'finding_types' => $types,
                'brand_voice_present' => $brandVoice !== [],
                'writer_profile_present' => $writerProfile !== [],
                'human_finding_count' => count($humanFindings),
                'corpus_diversity_finding_count' => count($corpusDiversityFindings),
            ],
        ];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function rewriteGenericHeadings(string $html, string $topic, array $editorialPlan): array
    {
        $notes = [];
        $specificThesis = trim((string) data_get($editorialPlan, 'central_thesis', ''));
        $specificHeading = $specificThesis !== ''
            ? Str::of($specificThesis)->limit(72, '')->toString()
            : 'Why ' . $topic . ' needs editorial judgment';

        $replacements = [
            '/(<h([1-6])\b[^>]*>)\s*(Introduction|Inleiding)\s*(<\/h\2>)/iu' => '$1' . e($specificHeading) . '$4',
            '/(<h([1-6])\b[^>]*>)\s*(Main Section|Hoofdsectie|Section\s+\d+|Sectie\s+\d+)\s*(<\/h\2>)/iu' => '$1What changes in practice for ' . e($topic) . '$4',
            '/(<h([1-6])\b[^>]*>)\s*(Conclusion|Conclusie|Final Thoughts|Eindgedachten|Summary|Samenvatting)\s*(<\/h\2>)/iu' => '$1What readers should do next with ' . e($topic) . '$4',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $html, -1, $count) ?? $html;
            if ($count > 0) {
                $notes[] = 'Rewrote generic structural headings into topic-specific editorial headings.';
                $html = $updated;
            }
        }

        return [$html, $notes];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function replaceStockLanguage(string $html): array
    {
        $changed = false;
        $replacements = [
            'In today\'s digital landscape, ' => '',
            'In today\'s digital landscape' => 'In current publishing workflows',
            'In het huidige digitale landschap, ' => '',
            'In het huidige digitale landschap' => 'In actuele contentprocessen',
            'it is important to note that ' => '',
            'het is belangrijk om op te merken dat ' => '',
            'unlock the power of' => 'use',
            'ontgrendel de kracht van' => 'gebruik',
            'robust solution' => 'practical system',
            'robuuste oplossing' => 'praktisch systeem',
            'seamless experience' => 'clear workflow',
            'naadloze ervaring' => 'heldere workflow',
            'game changer' => 'material shift',
            'gamechanger' => 'merkbare verschuiving',
            'stay ahead of the curve' => 'make the decision earlier',
            'blijf de concurrentie voor' => 'neem de beslissing eerder',
            'In conclusion, ' => '',
            'In conclusion' => 'The practical implication',
            'Kortom, ' => '',
            'Kortom' => 'De praktische implicatie',
            'Overall, ' => '',
            'Ultimately, ' => '',
            'Uiteindelijk, ' => '',
        ];

        $html = $this->transformTextNodes($html, function (string $text) use ($replacements, &$changed): string {
            $updated = str_ireplace(array_keys($replacements), array_values($replacements), $text);
            if ($updated !== $text) {
                $changed = true;
            }

            return $updated;
        });

        return [$html, $changed ? ['Replaced generic AI and marketing phrasing with more concrete language.'] : []];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function varyParagraphRhythm(string $html): array
    {
        $changed = false;
        $html = preg_replace_callback('/<p\b([^>]*)>(.*?)<\/p>/is', function (array $match) use (&$changed): string {
            $body = trim((string) $match[2]);
            $plain = trim(strip_tags($body));
            if (str_word_count($plain) < 42) {
                return $match[0];
            }

            $parts = preg_split('/(?<=[.!?])\s+/', $body, 2);
            if (! is_array($parts) || count($parts) < 2 || str_word_count(strip_tags((string) $parts[0])) < 8) {
                return $match[0];
            }

            $changed = true;

            return '<p' . $match[1] . '>' . trim((string) $parts[0]) . '</p><p' . $match[1] . '>' . trim((string) $parts[1]) . '</p>';
        }, $html) ?? $html;

        return [$html, $changed ? ['Varied paragraph rhythm by splitting dense uniform paragraphs.'] : []];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function sharpenEditorialJudgment(string $html, string $topic): array
    {
        $changed = false;
        $html = $this->transformTextNodes($html, function (string $text) use ($topic, &$changed): string {
            $updated = preg_replace('/\b(it depends|this depends)\b/i', 'the decision depends on the operating constraint', $text) ?? $text;
            $updated = preg_replace('/\bshould consider\b/i', 'should decide based on evidence from ' . $topic, $updated) ?? $updated;
            $updated = preg_replace('/\b(is|are) important because\b/i', '$1 useful when', $updated) ?? $updated;

            if ($updated !== $text) {
                $changed = true;
            }

            return $updated;
        });

        return [$html, $changed ? ['Sharpened hedged recommendations into more specific editorial judgment.'] : []];
    }

    private function transformTextNodes(string $html, callable $callback): string
    {
        $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $insideRaw = false;

        foreach ($parts as $index => $part) {
            if (preg_match('/^<\s*(script|style)\b/i', $part) === 1) {
                $insideRaw = true;
                continue;
            }

            if (preg_match('/^<\s*\/\s*(script|style)\s*>/i', $part) === 1) {
                $insideRaw = false;
                continue;
            }

            if ($insideRaw || str_starts_with($part, '<')) {
                continue;
            }

            $parts[$index] = $callback($part);
        }

        return implode('', $parts);
    }

    /**
     * @param array<string,mixed> $before
     * @return array<string,mixed>
     */
    private function validatePreservation(array $before, string $afterHtml): array
    {
        $after = [
            'hrefs' => $this->hrefs($afterHtml),
            'json_ld' => $this->jsonLdScripts($afterHtml),
            'numbers' => $this->numbers($afterHtml),
            'entities' => $this->entities($afterHtml),
        ];

        $missingEntities = array_values(array_diff((array) $before['entities'], $after['entities']));

        return [
            'passed' => $before['hrefs'] === $after['hrefs']
                && $before['json_ld'] === $after['json_ld']
                && $before['numbers'] === $after['numbers']
                && $missingEntities === [],
            'links_preserved' => $before['hrefs'] === $after['hrefs'],
            'schema_preserved' => $before['json_ld'] === $after['json_ld'],
            'facts_preserved' => $before['numbers'] === $after['numbers'] && $missingEntities === [],
            'missing_entities' => $missingEntities,
            'before_link_count' => count((array) $before['hrefs']),
            'after_link_count' => count($after['hrefs']),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref=(["\'])(.*?)\1/is', $html, $matches);

        return array_values(array_map('strval', $matches[2] ?? []));
    }

    /**
     * @return array<int,string>
     */
    private function jsonLdScripts(string $html): array
    {
        preg_match_all('/<script\b[^>]*type=(["\'])application\/ld\+json\1[^>]*>(.*?)<\/script>/is', $html, $matches);

        return array_values(array_map(fn (string $script): string => $this->normalize($script), $matches[2] ?? []));
    }

    /**
     * @return array<int,string>
     */
    private function numbers(string $html): array
    {
        preg_match_all('/\b\d+(?:[.,]\d+)?%?\b/u', $this->plainText($html), $matches);

        return array_values($matches[0] ?? []);
    }

    /**
     * @return array<int,string>
     */
    private function entities(string $html): array
    {
        preg_match_all('/\b[A-Z][A-Za-z0-9]+(?:\s+[A-Z][A-Za-z0-9]+)*\b/u', $this->plainText($html), $matches);

        return collect($matches[0] ?? [])
            ->filter(function (string $entity): bool {
                if (mb_strlen($entity) <= 2) {
                    return false;
                }

                foreach (['The', 'This', 'That', 'What', 'Why', 'How', 'Main', 'Section', 'Main Section', 'Conclusion', 'Introduction', 'Final Thoughts', 'Summary'] as $generic) {
                    if (str_contains($entity, $generic)) {
                        return false;
                    }
                }

                return true;
            })
            ->unique()
            ->values()
            ->all();
    }

    private function topic(?Brief $brief, array $editorialPlan): string
    {
        $topic = trim((string) ($brief?->primary_keyword ?: $brief?->title ?: data_get($editorialPlan, 'primary_pattern.name', 'the topic')));

        return $topic !== '' ? $topic : 'the topic';
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function plainText(string $html): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

        return $this->normalize(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
