<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ContentImage extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'content_id',
        'campaign_id',
        'social_publication_id',
        'social_post_variant_id',
        'type',
        'source',
        'prompt',
        'provider',
        'model',
        'image_path',
        'image_url',
        'original_filename',
        'mime_type',
        'alt_text',
        'original_path',
        'medium_path',
        'thumbnail_path',
        'original_webp_path',
        'medium_webp_path',
        'thumbnail_webp_path',
        'credit_cost',
        'credit_wallet_id',
        'workspace_credit_wallet_id',
        'credit_status',
        'credit_ledger_entry_id',
        'workspace_credit_transaction_id',
        'credit_release_reason',
        'width',
        'height',
        'file_size',
        'status',
        'is_active',
        'display_on_website',
        'display_as_featured_image',
        'use_as_meta_image',
        'use_as_social_image',
        'use_for_linkedin',
        'error_message',
        'metadata',
        'created_by',
        'uploaded_by',
    ];

    protected $casts = [
        'credit_cost' => 'integer',
        'credit_status' => 'string',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'is_active' => 'boolean',
        'display_on_website' => 'boolean',
        'display_as_featured_image' => 'boolean',
        'use_as_meta_image' => 'boolean',
        'use_as_social_image' => 'boolean',
        'use_for_linkedin' => 'boolean',
        'metadata' => 'array',
    ];

    public const SOURCE_GENERATED = 'generated';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_STOCK = 'stock';

    public const USAGE_WEBSITE = 'website';

    public const USAGE_FEATURED = 'featured';

    public const USAGE_META = 'meta';

    public const USAGE_SOCIAL = 'social';

    public const USAGE_LINKEDIN = 'linkedin';

    public static function storageDirectory(): string
    {
        $directory = trim((string) config('argusly.images.path', 'content-images'), '/');

        return $directory !== '' ? $directory : 'content-images';
    }

    public static function storagePath(string $path): string
    {
        return static::storageDirectory().'/'.ltrim($path, '/');
    }

    public static function publicUrlForStorageValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '//')) {
            return static::publicUrlForStorageValue('https:'.$value);
        }

        $parts = parse_url($value);
        $path = (string) ($parts['path'] ?? '');

        if (($parts['scheme'] ?? null) !== null && ($parts['host'] ?? null) !== null) {
            $normalizedPath = static::normalizePublicContentImagePath($path);
            if ($normalizedPath === null) {
                return $value;
            }

            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';
            $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

            return $parts['scheme'].'://'.$parts['host'].$port.$normalizedPath.$query.$fragment;
        }

        $normalizedPath = static::normalizePublicContentImagePath($value);
        if ($normalizedPath === null) {
            return $value;
        }

        return asset(ltrim($normalizedPath, '/'));
    }

    public static function isPublicContentImageValue(string $value): bool
    {
        return static::normalizePublicContentImagePath($value) !== null;
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function socialPublication(): BelongsTo
    {
        return $this->belongsTo(SocialPublication::class);
    }

    public function socialPostVariant(): BelongsTo
    {
        return $this->belongsTo(SocialPostVariant::class);
    }

    public function workspaceCreditWallet(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditWallet::class, 'workspace_credit_wallet_id');
    }

    public function workspaceCreditTransaction(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'workspace_credit_transaction_id');
    }

    public function hasOutput(): bool
    {
        $directPaths = [
            $this->image_path,
            $this->image_url,
            $this->original_path,
            $this->medium_path,
            $this->thumbnail_path,
            $this->original_webp_path,
            $this->medium_webp_path,
            $this->thumbnail_webp_path,
        ];

        foreach ($directPaths as $path) {
            if (trim((string) $path) !== '') {
                return true;
            }
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return trim((string) (data_get($metadata, 'remote_url') ?? '')) !== ''
            || trim((string) (data_get($metadata, 'wp.attachment_id') ?? '')) !== ''
            || trim((string) (data_get($metadata, 'wp.media_id') ?? '')) !== '';
    }

    public function allowsUsage(string $usage): bool
    {
        return match ($usage) {
            self::USAGE_WEBSITE => (bool) $this->display_on_website || (bool) $this->display_as_featured_image,
            self::USAGE_FEATURED => (bool) $this->display_as_featured_image,
            self::USAGE_META => (bool) $this->use_as_meta_image,
            self::USAGE_LINKEDIN => (bool) $this->use_for_linkedin || (bool) $this->use_as_social_image,
            self::USAGE_SOCIAL => (bool) $this->use_as_social_image || (bool) $this->use_for_linkedin,
            default => false,
        };
    }

    public function bestUrlForUsage(string $usage = self::USAGE_WEBSITE): string
    {
        $candidates = match ($usage) {
            self::USAGE_WEBSITE, self::USAGE_FEATURED => [
                $this->medium_ui_url,
                $this->original_ui_url,
                $this->image_url,
            ],
            self::USAGE_META, self::USAGE_SOCIAL, self::USAGE_LINKEDIN => [
                $this->original_ui_url,
                $this->medium_ui_url,
                $this->image_url,
            ],
            default => [
                $this->original_ui_url,
                $this->medium_ui_url,
                $this->image_url,
            ],
        };

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function isFailedWithoutOutput(): bool
    {
        return in_array((string) $this->status, ['failed', 'canceled', 'expired'], true)
            && ! $this->hasOutput();
    }

    public function getThumbnailUiUrlAttribute(): string
    {
        return $this->resolveStorageUrl([
            $this->thumbnail_webp_path,
            $this->thumbnail_path,
            $this->medium_webp_path,
            $this->medium_path,
            $this->original_webp_path,
            $this->original_path,
            $this->image_path,
            $this->image_url,
        ]);
    }

    public function getMediumUiUrlAttribute(): string
    {
        return $this->resolveStorageUrl([
            $this->medium_webp_path,
            $this->medium_path,
            $this->original_webp_path,
            $this->original_path,
            $this->image_path,
            $this->image_url,
        ]);
    }

    public function getOriginalUiUrlAttribute(): string
    {
        return $this->resolveStorageUrl([
            $this->original_path,
            $this->image_path,
            $this->image_url,
        ]);
    }

    public function getPathForWordPressUpload(?ClientSite $site = null): string
    {
        $canUseWebp = $this->supportsWordPressWebp($site);

        if ($canUseWebp && filled($this->medium_webp_path)) {
            return (string) $this->medium_webp_path;
        }

        if (filled($this->medium_path)) {
            return (string) $this->medium_path;
        }

        if ($canUseWebp && filled($this->original_webp_path)) {
            return (string) $this->original_webp_path;
        }

        if (filled($this->original_path)) {
            return (string) $this->original_path;
        }

        return (string) ($this->image_path ?? '');
    }

    public function getWordPressUploadMimeType(?ClientSite $site = null): string
    {
        $path = $this->getPathForWordPressUpload($site);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    public function getWordPressUploadFilename(?ClientSite $site = null): string
    {
        $path = $this->getPathForWordPressUpload($site);

        return basename($path);
    }

    public function getWordPressUploadUrl(?ClientSite $site = null): string
    {
        $path = $this->getPathForWordPressUpload($site);
        if ($path === '') {
            return $this->normalizeWordPressUploadUrl((string) ($this->image_url ?? ''));
        }

        $disk = Storage::disk($this->resolveImageDisk());
        if (filled($this->image_url) && ! $disk->exists($path)) {
            return $this->normalizeWordPressUploadUrl((string) $this->image_url);
        }

        return $this->normalizeWordPressUploadUrl((string) Storage::disk($this->resolveImageDisk())->url($path));
    }

    public function usesWebpForUiThumbnail(): bool
    {
        return filled($this->thumbnail_webp_path);
    }

    private function supportsWordPressWebp(?ClientSite $site): bool
    {
        if (! $site) {
            return false;
        }

        $capabilities = is_array($site->capabilities) ? $site->capabilities : [];

        return (bool) data_get($capabilities, 'supports_webp', false)
            || (bool) data_get($capabilities, 'image_formats.webp', false);
    }

    /**
     * @param array<int,mixed> $candidates
     */
    private function resolveStorageUrl(array $candidates): string
    {
        $disk = Storage::disk($this->resolveImageDisk());
        $fallbackUrl = static::publicUrlForStorageValue((string) ($this->image_url ?? ''));

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
                return static::publicUrlForStorageValue($value);
            }

            $normalizedValue = static::publicUrlForStorageValue($value);
            $relativeValue = ltrim(str_replace('\\', '/', $value), '/');
            if (static::isPublicContentImageValue($value)
                && ($fallbackUrl === ''
                    || $fallbackUrl === $normalizedValue
                    || str_starts_with($relativeValue, 'storage/'.static::storageDirectory().'/')
                    || str_starts_with($relativeValue, 'public/'.static::storageDirectory().'/'))) {
                return $normalizedValue;
            }

            if ($fallbackUrl !== '' && ! $disk->exists($value)) {
                continue;
            }

            return static::publicUrlForStorageValue((string) Storage::disk($this->resolveImageDisk())->url($value));
        }

        return '';
    }

    private function resolveImageDisk(): string
    {
        return (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images'));
    }

    private function normalizeWordPressUploadUrl(string $value): string
    {
        $value = static::publicUrlForStorageValue($value);
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '//')) {
            return 'https:'.$value;
        }

        $baseUrl = $this->resolveWordPressPublicBaseUrl();
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== '' && $host !== '') {
            if (in_array($scheme, ['http', 'https'], true) && $this->isLocalOrPrivateHost($host) && $baseUrl !== '') {
                $path = (string) ($parts['path'] ?? '');
                $query = isset($parts['query']) ? '?'.$parts['query'] : '';
                $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

                return rtrim($baseUrl, '/').'/'.ltrim($path, '/').$query.$fragment;
            }

            return $value;
        }

        if ($baseUrl === '') {
            return $value;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($value, '/');
    }

    private function resolveWordPressPublicBaseUrl(): string
    {
        $base = trim((string) config('argusly.webhooks.connector_public_url', ''));
        if ($base === '') {
            $base = trim((string) config('app.url', ''));
        }

        if ($base === '') {
            return '';
        }

        if (! str_starts_with(strtolower($base), 'http://') && ! str_starts_with(strtolower($base), 'https://')) {
            $base = 'https://'.$base;
        }

        return rtrim($base, '/');
    }

    private function isLocalOrPrivateHost(string $host): bool
    {
        if ($host === '') {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')) {
            return true;
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! (bool) filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private static function normalizePublicContentImagePath(string $value): ?string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return null;
        }

        $directory = static::storageDirectory();
        $relative = ltrim($value, '/');

        foreach ([
            'public/'.$directory.'/',
            'storage/'.$directory.'/',
            $directory.'/',
        ] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return '/'.$directory.'/'.substr($relative, strlen($prefix));
            }
        }

        return null;
    }
}
