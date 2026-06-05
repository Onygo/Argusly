<?php

namespace App\Services\LlmTracking;

use App\Models\LlmTrackingQuery;
use Illuminate\Support\Str;

class LlmAuthorityEntityExtractor
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function extract(string $answer, LlmTrackingQuery $query, array $sources = []): array
    {
        $ignored = $this->ignoredNames($query);
        $candidates = [];

        foreach ($this->knownEntityNames() as $name) {
            $hit = $this->entityHit($answer, $name, $ignored, $sources, $query);
            if ($hit !== null) {
                $candidates[$hit['normalized_name']] = $hit;
            }
        }

        foreach ($this->extractCapitalizedPhrases($answer) as $name) {
            $hit = $this->entityHit($answer, $name, $ignored, $sources, $query);
            if ($hit === null) {
                continue;
            }

            $key = (string) $hit['normalized_name'];
            if (isset($candidates[$key])) {
                $candidates[$key]['mention_count'] += (int) $hit['mention_count'];
                $candidates[$key]['context_snippets'] = collect([
                    ...((array) ($candidates[$key]['context_snippets'] ?? [])),
                    ...((array) ($hit['context_snippets'] ?? [])),
                ])->filter()->unique()->take(4)->values()->all();
                continue;
            }

            $candidates[$key] = $hit;
        }

        return collect($candidates)
            ->sortBy('rank')
            ->values()
            ->map(function (array $entity): array {
                $entity['confidence_score'] = $this->confidenceScore($entity);

                return $entity;
            })
            ->filter(fn (array $entity): bool => (float) ($entity['confidence_score'] ?? 0) >= 0.35)
            ->values()
            ->all();
    }

    public function normalizeName(string $name): string
    {
        $value = Str::lower(trim($name));
        $value = preg_replace('#https?://#', '', $value) ?? $value;
        $value = preg_replace('#^www\.#', '', $value) ?? $value;
        $value = preg_replace('#\.(com|io|ai|co|net|org|app)\b#', '', $value) ?? $value;
        $value = preg_replace('/\b(seo|ai|geo|llm|tool|tools|platform|software|app|suite|hq|labs)\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array<int,string>
     */
    private function knownEntityNames(): array
    {
        return [
            'Semrush',
            'Ahrefs',
            'Surfer SEO',
            'Frase',
            'Profound',
            'AthenaHQ',
            'Otterly',
            'Peec',
            'SE Ranking',
            'Rankability',
            'Clearscope',
            'Scalenut',
            'NeuronWriter',
            'MarketMuse',
            'HubSpot',
            'Moz',
            'Reddit',
            'Gartner',
            'GitHub',
            'Wikipedia',
            'G2',
            'Capterra',
            'Forbes',
            'Search Engine Journal',
            'Search Engine Land',
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function ignoredNames(LlmTrackingQuery $query): array
    {
        $terms = [
            $query->target_brand,
            $query->target_domain,
            ...((array) ($query->brand_terms ?? [])),
            'ChatGPT',
            'Claude',
            'Gemini',
            'Perplexity',
            'Google',
            'OpenAI',
            'Anthropic',
            'LLM',
            'SEO',
            'GEO',
            'AI',
        ];

        return collect($terms)
            ->map(fn ($term): string => $this->normalizeName((string) $term))
            ->filter()
            ->mapWithKeys(fn (string $term): array => [$term => true])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function extractCapitalizedPhrases(string $answer): array
    {
        preg_match_all('/\b(?:[A-Z][A-Za-z0-9]+|[A-Z]{2,})(?:[\s-]+(?:[A-Z][A-Za-z0-9]+|[A-Z]{2,})){0,3}\b/u', $answer, $matches);

        return collect($matches[0] ?? [])
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 3)
            ->reject(fn (string $value): bool => $this->isGenericPhrase($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string,bool> $ignored
     * @param array<int,array<string,mixed>> $sources
     * @return array<string,mixed>|null
     */
    private function entityHit(string $answer, string $name, array $ignored, array $sources, LlmTrackingQuery $query): ?array
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '' || isset($ignored[$normalized]) || $this->isGenericPhrase($name)) {
            return null;
        }

        $pattern = '/\b' . preg_quote($name, '/') . '(?:\.com|\.io|\.ai)?\b/iu';
        preg_match_all($pattern, $answer, $matches, PREG_OFFSET_CAPTURE);
        $occurrences = (array) ($matches[0] ?? []);
        if ($occurrences === []) {
            return null;
        }

        $positions = collect($occurrences)
            ->map(fn ($match): int => (int) ($match[1] ?? -1))
            ->filter(fn (int $position): bool => $position >= 0)
            ->values();
        if ($positions->isEmpty()) {
            return null;
        }

        $rank = $this->rankFromAnswer($answer, (int) $positions->min());
        $sourceUrls = $this->matchingSourceUrls($sources, $normalized);

        return [
            'brand_name' => $this->displayName($name),
            'normalized_name' => $normalized,
            'entity_category' => $this->categoryFor($name, $sources, $query),
            'mention_count' => $positions->count(),
            'rank' => $rank,
            'source_urls' => $sourceUrls,
            'context_snippets' => $this->contextSnippets($answer, $positions->all()),
            'same_category' => $this->sameCategory($answer, $name, $query),
            'reason' => $sourceUrls !== []
                ? 'Mentioned in the model answer and connected to cited source URLs.'
                : 'Mentioned in the model answer near the tracked category context.',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array<int,string>
     */
    private function matchingSourceUrls(array $sources, string $normalized): array
    {
        $needle = str_replace(' ', '', $normalized);

        return collect($sources)
            ->filter(function (array $source) use ($needle): bool {
                $url = Str::lower((string) ($source['url'] ?? ''));
                $domain = Str::lower((string) ($source['domain'] ?? ''));

                return $needle !== '' && (Str::contains(str_replace(['-', '.'], '', $url), $needle)
                    || Str::contains(str_replace(['-', '.'], '', $domain), $needle));
            })
            ->pluck('url')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function categoryFor(string $name, array $sources, LlmTrackingQuery $query): string
    {
        $normalized = $this->normalizeName($name);
        $sourceDomains = collect($sources)->pluck('domain')->map(fn ($domain): string => Str::lower((string) $domain))->all();

        if (in_array($normalized, collect((array) ($query->competitor_terms ?? []))->map(fn ($term): string => $this->normalizeName((string) $term))->all(), true)) {
            return 'competitor';
        }

        if (collect(['reddit', 'gartner', 'g2', 'capterra', 'forbes', 'wikipedia'])->contains($normalized)) {
            return 'source_authority';
        }

        if (collect($sourceDomains)->contains(fn (string $domain): bool => Str::contains($domain, [$normalized, str_replace(' ', '', $normalized)]))) {
            return 'publisher';
        }

        if (collect(['github', 'wikipedia'])->contains($normalized)) {
            return 'ecosystem_entity';
        }

        if (Str::contains(Str::lower($name), ['hubspot', 'notion', 'zapier'])) {
            return 'complementary_platform';
        }

        return 'benchmark';
    }

    private function sameCategory(string $answer, string $name, LlmTrackingQuery $query): bool
    {
        $queryTokens = collect(preg_split('/[^a-z0-9]+/i', Str::lower((string) $query->query_text)) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 4)
            ->values();

        $position = stripos($answer, $name);
        if ($position === false) {
            return false;
        }

        $context = Str::lower(substr($answer, max(0, $position - 120), 260));

        return $queryTokens->contains(fn (string $token): bool => Str::contains($context, $token));
    }

    private function rankFromAnswer(string $answer, int $position): int
    {
        $before = substr($answer, 0, $position);
        preg_match_all('/(?:^|\n)\s*(?:[-*]|\d+[.)])\s+/u', $before, $matches);

        return max(1, count($matches[0] ?? []) + 1);
    }

    /**
     * @param array<int,int> $positions
     * @return array<int,string>
     */
    private function contextSnippets(string $answer, array $positions): array
    {
        return collect(array_slice($positions, 0, 4))
            ->map(function (int $position) use ($answer): string {
                $start = max(0, $position - 80);

                return trim(substr($answer, $start, 220));
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function confidenceScore(array $entity): float
    {
        $score = 0.25;
        $score += min(0.25, ((int) ($entity['mention_count'] ?? 0)) * 0.08);
        $score += ((array) ($entity['source_urls'] ?? [])) !== [] ? 0.20 : 0.0;
        $score += ! empty($entity['same_category']) ? 0.20 : 0.0;
        $score += in_array((string) ($entity['entity_category'] ?? ''), ['competitor', 'benchmark'], true) ? 0.10 : 0.05;

        return round(min(1.0, $score), 4);
    }

    private function displayName(string $name): string
    {
        $trimmed = trim(preg_replace('/\.(com|io|ai|co|net|org|app)\b/i', '', $name) ?? $name);

        return $trimmed === '' ? $name : $trimmed;
    }

    private function isGenericPhrase(string $phrase): bool
    {
        $normalized = $this->normalizeName($phrase);
        if ($normalized === '' || strlen($normalized) < 3) {
            return true;
        }

        $generic = [
            'best', 'content', 'tools', 'platform', 'software', 'workflow', 'marketing',
            'search', 'visibility', 'answer', 'model', 'source', 'sources', 'category',
            'buyer', 'comparison', 'recommendation', 'recommendations', 'complete',
            'generative engine optimization', 'chatgpt search visibility',
        ];

        return in_array($normalized, $generic, true);
    }
}
