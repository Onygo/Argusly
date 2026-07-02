<?php

namespace App\Services\ContentImages;

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\SocialPublication;
use Illuminate\Database\Eloquent\Builder;

class ContentImageAssetResolver
{
    public function imageForContent(Content $content, string $usage, ?string $platform = null): ?ContentImage
    {
        $usage = $this->normalizeUsage($usage, $platform);

        $image = $this->baseContentQuery($content)
            ->where(fn (Builder $query): Builder => $this->usageConstraint($query, $usage))
            ->latest('created_at')
            ->first();

        if ($image instanceof ContentImage) {
            return $image;
        }

        return $this->legacyImageForContent($content, $usage);
    }

    public function urlForContent(Content $content, string $usage, ?string $platform = null): string
    {
        $usage = $this->normalizeUsage($usage, $platform);
        $image = $this->imageForContent($content, $usage, $platform);

        return $image?->bestUrlForUsage($usage) ?? '';
    }

    public function imageForSocialPublication(SocialPublication $publication, string $usage, ?string $platform = null): ?ContentImage
    {
        $usage = $this->normalizeUsage($usage, $platform);

        $image = ContentImage::query()
            ->where('social_publication_id', (string) $publication->id)
            ->where('status', 'ready')
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $this->usageConstraint($query, $usage))
            ->latest('created_at')
            ->first();

        if ($image instanceof ContentImage) {
            return $image;
        }

        if ($publication->campaign_id) {
            $image = ContentImage::query()
                ->where('campaign_id', (string) $publication->campaign_id)
                ->whereNull('social_publication_id')
                ->where('status', 'ready')
                ->where('is_active', true)
                ->where(fn (Builder $query): Builder => $this->usageConstraint($query, $usage))
                ->latest('created_at')
                ->first();

            if ($image instanceof ContentImage) {
                return $image;
            }
        }

        return null;
    }

    public function urlForSocialPublication(SocialPublication $publication, string $usage, ?string $platform = null): string
    {
        $usage = $this->normalizeUsage($usage, $platform);
        $image = $this->imageForSocialPublication($publication, $usage, $platform);

        return $image?->bestUrlForUsage($usage) ?? '';
    }

    private function baseContentQuery(Content $content): Builder
    {
        return ContentImage::query()
            ->where('content_id', (string) $content->id)
            ->where('status', 'ready')
            ->where('is_active', true);
    }

    private function usageConstraint(Builder $query, string $usage): Builder
    {
        return match ($usage) {
            ContentImage::USAGE_WEBSITE => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('display_on_website', true)
                    ->orWhere('display_as_featured_image', true)),
            ContentImage::USAGE_FEATURED => $query->where('display_as_featured_image', true),
            ContentImage::USAGE_META => $query->where('use_as_meta_image', true),
            ContentImage::USAGE_LINKEDIN => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('use_for_linkedin', true)
                    ->orWhere('use_as_social_image', true)),
            ContentImage::USAGE_SOCIAL => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('use_as_social_image', true)
                    ->orWhere('use_for_linkedin', true)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function legacyImageForContent(Content $content, string $usage): ?ContentImage
    {
        $type = match ($usage) {
            ContentImage::USAGE_WEBSITE, ContentImage::USAGE_FEATURED => 'featured',
            ContentImage::USAGE_META => 'og',
            ContentImage::USAGE_SOCIAL, ContentImage::USAGE_LINKEDIN => null,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        return $this->baseContentQuery($content)
            ->where('type', $type)
            ->latest('created_at')
            ->first();
    }

    private function normalizeUsage(string $usage, ?string $platform): string
    {
        $usage = trim($usage);
        $platform = strtolower(trim((string) $platform));

        if ($platform === 'linkedin' && in_array($usage, [ContentImage::USAGE_SOCIAL, 'social'], true)) {
            return ContentImage::USAGE_LINKEDIN;
        }

        return match ($usage) {
            'display_on_website', 'website_display', 'website' => ContentImage::USAGE_WEBSITE,
            'display_as_featured_image', 'featured_image', 'featured' => ContentImage::USAGE_FEATURED,
            'use_as_meta_image', 'open_graph', 'og', 'meta' => ContentImage::USAGE_META,
            'use_as_social_image', 'social' => ContentImage::USAGE_SOCIAL,
            'use_for_linkedin', 'linkedin' => ContentImage::USAGE_LINKEDIN,
            default => $usage,
        };
    }
}
