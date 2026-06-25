<?php

namespace App\Services\EmailMarketing;

use App\Enums\CampaignContentAssetType;
use App\Models\CampaignContent;
use Illuminate\Support\Str;

class EmailCampaignPayloadBuilder
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function build(CampaignContent $asset, array $overrides = []): array
    {
        $asset->loadMissing('campaign', 'content.currentVersion', 'sourceContent.currentVersion');
        $campaign = $asset->campaign;

        $ctaUrl = trim((string) ($overrides['cta_url'] ?? data_get($asset->metadata, 'cta.url', '')));
        $trackingParameters = $this->trackingParameters($asset);

        $body = $this->snippetBody($asset, $overrides);
        $sourceUrl = trim((string) data_get($asset->metadata, 'source.url', ''));
        if ($sourceUrl === '') {
            $sourceUrl = trim((string) ($asset->content?->published_url ?? $asset->sourceContent?->published_url ?? ''));
        }
        $ctaUrl = $ctaUrl !== '' ? $ctaUrl : $sourceUrl;
        $trackedCtaUrl = $this->trackedUrl($ctaUrl, $trackingParameters);

        return [
            'source' => [
                'system' => 'argusly',
                'campaign_id' => (string) $asset->campaign_id,
                'campaign_content_id' => (string) $asset->id,
                'content_id' => $asset->content_id ? (string) $asset->content_id : null,
                'source_content_id' => $asset->source_content_id ? (string) $asset->source_content_id : null,
            ],
            'campaign' => [
                'name' => (string) ($campaign?->name ?? ''),
                'slug' => (string) ($campaign?->slug ?? ''),
                'objective' => $campaign?->objective,
                'audience' => $campaign?->audience ?? [],
                'goals' => $campaign?->goals ?? [],
                'kpis' => $campaign?->kpis ?? [],
            ],
            'email' => [
                'subject' => trim((string) ($overrides['subject'] ?? data_get($asset->metadata, 'email.subject', $asset->working_title))),
                'preheader' => trim((string) ($overrides['preheader'] ?? data_get($asset->metadata, 'email.preheader', ''))),
                'body' => $body,
                'cta_label' => trim((string) ($overrides['cta_label'] ?? data_get($asset->metadata, 'cta.label', 'Lees meer'))),
                'cta_url' => $trackedCtaUrl,
                'locale' => trim((string) ($overrides['locale'] ?? $asset->target_locale ?? '')) ?: null,
                'template_id' => trim((string) ($overrides['template_id'] ?? '')) ?: null,
                'audience_id' => trim((string) ($overrides['audience_id'] ?? '')) ?: null,
            ],
            'tracking' => [
                'utm' => $trackingParameters,
            ],
            'asset' => [
                'type' => (string) ($asset->asset_type?->value ?? $asset->asset_type),
                'title' => (string) $asset->working_title,
                'source_title' => (string) ($asset->content?->title ?? $asset->sourceContent?->title ?? ''),
                'source_url' => $sourceUrl ?: null,
                'status' => (string) $asset->status,
                'approval_status' => (string) ($asset->approval_status?->value ?? $asset->approval_status),
                'scheduled_for' => $asset->scheduled_for?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function snippetBody(CampaignContent $asset, array $overrides): string
    {
        $explicit = trim((string) ($overrides['body'] ?? data_get($asset->metadata, 'body', '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $metadataCandidates = [
            data_get($asset->metadata, 'email.body'),
            data_get($asset->metadata, 'snippet.body'),
            data_get($asset->metadata, 'summary'),
            data_get($asset->metadata, 'excerpt'),
        ];

        foreach ($metadataCandidates as $candidate) {
            $text = $this->plainText($candidate);
            if ($text !== '') {
                return $text;
            }
        }

        $sourceBody = $this->plainText($asset->content?->currentVersion?->body)
            ?: $this->plainText($asset->sourceContent?->currentVersion?->body);

        if ($sourceBody !== '') {
            return $this->newsletterSummary($asset, $sourceBody);
        }

        $description = $this->plainText(data_get($asset->brief, 'description'));
        if ($description !== '') {
            return $description;
        }

        return $this->plainText($asset->working_title) ?: 'Bekijk de laatste update in deze campagne.';
    }

    private function newsletterSummary(CampaignContent $asset, string $sourceBody): string
    {
        $title = trim((string) ($asset->content?->title ?? $asset->sourceContent?->title ?? $asset->working_title));
        $intro = $title !== '' ? $title."\n\n" : '';

        return $intro.Str::limit($sourceBody, 520, '...');
    }

    private function plainText(mixed $value): string
    {
        $text = trim(strip_tags(html_entity_decode((string) $value)));
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';

        return trim($text);
    }

    public function assertExportable(CampaignContent $asset): void
    {
        if ($asset->asset_type !== CampaignContentAssetType::NEWSLETTER_SNIPPET) {
            throw new EmailMarketingProviderException('Only newsletter snippets can be exported to email marketing tools.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function trackingParameters(CampaignContent $asset): array
    {
        $campaign = $asset->campaign;
        $parameters = $campaign?->trackingParameters() ?? [];

        return array_filter([
            'utm_source' => $parameters['utm_source'] ?? 'argusly',
            'utm_medium' => $parameters['utm_medium'] ?? 'email',
            'utm_campaign' => $parameters['utm_campaign'] ?? Str::slug((string) ($campaign?->slug ?: $campaign?->name ?: $asset->campaign_id)),
            'utm_content' => (string) $asset->id,
            'utm_term' => $parameters['utm_term'] ?? null,
        ], static fn (?string $value): bool => trim((string) $value) !== '');
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function trackedUrl(string $url, array $parameters): ?string
    {
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = array_replace($query, $parameters);

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $userInfo = $parts['user'] ?? null;
        if ($userInfo !== null) {
            $authority = $userInfo.(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'.$authority;
        }

        return $parts['scheme'].'://'.$authority
            .($parts['path'] ?? '')
            .($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
