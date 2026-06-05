<?php

namespace App\Agents\InternalLinking;

use App\Models\Content;
use Illuminate\Support\Str;

class AnchorSuggestionService
{
    /**
     * @var array<int,string>
     */
    private array $genericAnchors = [
        'click here',
        'read more',
        'learn more',
        'this article',
        'this guide',
        'guide',
        'article',
        'post',
        'more',
    ];

    /**
     * @param array<string,mixed> $input
     * @return array{anchor_text:string,insertion_hint:?string,first_position:int}|null
     */
    public function suggest(array $input, Content $candidate): ?array
    {
        $sourceText = trim((string) ($input['source_text'] ?? ''));
        if ($sourceText === '') {
            return null;
        }

        $phrases = collect([
            trim((string) ($candidate->primary_keyword ?? '')),
            trim((string) $candidate->title),
        ])
            ->map(fn (string $phrase): string => trim(preg_replace('/\s+/u', ' ', $phrase) ?? ''))
            ->filter(fn (string $phrase): bool => $phrase !== '' && mb_strlen($phrase) >= 4)
            ->reject(fn (string $phrase): bool => in_array(Str::lower($phrase), $this->genericAnchors, true))
            ->unique()
            ->values();

        foreach ($phrases as $phrase) {
            $position = mb_stripos($sourceText, Str::lower($phrase));
            if ($position === false) {
                continue;
            }

            return [
                'anchor_text' => $phrase,
                'insertion_hint' => $this->insertionHint((array) ($input['headings'] ?? []), $phrase),
                'first_position' => (int) $position,
            ];
        }

        return null;
    }

    /**
     * @param array<int,string> $headings
     */
    private function insertionHint(array $headings, string $phrase): ?string
    {
        $needle = Str::lower($phrase);
        $phraseTokens = $this->tokens($phrase);

        foreach ($headings as $heading) {
            $normalizedHeading = Str::lower(trim($heading));
            if ($normalizedHeading === '') {
                continue;
            }

            if (str_contains($normalizedHeading, $needle)) {
                return $heading;
            }

            $sharedTokens = array_intersect($this->tokens($heading), $phraseTokens);
            if ($sharedTokens !== []) {
                return $heading;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $value): array
    {
        return collect(preg_split('/[^[:alnum:]]+/u', Str::lower($value)) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();
    }
}
