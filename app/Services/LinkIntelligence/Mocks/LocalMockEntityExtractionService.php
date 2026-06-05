<?php

namespace App\Services\LinkIntelligence\Mocks;

use App\Contracts\LinkIntelligence\EntityExtractionService;
use App\DTO\LinkIntelligence\EntityResult;
use App\Models\Draft;
use Illuminate\Support\Str;

class LocalMockEntityExtractionService implements EntityExtractionService
{
    public function extractEntities(Draft $article): EntityResult
    {
        $titleTokens = $this->tokenize($article->title ?? '');
        $bodyTokens = $this->tokenize(strip_tags((string) ($article->content_html ?? '')));

        $primary = array_slice(array_values(array_unique($titleTokens)), 0, 6);
        $secondary = array_slice(
            array_values(array_diff(array_unique($bodyTokens), $primary)),
            0,
            20,
        );

        $entities = [];
        foreach ($primary as $token) {
            $entities[] = [
                'name' => $token,
                'type' => 'primary',
                'confidence' => 0.9,
            ];
        }

        foreach ($secondary as $token) {
            $entities[] = [
                'name' => $token,
                'type' => 'secondary',
                'confidence' => 0.65,
            ];
        }

        return new EntityResult($entities);
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', $text) ?: [];

        return array_values(array_filter($parts, static function (?string $token): bool {
            if (! $token) {
                return false;
            }

            if (preg_match('/^\d+$/', $token)) {
                return false;
            }

            $allowedShortDomainTerms = ['ao', 'ai', 'bi', 'it', 'erp', 'crm', 'api', 'seo', 'saas', 'wp'];
            if (strlen($token) < 4 && ! in_array($token, $allowedShortDomainTerms, true)) {
                return false;
            }

            $stopWords = [
                // English
                'this', 'that', 'with', 'from', 'your', 'into', 'were', 'what', 'when', 'have', 'about', 'article', 'draft',
                'also', 'more', 'most', 'very', 'than', 'then', 'because', 'while', 'where', 'which',
                // Dutch
                'maar', 'deze', 'voor', 'over', 'zijn', 'naar', 'door', 'tussen', 'vooral', 'omdat', 'zoals',
                'worden', 'wordt', 'geworden', 'heeft', 'hebben', 'hadden', 'doen', 'doet', 'deed',
                'maken', 'maakt', 'gemaakt', 'werken', 'werkt', 'gewerkt', 'groeit', 'groeien',
                'kunnen', 'kan', 'kunt', 'kun', 'moeten', 'moet', 'zullen', 'zal',
                'hiermee', 'daarmee', 'daarvoor', 'hiervoor', 'daarin', 'hierin',
            ];

            return ! in_array($token, $stopWords, true);
        }));
    }
}
