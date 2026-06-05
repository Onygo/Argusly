<?php

namespace App\Services\QueryIntent;

class BuyerRoleClassifier
{
    /**
     * @return array{role:string,signals:array<string,mixed>}
     */
    public function classify(string $text): array
    {
        $text = strtolower($text);
        $scores = [
            'marketers' => $this->score($text, ['seo', 'aeo', 'campaign', 'content', 'brand', 'demand gen', 'marketing']),
            'developers' => $this->score($text, ['api', 'sdk', 'developer', 'laravel', 'webhook', 'integration', 'schema']),
            'founders' => $this->score($text, ['founder', 'startup', 'growth', 'revenue', 'go-to-market', 'gtm']),
            'operations' => $this->score($text, ['workflow', 'process', 'operations', 'approval', 'calendar', 'production']),
            'enterprise_buyers' => $this->score($text, ['enterprise', 'security', 'compliance', 'procurement', 'governance', 'sla']),
        ];

        arsort($scores);

        return [
            'role' => (string) array_key_first($scores),
            'signals' => ['buyer_role_scores' => $scores],
        ];
    }

    private function score(string $text, array $needles): float
    {
        $score = 0.0;
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $score += 1.0;
            }
        }

        return $score;
    }
}
