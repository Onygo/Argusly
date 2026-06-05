<?php

namespace App\Services\QueryIntent;

class QueryIntentClassifier
{
    /**
     * @return array{primary:string,secondary:array<int,string>,confidence:float,signals:array<string,mixed>}
     */
    public function classify(string $text): array
    {
        $text = strtolower($text);
        $scores = array_fill_keys(QueryIntentTaxonomy::INTENTS, 0.0);
        $matched = [];

        foreach ($this->rules() as $intent => $rules) {
            foreach ($rules as $needle => $weight) {
                if (str_contains($text, (string) $needle)) {
                    $scores[$intent] += (float) $weight;
                    $matched[$intent][] = $needle;
                }
            }
        }

        if (array_sum($scores) <= 0) {
            $scores['informational'] = 2.0;
        }

        arsort($scores);
        $primary = (string) array_key_first($scores);
        $secondary = collect($scores)
            ->filter(fn (float $score, string $intent): bool => $intent !== $primary && $score > 0)
            ->keys()
            ->take(3)
            ->values()
            ->all();

        $topScore = (float) ($scores[$primary] ?? 0);
        $confidence = min(98.0, max(42.0, 48.0 + ($topScore * 9.0) - (count($secondary) * 4.0)));

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'confidence' => round($confidence, 2),
            'signals' => [
                'intent_scores' => $scores,
                'matched_terms' => $matched,
            ],
        ];
    }

    private function rules(): array
    {
        return [
            'informational' => [
                'what is' => 4, 'how does' => 4, 'guide' => 2, 'learn' => 2, 'examples' => 2, 'definition' => 3,
            ],
            'commercial' => [
                'best' => 3, 'top' => 2, 'software' => 2, 'platform' => 2, 'tools' => 2, 'solution' => 2, 'vendor' => 2,
            ],
            'transactional' => [
                'pricing' => 5, 'price' => 4, 'buy' => 5, 'demo' => 5, 'trial' => 4, 'quote' => 4, 'book' => 3,
            ],
            'navigational' => [
                'login' => 5, 'dashboard' => 4, 'docs' => 3, 'api reference' => 4, 'support' => 3, 'download' => 3,
            ],
            'implementation' => [
                'implement' => 5, 'implementation' => 5, 'setup' => 4, 'configure' => 4, 'integrate' => 4, 'workflow' => 3, 'playbook' => 3,
            ],
            'comparison' => [
                ' vs ' => 6, 'versus' => 6, 'alternative' => 5, 'alternatives' => 5, 'compare' => 4, 'comparison' => 5,
            ],
            'migration' => [
                'migrate' => 10, 'migration' => 10, 'switch from' => 8, 'switching' => 6, 'replace' => 6, 'move from' => 6, 'transition' => 4,
            ],
            'risk_evaluation' => [
                'risk' => 5, 'security' => 4, 'compliance' => 4, 'gdpr' => 4, 'governance' => 4, 'audit' => 3, 'safe' => 2, 'reliability' => 3,
            ],
        ];
    }
}
