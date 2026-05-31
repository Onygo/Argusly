<?php

namespace App\Services\Visibility\Providers;

use App\Contracts\AiVisibilityProviderInterface;
use Illuminate\Support\Str;

class FakeAiVisibilityProvider implements AiVisibilityProviderInterface
{
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $model,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function runPrompt(string $prompt, array $context = []): array
    {
        $brand = (string) ($context['brand'] ?? 'the tracked brand');
        $query = trim($prompt);
        $language = (string) ($context['language'] ?? 'en');
        $market = $context['market'] ?? null;
        $scoreSeed = crc32($this->key.'|'.$query.'|'.$brand);
        $visible = ($scoreSeed % 3) !== 0;
        $domain = Str::slug($brand ?: 'brand').'.example';

        return [
            'provider' => $this->key,
            'model' => $this->model,
            'prompt' => $query,
            'language' => $language,
            'detected_language' => $language,
            'market' => $market,
            'answer' => $visible
                ? "{$this->name} fake answer mentions {$brand} as a relevant option for {$query}."
                : "{$this->name} fake answer discusses {$query} without a strong brand mention.",
            'citations' => [
                [
                    'url' => "https://{$domain}/visibility-reference",
                    'domain' => $domain,
                    'title' => "{$brand} visibility reference",
                    'snippet' => "Fake {$this->name} citation prepared for adapter parsing.",
                    'rank' => 1,
                    'trust_score' => 70 + (int) ($scoreSeed % 25),
                    'metadata' => ['fake' => true],
                ],
            ],
            'entities' => [
                [
                    'entity_name' => $brand,
                    'entity_type' => 'brand',
                    'sentiment' => $visible ? 'positive' : 'neutral',
                    'position' => $visible ? 1 : null,
                    'metadata' => ['fake' => true, 'visible' => $visible],
                ],
            ],
            'usage' => [
                'input_tokens' => strlen($query),
                'output_tokens' => 42,
                'cost_credits' => 0,
            ],
            'fake' => true,
        ];
    }

    public function normalizeAnswer(array $response): string
    {
        return (string) ($response['answer'] ?? '');
    }

    public function extractCitations(array $response): array
    {
        return $response['citations'] ?? [];
    }

    public function extractEntities(array $response): array
    {
        return $response['entities'] ?? [];
    }

    public function calculateVisibilityScore(string $normalizedAnswer, array $citations = [], array $entities = [], array $context = []): int
    {
        $brand = (string) ($context['brand'] ?? '');
        $mentionsBrand = $brand !== '' && str_contains(strtolower($normalizedAnswer), strtolower($brand));
        $citationScore = min(25, count($citations) * 10);
        $entityScore = collect($entities)->contains(fn (array $entity) => ($entity['entity_type'] ?? null) === 'brand') ? 25 : 0;

        return min(100, 30 + $citationScore + $entityScore + ($mentionsBrand ? 20 : 0));
    }
}
