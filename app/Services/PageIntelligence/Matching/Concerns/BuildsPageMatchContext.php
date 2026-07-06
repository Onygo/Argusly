<?php

namespace App\Services\PageIntelligence\Matching\Concerns;

use App\Models\MonitoredPage;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait BuildsPageMatchContext
{
    protected function latestSnapshot(MonitoredPage $page): ?PageSnapshot
    {
        return $page->relationLoaded('latestSnapshot')
            ? $page->latestSnapshot
            : $page->latestSnapshot()->first();
    }

    protected function latestExtraction(MonitoredPage $page, ?PageSnapshot $snapshot = null): ?PageContentExtraction
    {
        if ($snapshot?->relationLoaded('contentExtraction')) {
            return $snapshot->contentExtraction;
        }

        if ($snapshot) {
            $extraction = $snapshot->contentExtraction()->first();
            if ($extraction instanceof PageContentExtraction) {
                return $extraction;
            }
        }

        return $page->latestContentExtraction()->first();
    }

    protected function pageText(MonitoredPage $page, ?PageContentExtraction $extraction): string
    {
        return collect([
            $page->title_current,
            $page->canonical_url,
            $page->first_seen_url,
            $page->final_url,
            $page->domain,
            $extraction?->title,
            $extraction?->meta_description,
            $extraction?->summary,
            $extraction?->mainTextForAnalysis(),
            json_encode((array) ($extraction?->headings_json ?? []), JSON_UNESCAPED_SLASHES),
        ])->filter()->implode("\n");
    }

    protected function pageUrls(MonitoredPage $page): array
    {
        return collect([
            $page->canonical_url,
            $page->first_seen_url,
            $page->final_url,
        ])->filter()->map(fn (mixed $url): string => (string) $url)->unique()->values()->all();
    }

    protected function links(?PageContentExtraction $extraction = null): Collection
    {
        return collect([
            ...(array) ($extraction?->outbound_links_json ?? []),
            ...(array) ($extraction?->internal_links_json ?? []),
        ])->map(function (mixed $link): array {
            if (is_string($link)) {
                return ['href' => $link, 'text' => ''];
            }

            return [
                'href' => (string) data_get($link, 'href', data_get($link, 'url', '')),
                'text' => (string) data_get($link, 'text', data_get($link, 'anchor', data_get($link, 'label', ''))),
            ];
        })->filter(fn (array $link): bool => trim($link['href']) !== '' || trim($link['text']) !== '');
    }

    protected function containsTerm(string $haystack, string $term): bool
    {
        $term = trim($term);

        return $term !== '' && Str::contains(Str::lower($haystack), Str::lower($term));
    }

    protected function termList(array $values): array
    {
        return collect($values)
            ->flatMap(fn (mixed $value): array => is_array($value) ? $value : [$value])
            ->flatMap(function (mixed $value): array {
                if (is_string($value)) {
                    return [$value];
                }

                if (is_array($value)) {
                    return collect($value)->flatten()->filter(fn (mixed $item): bool => is_scalar($item))->map(fn (mixed $item): string => (string) $item)->all();
                }

                return [];
            })
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '' && mb_strlen($value) >= 3)
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
    }

    protected function sameUrlOrPath(string $candidate, string $url): bool
    {
        $candidateParts = parse_url($candidate);
        $urlParts = parse_url($url);

        if (! is_array($candidateParts) || ! is_array($urlParts)) {
            return trim($candidate) === trim($url);
        }

        $candidateHost = Str::lower((string) ($candidateParts['host'] ?? ''));
        $urlHost = Str::lower((string) ($urlParts['host'] ?? ''));
        $candidatePath = rtrim((string) ($candidateParts['path'] ?? '/'), '/') ?: '/';
        $urlPath = rtrim((string) ($urlParts['path'] ?? '/'), '/') ?: '/';

        return $candidateHost !== '' && $candidateHost === $urlHost && $candidatePath === $urlPath;
    }

    protected function hostMatches(string $domain, string $host): bool
    {
        $domain = Str::lower(trim($domain));
        $host = Str::lower(trim($host));

        return $domain !== '' && ($host === $domain || Str::endsWith($host, '.'.$domain));
    }

    protected function hostFromUrl(string $url): string
    {
        return Str::lower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    }

    protected function queryPairs(string $url): array
    {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        $pairs = [];
        parse_str($query, $pairs);

        return collect($pairs)->mapWithKeys(fn (mixed $value, string|int $key): array => [Str::lower((string) $key) => Str::lower((string) $value)])->all();
    }

    protected function snippet(string $text, string $term, int $radius = 80): string
    {
        $position = stripos($text, $term);
        if ($position === false) {
            return Str::limit($text, $radius * 2);
        }

        $start = max(0, $position - $radius);

        return Str::limit(substr($text, $start, $radius * 2 + strlen($term)), $radius * 2 + strlen($term));
    }
}
