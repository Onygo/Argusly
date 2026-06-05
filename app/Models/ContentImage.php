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
        'content_id',
        'type',
        'prompt',
        'provider',
        'model',
        'image_path',
        'image_url',
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
        'error_message',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'credit_cost' => 'integer',
        'credit_status' => 'string',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
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
        $fallbackUrl = trim((string) ($this->image_url ?? ''));

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
                return $value;
            }

            if ($fallbackUrl !== '' && ! $disk->exists($value)) {
                continue;
            }

            return (string) Storage::disk($this->resolveImageDisk())->url($value);
        }

        return '';
    }

    private function resolveImageDisk(): string
    {
        return (string) config('publishlayer.images.disk', config('publishlayer.ai.images.storage_disk', 'public'));
    }

    private function normalizeWordPressUploadUrl(string $value): string
    {
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
        $base = trim((string) config('publishlayer.webhooks.connector_public_url', ''));
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
}
