<?php

namespace App\Support;

use App\Models\ContentImage;

class ImageAttribution
{
    /**
     * @return array<string,string>
     */
    public static function fromContentImage(?ContentImage $image): array
    {
        $metadata = is_array($image?->metadata) ? $image->metadata : [];
        $attribution = is_array(data_get($metadata, 'attribution'))
            ? (array) data_get($metadata, 'attribution')
            : [];

        $text = trim((string) data_get($attribution, 'text'));
        $photographerName = trim((string) data_get($attribution, 'photographer_name'));
        $photographerUrl = trim((string) data_get($attribution, 'photographer_url'));
        $providerName = trim((string) data_get($attribution, 'provider_name'));
        $providerUrl = trim((string) data_get($attribution, 'provider_url'));

        if ($text === '' && ($photographerName !== '' || $providerName !== '')) {
            $text = trim('Photo by '.$photographerName.' on '.$providerName);
        }

        return array_filter([
            'text' => $text,
            'photographer_name' => $photographerName,
            'photographer_url' => self::withReferralParams($photographerUrl),
            'provider_name' => $providerName,
            'provider_url' => self::withReferralParams($providerUrl),
            'license' => trim((string) data_get($metadata, 'license')),
            'photo_url' => self::withReferralParams(trim((string) data_get($metadata, 'photo_url'))),
        ], static fn (string $value): bool => $value !== '');
    }

    public static function toHtml(?ContentImage $image): string
    {
        $attribution = self::fromContentImage($image);
        if ($attribution === []) {
            return '';
        }

        $photographerName = $attribution['photographer_name'] ?? '';
        $photographerUrl = $attribution['photographer_url'] ?? '';
        $providerName = $attribution['provider_name'] ?? '';
        $providerUrl = $attribution['provider_url'] ?? '';

        if ($photographerName === '' || $photographerUrl === '' || $providerName === '' || $providerUrl === '') {
            return '';
        }

        return '<aside class="argusly-image-attribution" data-argusly-image-attribution="featured">'
            .'Photo by <a href="'.e($photographerUrl).'" rel="noopener" target="_blank">'.e($photographerName).'</a>'
            .' on <a href="'.e($providerUrl).'" rel="noopener" target="_blank">'.e($providerName).'</a>.'
            .'</aside>';
    }

    private static function withReferralParams(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query([
            'utm_source' => 'argusly',
            'utm_medium' => 'referral',
        ]);
    }
}
