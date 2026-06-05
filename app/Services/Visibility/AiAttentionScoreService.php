<?php

namespace App\Services\Visibility;

class AiAttentionScoreService
{
    /**
     * @param  array<string, int|float>  $metrics
     */
    public function score(array $metrics): int
    {
        $answer = (int) ($metrics['answer_presence_score'] ?? 0);
        $citations = (int) ($metrics['citation_score'] ?? 0);
        $sources = (int) ($metrics['source_presence_score'] ?? 0);
        $authority = (int) ($metrics['authority_score'] ?? 0);
        $competitors = (int) ($metrics['competitor_presence_score'] ?? 0);

        $score = ($answer * 0.38)
            + ($citations * 0.18)
            + ($sources * 0.18)
            + ($authority * 0.16)
            + ((100 - $competitors) * 0.10);

        return max(0, min(100, (int) round($score)));
    }
}
