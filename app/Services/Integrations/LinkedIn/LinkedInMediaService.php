<?php

namespace App\Services\Integrations\LinkedIn;

use App\Models\SocialMediaAsset;
use App\Models\SocialPost;
use Illuminate\Support\Collection;

class LinkedInMediaService
{
    public function uploadNotImplementedMessage(): string
    {
        return 'media upload not implemented yet';
    }

    /**
     * @return Collection<int, SocialMediaAsset>
     */
    public function mediaAssetsForPost(SocialPost $post): Collection
    {
        $ids = collect($post->media ?? [])
            ->map(fn (mixed $item) => is_array($item) ? ($item['social_media_asset_id'] ?? $item['id'] ?? null) : $item)
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $query = SocialMediaAsset::query()
            ->where('account_id', $post->account_id)
            ->where('brand_id', $post->brand_id)
            ->where('provider', 'linkedin')
            ->where(function ($query) use ($post): void {
                $query->whereNull('social_post_id')
                    ->orWhere('social_post_id', $post->id);
            });

        if ($ids->isNotEmpty()) {
            return $query->whereIn('id', $ids)->get();
        }

        return $query->where('social_post_id', $post->id)->get();
    }

    public function hasMediaForPost(SocialPost $post): bool
    {
        if ($this->mediaAssetsForPost($post)->isNotEmpty()) {
            return true;
        }

        return collect($post->media ?? [])->isNotEmpty();
    }
}
