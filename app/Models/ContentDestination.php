<?php

namespace App\Models;

use App\Enums\ContentDestinationEnvironment;
use App\Enums\ContentDestinationStatus;
use App\Enums\ContentDestinationType;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class ContentDestination extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'type',
        'status',
        'environment',
        'config',
        'default_language',
        'default_content_type',
        'export_format',
        'tracking_enabled',
        'seo_audit_enabled',
        'webhook_url',
        'webhook_secret',
        'created_by',
        'last_used_at',
    ];

    protected $casts = [
        'type' => ContentDestinationType::class,
        'status' => ContentDestinationStatus::class,
        'environment' => ContentDestinationEnvironment::class,
        'config' => 'array',
        'tracking_enabled' => 'boolean',
        'seo_audit_enabled' => 'boolean',
        'last_used_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $destination): void {
            $normalizedType = ContentDestinationType::normalize($destination->getAttribute('type'));

            if ($normalizedType !== null) {
                $destination->setAttribute('type', $normalizedType);
            }
        });
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class);
    }

    public function apiWebhooks()
    {
        return $this->hasMany(ApiWebhook::class);
    }

    public function briefs()
    {
        return $this->hasMany(Brief::class);
    }

    public function syncAttempts()
    {
        return $this->hasMany(ContentDestinationSyncAttempt::class);
    }

    public function latestSyncAttempt()
    {
        return $this->hasOne(ContentDestinationSyncAttempt::class)->latestOfMany('created_at');
    }

    public function drafts()
    {
        return $this->hasMany(Draft::class);
    }

    public function billingClientSiteId(): ?string
    {
        return trim((string) data_get($this->config, 'billing_client_site_id')) ?: null;
    }

    public function hasType(ContentDestinationType $type): bool
    {
        return ContentDestinationType::normalize($this->rawTypeValue()) === $type->value;
    }

    public function isLaravelConnector(): bool
    {
        return $this->hasType(ContentDestinationType::LARAVEL);
    }

    public function isWordPressDestination(): bool
    {
        return $this->hasType(ContentDestinationType::WORDPRESS);
    }

    public function resolvedType(): ?ContentDestinationType
    {
        return ContentDestinationType::fromNormalized($this->rawTypeValue());
    }

    public function typeValue(): ?string
    {
        return $this->resolvedType()?->value;
    }

    public function typeLabel(): string
    {
        return ContentDestinationType::label($this->rawTypeValue());
    }

    public function rawTypeValue(): ?string
    {
        $value = $this->getRawOriginal('type');

        if ($value === null) {
            $value = $this->getAttributes()['type'] ?? null;
        }

        if ($value instanceof ContentDestinationType) {
            return $value->value;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizedConfig(): array
    {
        $config = is_array($this->config) ? $this->config : [];

        if (! $this->isLaravelConnector()) {
            return $config;
        }

        $settings = $this->laravelConnectorSettings();
        unset($settings['api_key_encrypted']);
        $settings['has_api_key'] = $this->hasLaravelConnectorApiKey();
        $settings['sync_url'] = $this->laravelConnectorSyncUrl();
        $settings['health_url'] = $this->laravelConnectorHealthUrl();

        $config['laravel_connector'] = $settings;

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function laravelConnectorSettings(): array
    {
        $settings = data_get($this->config, 'laravel_connector');

        return is_array($settings) ? $settings : [];
    }

    public function laravelConnectorBaseUrl(): ?string
    {
        $value = SiteUrl::normalizeBaseUrl((string) data_get($this->laravelConnectorSettings(), 'base_url', ''));

        return $value !== '' ? $value : null;
    }

    public function laravelConnectorSyncPath(): string
    {
        $path = trim((string) data_get($this->laravelConnectorSettings(), 'sync_endpoint', '/argusly/sync'));
        if ($path === '') {
            return '/argusly/sync';
        }

        return '/'.ltrim($path, '/');
    }

    public function laravelConnectorSyncUrl(): ?string
    {
        $baseUrl = $this->laravelConnectorBaseUrl();

        return $baseUrl ? $baseUrl.$this->laravelConnectorSyncPath() : null;
    }

    public function laravelConnectorHealthPath(): string
    {
        $syncPath = $this->laravelConnectorSyncPath();

        if (str_ends_with($syncPath, '/sync')) {
            return substr($syncPath, 0, -4).'health';
        }

        return '/api/argusly/health';
    }

    public function laravelConnectorHealthUrl(): ?string
    {
        $baseUrl = $this->laravelConnectorBaseUrl();

        return $baseUrl ? $baseUrl.$this->laravelConnectorHealthPath() : null;
    }

    public function laravelConnectorSiteId(): ?string
    {
        $value = trim((string) data_get($this->laravelConnectorSettings(), 'site_id', ''));
        if ($value !== '') {
            return $value;
        }

        $billingSiteId = $this->billingClientSiteId();

        return $billingSiteId !== null && $billingSiteId !== '' ? $billingSiteId : null;
    }

    public function laravelConnectorMode(): string
    {
        $mode = trim((string) data_get($this->laravelConnectorSettings(), 'mode', 'hosted_views'));

        return in_array($mode, ['hosted_views', 'headless'], true) ? $mode : 'hosted_views';
    }

    public function laravelConnectorEnabled(): bool
    {
        if (! array_key_exists('enabled', $this->laravelConnectorSettings())) {
            return ($this->status?->value ?? $this->status) !== ContentDestinationStatus::DISABLED->value;
        }

        return (bool) data_get($this->laravelConnectorSettings(), 'enabled', true);
    }

    public function hasLaravelConnectorApiKey(): bool
    {
        return $this->laravelConnectorApiKey() !== null;
    }

    public function laravelConnectorApiKey(): ?string
    {
        $encrypted = trim((string) data_get($this->laravelConnectorSettings(), 'api_key_encrypted', ''));
        if ($encrypted === '') {
            return null;
        }

        try {
            $value = trim((string) Crypt::decryptString($encrypted));
        } catch (\Throwable) {
            return null;
        }

        return $value !== '' ? $value : null;
    }
}
