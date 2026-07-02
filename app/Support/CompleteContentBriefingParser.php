<?php

namespace App\Support;

use Illuminate\Support\Str;

class CompleteContentBriefingParser
{
    /**
     * @var array<string,string>
     */
    private const HEADINGS = [
        'content briefing' => 'overview',
        'working title' => 'working_title',
        'alternative seo titles' => 'alternative_seo_titles',
        'primary keyword' => 'primary_keyword',
        'secondary keywords' => 'secondary_keywords',
        'search intent' => 'search_intent',
        'target audience' => 'target_audience',
        'core message' => 'core_message',
        'angle' => 'angle',
        'problem statement' => 'problem_statement',
        'key discussion points' => 'key_discussion_points',
        'connect with argusly' => 'brand_connection',
        'suggested article structure' => 'suggested_article_structure',
        'final thoughts' => 'final_thoughts',
        'internal linking opportunities' => 'internal_linking_opportunities',
        'internal linking opportunities argusly' => 'internal_linking_opportunities',
        'tone' => 'tone',
        'tone of voice' => 'tone',
        'call to action' => 'call_to_action',
        'funnel stage' => 'funnel_stage',
        'notes' => 'notes',
    ];

    /**
     * @return array{
     *   raw:string,
     *   sections:array<string,string>,
     *   derived:array<string,mixed>
     * }
     */
    public static function parse(string $briefing): array
    {
        $raw = trim($briefing);
        if ($raw === '') {
            return [
                'raw' => '',
                'sections' => [],
                'derived' => [],
            ];
        }

        $sections = self::parseSections($raw);

        return [
            'raw' => $raw,
            'sections' => $sections,
            'derived' => [
                'title' => self::firstLine($sections['working_title'] ?? '')
                    ?: self::firstLine($sections['alternative_seo_titles'] ?? ''),
                'primary_keyword' => self::firstLine($sections['primary_keyword'] ?? ''),
                'secondary_keywords' => self::listValue($sections['secondary_keywords'] ?? ''),
                'target_audience' => self::listValue($sections['target_audience'] ?? ''),
                'tone' => self::firstLine($sections['tone'] ?? ''),
                'funnel_stage' => self::normalizeFunnelStage($sections['funnel_stage'] ?? ''),
                'search_intent' => self::firstLine($sections['search_intent'] ?? ''),
                'unique_angle' => self::firstNonEmpty([
                    $sections['angle'] ?? '',
                    $sections['core_message'] ?? '',
                ]),
                'key_points' => self::listValue($sections['key_discussion_points'] ?? ''),
                'call_to_action' => self::firstNonEmpty([
                    $sections['call_to_action'] ?? '',
                    $sections['final_thoughts'] ?? '',
                ]),
                'strategic_positioning' => self::strategicPositioning($sections),
                'notes' => self::notes($sections),
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function parseSections(string $raw): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            $heading = self::headingKey($trimmed);

            if ($heading !== null) {
                $current = $heading;
                $sections[$current] ??= '';

                continue;
            }

            if ($current === null) {
                continue;
            }

            $sections[$current] = trim(($sections[$current] ?? '') . "\n" . $line);
        }

        return array_filter($sections, fn (string $value): bool => trim($value) !== '');
    }

    private static function headingKey(string $line): ?string
    {
        if ($line === '') {
            return null;
        }

        $normalized = Str::of($line)
            ->lower()
            ->replaceMatches('/[:\-]+$/', '')
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return self::HEADINGS[$normalized] ?? null;
    }

    private static function firstLine(string $value): string
    {
        return (string) collect(preg_split('/\R+/', trim($value)) ?: [])
            ->map(fn (string $line): string => self::cleanListItem($line))
            ->first(fn (string $line): bool => $line !== '', '');
    }

    /**
     * @param array<int,string> $values
     */
    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<int,string>
     */
    private static function listValue(string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', trim($value)) ?: [])
            ->map(fn (string $line): string => self::cleanListItem($line))
            ->filter()
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }

    private static function cleanListItem(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\s*(?:[-*•]|\d+[\).\:-])\s*/u', '', $value) ?: $value;

        return trim($value);
    }

    private static function normalizeFunnelStage(string $value): string
    {
        $normalized = Str::of(self::firstLine($value))->lower()->toString();

        return match (true) {
            str_contains($normalized, 'aware') => 'awareness',
            str_contains($normalized, 'consider') => 'consideration',
            str_contains($normalized, 'decision'),
            str_contains($normalized, 'convert'),
            str_contains($normalized, 'purchase') => 'decision',
            str_contains($normalized, 'retain'),
            str_contains($normalized, 'loyal') => 'retention',
            default => '',
        };
    }

    /**
     * @param array<string,string> $sections
     */
    private static function strategicPositioning(array $sections): string
    {
        return trim(implode("\n\n", array_filter([
            $sections['core_message'] ?? '',
            $sections['angle'] ?? '',
            $sections['brand_connection'] ?? '',
        ])));
    }

    /**
     * @param array<string,string> $sections
     */
    private static function notes(array $sections): string
    {
        $labels = [
            'problem_statement' => 'Problem statement',
            'key_discussion_points' => 'Key discussion points',
            'suggested_article_structure' => 'Suggested article structure',
            'brand_connection' => 'Brand connection',
            'internal_linking_opportunities' => 'Internal linking opportunities',
            'notes' => 'Notes',
        ];

        $blocks = [];
        foreach ($labels as $key => $label) {
            $value = trim((string) ($sections[$key] ?? ''));
            if ($value !== '') {
                $blocks[] = $label . ":\n" . $value;
            }
        }

        return trim(implode("\n\n", $blocks));
    }
}
