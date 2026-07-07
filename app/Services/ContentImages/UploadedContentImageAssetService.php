<?php

namespace App\Services\ContentImages;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\SocialPublication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UploadedContentImageAssetService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_WIDTH = 8000;

    private const MAX_HEIGHT = 8000;

    /**
     * @param array<string,bool> $usage
     */
    public function uploadForContent(Content $content, UploadedFile $file, array $usage, User $user, ?string $altText = null): ContentImage
    {
        $content->loadMissing('workspace');

        return $this->storeUploadedImage($file, $usage, $user, [
            'workspace_id' => (string) $content->workspace_id,
            'content_id' => (string) $content->id,
        ], $altText);
    }

    /**
     * @param array<string,bool> $usage
     */
    public function uploadForCampaign(Campaign $campaign, UploadedFile $file, array $usage, User $user, ?string $altText = null): ContentImage
    {
        return $this->storeUploadedImage($file, $usage, $user, [
            'workspace_id' => (string) $campaign->workspace_id,
            'campaign_id' => (string) $campaign->id,
        ], $altText);
    }

    /**
     * @param array<string,bool> $usage
     */
    public function uploadForSocialPublication(SocialPublication $publication, UploadedFile $file, array $usage, User $user, ?string $altText = null): ContentImage
    {
        return $this->storeUploadedImage($file, $usage, $user, [
            'workspace_id' => (string) $publication->workspace_id,
            'campaign_id' => $publication->campaign_id ? (string) $publication->campaign_id : null,
            'social_publication_id' => (string) $publication->id,
            'social_post_variant_id' => $publication->social_post_variant_id ? (string) $publication->social_post_variant_id : null,
        ], $altText);
    }

    /**
     * @param array<string,bool> $usage
     */
    public function assignUsageForContent(Content $content, ContentImage $image, array $usage): ContentImage
    {
        if ((string) $image->content_id !== (string) $content->id) {
            throw new RuntimeException('Image asset does not belong to this content item.');
        }

        $usage = $this->normalizeUsage($usage, allowEmpty: true);

        return DB::transaction(function () use ($content, $image, $usage): ContentImage {
            $this->deactivateConflictingContentImages((string) $content->id, $usage, (string) $image->id);

            $metadata = is_array($image->metadata) ? $image->metadata : [];
            $metadata['usage'] = $usage;
            $metadata['usage_updated_at'] = now()->toIso8601String();
            $hasUsage = in_array(true, $usage, true);

            $image->forceFill([
                'workspace_id' => $image->workspace_id ?: (string) $content->workspace_id,
                'type' => $hasUsage ? $this->typeForUsage($usage) : (string) ($image->type ?: 'asset'),
                'is_active' => $hasUsage,
                'display_on_website' => $usage['display_on_website'],
                'display_as_featured_image' => $usage['display_as_featured_image'],
                'use_as_meta_image' => $usage['use_as_meta_image'],
                'use_as_social_image' => $usage['use_as_social_image'],
                'use_for_linkedin' => $usage['use_for_linkedin'],
                'metadata' => $metadata,
            ])->save();

            return $image->refresh();
        });
    }

    /**
     * @param array<string,bool> $usage
     */
    public function assignUsageForCampaign(Campaign $campaign, ContentImage $image, array $usage): ContentImage
    {
        if ((string) $image->campaign_id !== (string) $campaign->id) {
            throw new RuntimeException('Image asset does not belong to this campaign.');
        }

        $usage = $this->normalizeUsage($usage, allowEmpty: true);

        return DB::transaction(function () use ($campaign, $image, $usage): ContentImage {
            $this->deactivateConflictingCampaignImages((string) $campaign->id, $usage, (string) $image->id);

            $metadata = is_array($image->metadata) ? $image->metadata : [];
            $metadata['usage'] = $usage;
            $metadata['usage_updated_at'] = now()->toIso8601String();
            $hasUsage = in_array(true, $usage, true);

            $image->forceFill([
                'workspace_id' => $image->workspace_id ?: (string) $campaign->workspace_id,
                'type' => $hasUsage ? $this->typeForUsage($usage) : (string) ($image->type ?: 'asset'),
                'is_active' => $hasUsage,
                'display_on_website' => $usage['display_on_website'],
                'display_as_featured_image' => $usage['display_as_featured_image'],
                'use_as_meta_image' => $usage['use_as_meta_image'],
                'use_as_social_image' => $usage['use_as_social_image'],
                'use_for_linkedin' => $usage['use_for_linkedin'],
                'metadata' => $metadata,
            ])->save();

            return $image->refresh();
        });
    }


    /**
     * @param array<string,bool> $usage
     * @param array<string,mixed> $context
     */
    private function storeUploadedImage(UploadedFile $file, array $usage, User $user, array $context, ?string $altText = null): ContentImage
    {
        $usage = $this->normalizeUsage($usage);
        $this->assertUsableImage($file);

        $dimensions = $this->dimensions($file);
        $workspaceId = trim((string) ($context['workspace_id'] ?? ''));
        if ($workspaceId === '') {
            throw new RuntimeException('Uploaded image asset requires a workspace.');
        }

        $disk = $this->disk();
        $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg'));
        $assetId = (string) Str::uuid();
        $path = ContentImage::storagePath(sprintf('uploads/%s/%s.%s', $workspaceId, $assetId, $extension));
        $binary = (string) file_get_contents($file->getRealPath());

        if ($binary === '') {
            throw new RuntimeException('Uploaded image file is empty.');
        }

        Storage::disk($disk)->put($path, $binary);

        return DB::transaction(function () use ($context, $usage, $user, $file, $dimensions, $assetId, $path, $disk, $altText): ContentImage {
            $contentId = trim((string) ($context['content_id'] ?? ''));

            if ($contentId !== '') {
                $this->deactivateConflictingContentImages($contentId, $usage);
            }

            $url = ContentImage::publicUrlForStorageValue((string) Storage::disk($disk)->url($path));

            return ContentImage::query()->create([
                'id' => $assetId,
                'workspace_id' => (string) $context['workspace_id'],
                'content_id' => $contentId !== '' ? $contentId : null,
                'campaign_id' => $context['campaign_id'] ?? null,
                'social_publication_id' => $context['social_publication_id'] ?? null,
                'social_post_variant_id' => $context['social_post_variant_id'] ?? null,
                'type' => $this->typeForUsage($usage),
                'source' => ContentImage::SOURCE_UPLOAD,
                'provider' => 'upload',
                'model' => null,
                'image_path' => $path,
                'image_url' => $url,
                'original_path' => $path,
                'original_filename' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
                'mime_type' => (string) $file->getMimeType(),
                'alt_text' => trim((string) $altText),
                'credit_cost' => 0,
                'status' => 'ready',
                'is_active' => true,
                'display_on_website' => $usage['display_on_website'],
                'display_as_featured_image' => $usage['display_as_featured_image'],
                'use_as_meta_image' => $usage['use_as_meta_image'],
                'use_as_social_image' => $usage['use_as_social_image'],
                'use_for_linkedin' => $usage['use_for_linkedin'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'file_size' => (int) $file->getSize(),
                'metadata' => [
                    'source' => ContentImage::SOURCE_UPLOAD,
                    'uploaded_at' => now()->toIso8601String(),
                    'usage' => $usage,
                ],
                'created_by' => (string) $user->id,
                'uploaded_by' => $user->id,
            ]);
        });
    }

    /**
     * @param array<string,bool> $usage
     * @return array{display_on_website:bool,display_as_featured_image:bool,use_as_meta_image:bool,use_as_social_image:bool,use_for_linkedin:bool}
     */
    private function normalizeUsage(array $usage, bool $allowEmpty = false): array
    {
        $normalized = [
            'display_on_website' => (bool) ($usage['display_on_website'] ?? false),
            'display_as_featured_image' => (bool) ($usage['display_as_featured_image'] ?? false),
            'use_as_meta_image' => (bool) ($usage['use_as_meta_image'] ?? false),
            'use_as_social_image' => (bool) ($usage['use_as_social_image'] ?? false),
            'use_for_linkedin' => (bool) ($usage['use_for_linkedin'] ?? false),
        ];

        if ($normalized['display_as_featured_image']) {
            $normalized['display_on_website'] = true;
        }

        if ($normalized['use_for_linkedin']) {
            $normalized['use_as_social_image'] = true;
        }

        if (! $allowEmpty && ! in_array(true, $normalized, true)) {
            throw new RuntimeException('Select at least one image usage.');
        }

        return $normalized;
    }

    private function assertUsableImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Uploaded image is invalid.');
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('Uploaded image may not be larger than 10 MB.');
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Uploaded image must be a JPG, PNG, or WebP file.');
        }

        $dimensions = $this->dimensions($file);
        if ($dimensions['width'] < 1 || $dimensions['height'] < 1) {
            throw new RuntimeException('Uploaded image dimensions could not be read.');
        }

        if ($dimensions['width'] > self::MAX_WIDTH || $dimensions['height'] > self::MAX_HEIGHT) {
            throw new RuntimeException('Uploaded image dimensions may not exceed 8000 x 8000 pixels.');
        }
    }

    /**
     * @return array{width:int,height:int}
     */
    private function dimensions(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());
        if (! is_array($info)) {
            throw new RuntimeException('Uploaded file is not a readable image.');
        }

        return [
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
        ];
    }

    /**
     * @param array<string,bool> $usage
     */
    private function typeForUsage(array $usage): string
    {
        if ($usage['display_as_featured_image'] || $usage['display_on_website']) {
            return 'featured';
        }

        if ($usage['use_as_meta_image']) {
            return 'og';
        }

        return 'social';
    }

    /**
     * @param array<string,bool> $usage
     */
    private function deactivateConflictingContentImages(string $contentId, array $usage, ?string $exceptImageId = null): void
    {
        foreach ([
            'display_on_website',
            'display_as_featured_image',
            'use_as_meta_image',
            'use_as_social_image',
            'use_for_linkedin',
        ] as $column) {
            if (! $usage[$column]) {
                continue;
            }

            ContentImage::query()
                ->where('content_id', $contentId)
                ->where($column, true)
                ->when($exceptImageId, fn ($query) => $query->whereKeyNot($exceptImageId))
                ->update(['is_active' => false]);
        }
    }

    /**
     * @param array<string,bool> $usage
     */
    private function deactivateConflictingCampaignImages(string $campaignId, array $usage, ?string $exceptImageId = null): void
    {
        foreach ([
            'display_on_website',
            'display_as_featured_image',
            'use_as_meta_image',
            'use_as_social_image',
            'use_for_linkedin',
        ] as $column) {
            if (! $usage[$column]) {
                continue;
            }

            ContentImage::query()
                ->where('campaign_id', $campaignId)
                ->whereNull('social_publication_id')
                ->where($column, true)
                ->when($exceptImageId, fn ($query) => $query->whereKeyNot($exceptImageId))
                ->update(['is_active' => false]);
        }
    }

    private function disk(): string
    {
        return (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images'));
    }
}
