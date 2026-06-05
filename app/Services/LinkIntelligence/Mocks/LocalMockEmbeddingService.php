<?php

namespace App\Services\LinkIntelligence\Mocks;

use App\Contracts\LinkIntelligence\EmbeddingService;
use App\DTO\LinkIntelligence\EmbeddingResult;
use App\Models\Draft;

class LocalMockEmbeddingService implements EmbeddingService
{
    public function buildEmbeddingForArticle(Draft $article): EmbeddingResult
    {
        $text = $this->normalizedText($article);
        $tokens = preg_split('/\s+/', $text) ?: [];
        $tokens = array_values(array_filter($tokens));

        $dimensions = 24;
        $vector = array_fill(0, $dimensions, 0.0);

        foreach ($tokens as $token) {
            $hash = abs(crc32($token));
            $index = $hash % $dimensions;
            $vector[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($norm > 0.0) {
            foreach ($vector as $index => $value) {
                $vector[$index] = round($value / $norm, 8);
            }
        }

        return new EmbeddingResult(
            provider: 'local_mock',
            model: (string) config('link_intelligence.embedding.model', 'local-mock-embedding-v1'),
            embedding: $vector,
        );
    }

    private function normalizedText(Draft $article): string
    {
        $raw = trim(($article->title ?? '') . ' ' . strip_tags((string) ($article->content_html ?? '')));
        $raw = strtolower($raw);
        $raw = preg_replace('/[^a-z0-9\s]/', ' ', $raw) ?? $raw;

        return trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    }
}
