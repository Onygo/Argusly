<?php

namespace App\Services\Content;

use App\Support\ContentIntentCatalog;

class IntentDetectionService
{
    /**
     * @param  array<int, string>  $supporting
     * @return array<int, string>
     */
    public function detectFromKeywords(string $primary, array $supporting = []): array
    {
        $text = mb_strtolower(trim($primary . ' ' . implode(' ', $supporting)));

        if ($text === '') {
            return [];
        }

        $detected = [];

        if ($this->containsAny($text, ['what', 'how', 'guide', 'explained', 'uitleg', 'gids'])) {
            $detected[] = 'educate';
            $detected[] = 'guide';
        }

        if ($this->containsAny($text, ['best', 'tool', 'software', 'platform', 'service'])) {
            $detected[] = 'commercial';
        }

        if ($this->containsAny($text, ['vs', 'versus', 'compare', 'comparison'])) {
            $detected[] = 'compare';
        }

        if ($this->containsAny($text, ['why', 'benefits', 'strategy', 'strategic', 'roadmap'])) {
            $detected[] = 'strategic';
        }

        if ($this->containsAny($text, ['process', 'workflow', 'checklist', 'steps', 'framework'])) {
            $detected[] = 'process';
        }

        if ($this->containsAny($text, ['solution', 'architecture', 'implementation', 'platform'])) {
            $detected[] = 'solution';
        }

        if ($this->containsAny($text, ['inform', 'overview', 'basics', 'fundamentals', 'introduction'])) {
            $detected[] = 'inform';
            $detected[] = 'explain';
        }

        return ContentIntentCatalog::normalizeKeys($detected);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
