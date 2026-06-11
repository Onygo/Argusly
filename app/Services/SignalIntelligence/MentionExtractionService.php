<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalEntityType;
use App\Enums\SignalSourceType;
use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\SignalFeedItem;
use App\Models\SignalMention;
use App\Models\SiteCompetitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MentionExtractionService
{
    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function extract(SignalFeedItem $feedItem): Collection
    {
        $workspace = $feedItem->workspace()->first();

        if (! $workspace) {
            return collect();
        }

        $text = $this->text($feedItem);
        $candidates = $this->candidates($feedItem);
        $seen = [];

        return $candidates
            ->map(function (array $candidate) use ($feedItem, $text, &$seen): ?array {
                $match = $this->match($text, $candidate);

                if (! $match) {
                    return null;
                }

                $key = $candidate['mention_type'].'|'.$candidate['entity_type'].'|'.Str::lower($candidate['name']);

                if (isset($seen[$key])) {
                    return null;
                }

                $seen[$key] = true;

                return [
                    'organization_id' => $feedItem->organization_id,
                    'workspace_id' => $feedItem->workspace_id,
                    'client_site_id' => $feedItem->client_site_id,
                    'signal_feed_item_id' => $feedItem->id,
                    'source_type' => SignalSourceType::WEBSITE_FEED->value,
                    'source_ref_type' => SignalFeedItem::class,
                    'source_ref_id' => (string) $feedItem->id,
                    'mention_type' => $candidate['mention_type'],
                    'entity_type' => $candidate['entity_type'],
                    'entity_name' => $candidate['name'],
                    'entity_key' => app(SignalEntityResolver::class)->entityKey($candidate['name']),
                    'url' => $feedItem->url,
                    'url_hash' => $feedItem->url_hash,
                    'context' => $this->excerpt($text, $match['offset'], strlen($match['needle'])),
                    'sentiment_label' => null,
                    'sentiment_score' => null,
                    'position_score' => $this->positionScore($match['offset'], strlen($text)),
                    'confidence_score' => $match['confidence'],
                    'observed_at' => $feedItem->published_at ?? $feedItem->fetched_at ?? now(),
                    'metadata' => [
                        'matched_term' => $match['needle'],
                        'matched_as' => $match['matched_as'],
                        'source' => 'deterministic_extraction',
                    ],
                    'dedupe_hash' => $this->dedupeHash($feedItem, $candidate),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int,array{name:string,mention_type:string,entity_type:string,aliases:array<int,string>,match_type:string}>
     */
    private function candidates(SignalFeedItem $feedItem): Collection
    {
        $workspace = $feedItem->workspace()->first();
        $site = $feedItem->client_site_id ? ClientSite::query()->find($feedItem->client_site_id) : null;

        $candidates = collect();

        if ($workspace?->display_name) {
            $candidates->push($this->candidate($workspace->display_name, SignalMention::TYPE_BRAND, SignalEntityType::BRAND->value));
        }

        CompanyProfile::query()->where('workspace_id', $feedItem->workspace_id)->get()->each(function (CompanyProfile $profile) use ($candidates): void {
            if ($profile->company_name) {
                $candidates->push($this->candidate($profile->company_name, SignalMention::TYPE_BRAND, SignalEntityType::BRAND->value));
            }

            foreach ($profile->keyServicesArray() as $topic) {
                $candidates->push($this->candidate($topic, SignalMention::TYPE_TOPIC, SignalEntityType::TOPIC->value, matchType: 'partial'));
            }
        });

        CompanyIntelligenceProfile::query()->where('workspace_id', $feedItem->workspace_id)->get()->each(function (CompanyIntelligenceProfile $profile) use ($candidates): void {
            foreach (array_filter([$profile->company_name, $profile->brand_key, $profile->market_category]) as $name) {
                $candidates->push($this->candidate((string) $name, SignalMention::TYPE_BRAND, SignalEntityType::BRAND->value));
            }

            foreach (array_merge((array) $profile->primary_topics, (array) $profile->authority_areas, (array) $profile->strategic_keywords) as $topic) {
                $candidates->push($this->candidate((string) $topic, SignalMention::TYPE_TOPIC, SignalEntityType::TOPIC->value, matchType: 'partial'));
            }

            foreach (array_merge((array) $profile->direct_competitors, (array) $profile->indirect_competitors, (array) $profile->aspirational_competitors) as $competitor) {
                $candidates->push($this->candidate((string) $competitor, SignalMention::TYPE_COMPETITOR, SignalEntityType::COMPETITOR->value));
            }
        });

        BrandVoice::query()->where('workspace_id', $feedItem->workspace_id)->get()->each(function (BrandVoice $voice) use ($candidates): void {
            foreach ($voice->preferredTerminologyArray() as $topic) {
                $candidates->push($this->candidate($topic, SignalMention::TYPE_TOPIC, SignalEntityType::TOPIC->value, matchType: 'partial'));
            }
        });

        SiteCompetitor::query()->where('workspace_id', $feedItem->workspace_id)->get()->each(function (SiteCompetitor $competitor) use ($candidates): void {
            $aliases = array_filter([$competitor->domain]);
            $candidates->push($this->candidate($competitor->name, SignalMention::TYPE_COMPETITOR, SignalEntityType::COMPETITOR->value, $aliases));
        });

        if ($site) {
            foreach (array_filter([$site->base_url, $site->site_url, parse_url((string) $site->base_url, PHP_URL_HOST)]) as $domain) {
                $candidates->push($this->candidate((string) $domain, SignalMention::TYPE_SOURCE, SignalEntityType::DOMAIN->value, matchType: 'domain'));
            }
        }

        return $candidates
            ->filter(fn (array $candidate): bool => trim($candidate['name']) !== '')
            ->unique(fn (array $candidate): string => $candidate['mention_type'].'|'.$candidate['entity_type'].'|'.Str::lower($candidate['name']))
            ->values();
    }

    /**
     * @param array<int,string> $aliases
     * @return array{name:string,mention_type:string,entity_type:string,aliases:array<int,string>,match_type:string}
     */
    private function candidate(string $name, string $mentionType, string $entityType, array $aliases = [], string $matchType = 'exact'): array
    {
        return [
            'name' => trim($name),
            'mention_type' => $mentionType,
            'entity_type' => $entityType,
            'aliases' => array_values(array_filter(array_map('trim', $aliases))),
            'match_type' => $matchType,
        ];
    }

    /**
     * @param array{name:string,aliases:array<int,string>,match_type:string} $candidate
     * @return array{needle:string,offset:int,confidence:int,matched_as:string}|null
     */
    private function match(string $text, array $candidate): ?array
    {
        foreach (array_merge([$candidate['name']], $candidate['aliases']) as $term) {
            $term = trim((string) $term);

            if ($term === '') {
                continue;
            }

            $offset = mb_stripos($text, $term);

            if ($offset !== false) {
                return [
                    'needle' => $term,
                    'offset' => (int) $offset,
                    'confidence' => $candidate['match_type'] === 'domain' ? 85 : ($candidate['match_type'] === 'partial' ? 60 : 90),
                    'matched_as' => $candidate['match_type'],
                ];
            }
        }

        return null;
    }

    private function text(SignalFeedItem $feedItem): string
    {
        return trim(implode("\n", array_filter([
            $feedItem->title,
            $feedItem->summary,
            $feedItem->body,
            $feedItem->url,
        ])));
    }

    private function excerpt(string $text, int $offset, int $length): string
    {
        $start = max(0, $offset - 80);

        return trim(mb_substr($text, $start, $length + 160));
    }

    private function positionScore(int $offset, int $textLength): float
    {
        if ($textLength <= 0) {
            return 0;
        }

        return round(max(0, 100 - (($offset / $textLength) * 100)), 2);
    }

    /**
     * @param array{name:string,mention_type:string,entity_type:string} $candidate
     */
    private function dedupeHash(SignalFeedItem $feedItem, array $candidate): string
    {
        return hash('sha256', implode('|', [
            $feedItem->workspace_id,
            $feedItem->id,
            $candidate['mention_type'],
            $candidate['entity_type'],
            Str::lower($candidate['name']),
        ]));
    }
}
