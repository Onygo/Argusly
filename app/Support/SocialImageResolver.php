<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\SocialPublication;
use App\Services\ContentImages\ContentImageAssetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialImageResolver
{
    public function __construct(private ?ContentImageAssetResolver $assets = null)
    {
        $this->assets ??= app(ContentImageAssetResolver::class);
    }

    /**
     * @return array{url:?string,source:?string}
     */
    public function resolveForPublication(SocialPublication $publication): array
    {
        $publication->loadMissing([
            'campaign',
            'variant.campaign',
            'variant.campaignContent',
            'variant.content.featuredImage',
        ]);

        $variant = $publication->variant;
        $content = $variant?->content;

        $candidates = [
            ['url' => $this->assets->urlForSocialPublication($publication, ContentImage::USAGE_LINKEDIN, 'linkedin'), 'source' => 'publication_linkedin_asset'],
            ['url' => $this->assets->urlForSocialPublication($publication, ContentImage::USAGE_SOCIAL, $this->platformValue($publication->platform)), 'source' => 'publication_social_asset'],
            ['url' => $this->imageUrlFromMediaRefs((array) data_get($publication->payload_snapshot, 'media_refs')), 'source' => 'payload_media_refs'],
            ['url' => $this->imageUrlFromMediaRefs((array) ($variant?->media_refs ?? [])), 'source' => 'variant_media_refs'],
            ['url' => $content ? $this->assets->urlForContent($content, ContentImage::USAGE_LINKEDIN, 'linkedin') : '', 'source' => 'content_linkedin_asset'],
            ['url' => $content ? $this->assets->urlForContent($content, ContentImage::USAGE_SOCIAL, $this->platformValue($publication->platform)) : '', 'source' => 'content_social_asset'],
            ['url' => $this->firstNonEmpty([
                data_get($publication->payload_snapshot, 'linkedin_image_url'),
                data_get($publication->payload_snapshot, 'social_image_url'),
                data_get($publication->payload_snapshot, 'seo_og_image'),
                data_get($publication->payload_snapshot, 'og_image'),
                data_get($publication->payload_snapshot, 'og_image_url'),
                data_get($publication->payload_snapshot, 'meta.og_image'),
                data_get($publication->payload_snapshot, 'meta.image'),
                $content?->seo_og_image,
            ]), 'source' => 'og_image'],
            ['url' => $this->featuredImageUrl($content), 'source' => 'featured_image'],
            ['url' => $this->firstNonEmpty([
                data_get($variant?->metadata, 'social_image_url'),
                data_get($variant?->metadata, 'fallback_image_url'),
                data_get($variant?->campaignContent?->metadata, 'social_image_url'),
                data_get($variant?->campaignContent?->metadata, 'fallback_image_url'),
                data_get($variant?->campaignContent?->metadata, 'image_url'),
                data_get($variant?->campaignContent?->channel_requirements, 'linkedin.image_url'),
                data_get($variant?->campaignContent?->channel_requirements, 'social.image_url'),
                data_get($variant?->campaign?->metadata, 'social_image_url'),
                data_get($variant?->campaign?->metadata, 'fallback_image_url'),
                data_get($variant?->campaign?->metadata, 'default_image_url'),
                data_get($publication->campaign?->metadata, 'social_image_url'),
                data_get($publication->campaign?->metadata, 'fallback_image_url'),
                data_get($publication->campaign?->metadata, 'default_image_url'),
            ]), 'source' => 'campaign_fallback'],
            ['url' => $this->defaultImage(), 'source' => 'global_fallback'],
        ];

        foreach ($candidates as $candidate) {
            $url = $this->absoluteUrl((string) $candidate['url']);

            if ($url !== '') {
                return ['url' => $url, 'source' => $candidate['source']];
            }
        }

        return ['url' => null, 'source' => null];
    }

    public function resolve(?string $explicitImage = null, ?string $canonicalUrl = null, ?string $pageType = null, ?Request $request = null): string
    {
        $request ??= request();

        foreach ([$explicitImage, $this->mappedImage($pageType, $request), $this->deterministicFallback($canonicalUrl, $request), $this->defaultImage()] as $candidate) {
            $url = $this->absoluteUrl((string) $candidate);

            if ($url !== '') {
                return $url;
            }
        }

        return asset(ltrim((string) config('argusly_social.default_image'), '/'));
    }

    public function deterministicFallback(?string $canonicalUrl = null, ?Request $request = null): string
    {
        $request ??= request();
        $variants = array_values(array_filter((array) config('argusly_social.variants', []), fn ($variant): bool => is_string($variant) && trim($variant) !== ''));

        if ($variants === []) {
            return (string) config('argusly_social.default_image', '');
        }

        $routeName = $this->normalizedRouteName($request);
        $key = trim((string) $canonicalUrl) !== '' ? trim((string) $canonicalUrl) : ($routeName !== '' ? $routeName : $request->url());

        return $variants[abs(crc32($key)) % count($variants)];
    }

    private function mappedImage(?string $pageType, Request $request): string
    {
        $pageType = trim((string) $pageType);
        $pageTypeMappings = (array) config('argusly_social.page_type_mapping', []);

        if ($pageType !== '' && is_string($pageTypeMappings[$pageType] ?? null)) {
            return (string) $pageTypeMappings[$pageType];
        }

        $routeName = $this->normalizedRouteName($request);
        $routeMappings = (array) config('argusly_social.route_type_mapping', []);

        foreach ($routeMappings as $pattern => $image) {
            if (is_string($pattern) && is_string($image) && Str::is($pattern, $routeName)) {
                return $image;
            }
        }

        return '';
    }

    private function defaultImage(): string
    {
        return (string) config('argusly_social.default_image', '');
    }

    private function platformValue(mixed $platform): string
    {
        if ($platform instanceof \BackedEnum) {
            return (string) $platform->value;
        }

        return strtolower(trim((string) $platform));
    }

    /**
     * @param array<int,mixed> $mediaRefs
     */
    private function imageUrlFromMediaRefs(array $mediaRefs): string
    {
        $refs = collect($mediaRefs)
            ->map(fn (mixed $media): array => $this->normalizeMediaRef($media))
            ->filter(fn (array $media): bool => $media['url'] !== '' && in_array($media['type'], ['image', 'photo'], true));

        $preferred = $refs->first(fn (array $media): bool => $media['is_preferred']);
        if (is_array($preferred)) {
            return (string) $preferred['url'];
        }

        $first = $refs->first();

        return is_array($first) ? (string) $first['url'] : '';
    }

    /**
     * @return array{url:string,type:string,is_preferred:bool}
     */
    private function normalizeMediaRef(mixed $media): array
    {
        if (is_string($media)) {
            return ['url' => trim($media), 'type' => 'image', 'is_preferred' => true];
        }

        if (! is_array($media)) {
            return ['url' => '', 'type' => '', 'is_preferred' => false];
        }

        $type = strtolower((string) data_get($media, 'type', data_get($media, 'media_type', 'image')));
        $url = trim((string) data_get($media, 'url', data_get($media, 'image_url', data_get($media, 'path', ''))));
        $usage = strtolower((string) data_get($media, 'usage', data_get($media, 'purpose', data_get($media, 'context', ''))));
        $platform = strtolower((string) data_get($media, 'platform', ''));
        $channels = collect((array) data_get($media, 'channels', []))->map(fn (mixed $channel): string => strtolower((string) $channel));

        return [
            'url' => $url,
            'type' => $type,
            'is_preferred' => $platform === 'linkedin'
                || $channels->contains('linkedin')
                || in_array($usage, ['linkedin', 'social', 'meta', 'og', 'open_graph'], true),
        ];
    }

    private function featuredImageUrl(?Content $content): string
    {
        if (! $content) {
            return '';
        }

        $image = $content->featuredImage;

        if ($image instanceof ContentImage && ! $this->featuredImageAllowedForSocial($image)) {
            return '';
        }

        return $this->firstNonEmpty([
            $content->public_blog_featured_image_url,
            $image?->original_ui_url,
            $image?->image_url,
        ]);
    }

    private function featuredImageAllowedForSocial(ContentImage $image): bool
    {
        if ((string) ($image->source ?? '') === ContentImage::SOURCE_UPLOAD) {
            return $image->allowsUsage(ContentImage::USAGE_SOCIAL);
        }

        foreach ([
            'social_allowed',
            'allow_social',
            'allowed_for_social',
            'usage.social',
            'usage.meta',
        ] as $key) {
            if (data_get($image->metadata, $key) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,mixed> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizedRouteName(Request $request): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        return Str::startsWith($routeName, 'test.') ? Str::after($routeName, 'test.') : $routeName;
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return asset(ltrim($url, '/'));
    }
}
