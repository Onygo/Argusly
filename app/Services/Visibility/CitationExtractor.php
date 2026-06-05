<?php

namespace App\Services\Visibility;

use App\Models\Brand;
use App\Models\VisibilityCitation;
use App\Models\VisibilityProviderRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CitationExtractor
{
    /**
     * @return Collection<int, VisibilityCitation>
     */
    public function extractForRun(VisibilityProviderRun $run, Brand $brand): Collection
    {
        $run->loadMissing('citations');
        $citations = collect();

        foreach ($run->citations as $citation) {
            $citations->push($this->normalizeCitation($citation, $brand));
        }

        foreach ($this->urlsFromText((string) ($run->raw_response ?: $run->normalized_answer)) as $url) {
            $domain = $this->domainFromUrl($url);

            if ($domain === null) {
                continue;
            }

            $citations->push(VisibilityCitation::query()->firstOrCreate(
                [
                    'provider_run_id' => $run->id,
                    'source_url' => $url,
                ],
                [
                    'account_id' => $run->account_id,
                    'brand_id' => $run->brand_id,
                    'visibility_check_id' => $run->visibility_check_id,
                    'url' => $url,
                    'source_domain' => $domain,
                    'domain' => $domain,
                    'source_title' => Str::headline($domain),
                    'title' => Str::headline($domain),
                    'citation_type' => 'external',
                    'confidence_score' => 60,
                    'trust_score' => 60,
                    'metadata_json' => ['extracted_from' => 'provider_response'],
                    'metadata' => ['extracted_from' => 'provider_response'],
                ],
            ));
        }

        return $citations->unique('id')->values();
    }

    public function domainFromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = Str::lower((string) $host);
        $host = preg_replace('/^www\./', '', $host);

        return $host ? trim($host, "/ \t\n\r\0\x0B") : null;
    }

    /**
     * @return array<int, string>
     */
    private function urlsFromText(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s\]\)"\'<>]+/i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function normalizeCitation(VisibilityCitation $citation, Brand $brand): VisibilityCitation
    {
        $domain = $citation->source_domain ?: $citation->domain ?: $this->domainFromUrl($citation->source_url ?: $citation->url);
        $ownedDomain = $this->domainFromUrl($brand->website_url ?: $brand->domain);
        $isOwned = $ownedDomain !== null && $domain !== null && ($domain === $ownedDomain || str_ends_with($domain, ".{$ownedDomain}"));

        $citation->forceFill([
            'visibility_check_id' => $citation->visibility_check_id ?: $citation->providerRun?->visibility_check_id,
            'source_url' => $citation->source_url ?: $citation->url,
            'source_domain' => $domain,
            'source_title' => $citation->source_title ?: $citation->title,
            'citation_type' => $isOwned ? 'owned' : ($citation->citation_type ?: 'external'),
            'is_owned_source' => $citation->is_owned_source || $isOwned,
            'confidence_score' => $citation->confidence_score ?: $citation->trust_score ?: 50,
            'metadata_json' => $citation->metadata_json ?: $citation->metadata,
        ])->save();

        return $citation->refresh();
    }
}
