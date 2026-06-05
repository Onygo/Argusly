<?php

namespace App\Services\CompetitorIntelligence;

use Illuminate\Support\Str;

class CompetitorTopicExtractor
{
    /**
     * @return array<int, string>
     */
    public function extract(string $text, int $limit = 10): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text) ?: '';
        $tokens = collect(preg_split('/\s+/', $text) ?: [])
            ->map(fn (string $token): string => trim($token, "- \t\n\r\0\x0B"))
            ->filter(fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, $this->stopWords(), true))
            ->values();

        $phrases = [];
        $count = $tokens->count();
        for ($size = 3; $size >= 1; $size--) {
            for ($i = 0; $i <= $count - $size; $i++) {
                $phrase = $tokens->slice($i, $size)->implode(' ');
                if ($this->isUsefulPhrase($phrase)) {
                    $phrases[$phrase] = ($phrases[$phrase] ?? 0) + $size;
                }
            }
        }

        arsort($phrases);

        return collect(array_keys($phrases))
            ->reject(fn (string $phrase): bool => Str::length($phrase) < 4)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    private function isUsefulPhrase(string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        $words = explode(' ', $phrase);

        return collect($words)->filter(fn (string $word): bool => ! in_array($word, $this->stopWords(), true))->count() === count($words);
    }

    /**
     * @return array<int, string>
     */
    private function stopWords(): array
    {
        return [
            'the', 'and', 'for', 'with', 'from', 'your', 'you', 'our', 'are', 'can', 'how', 'what', 'why', 'when',
            'into', 'that', 'this', 'than', 'then', 'their', 'there', 'about', 'using', 'use', 'get', 'all', 'new',
            'best', 'guide', 'page', 'pages', 'content', 'learn', 'more', 'free', 'demo', 'contact', 'pricing',
        ];
    }
}
