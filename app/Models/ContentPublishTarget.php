<?php

namespace App\Models;

use App\Enums\SupportedLanguage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a content publishing target (destination + language combination).
 *
 * ## Phase 1 Refactor Notes
 *
 * ### Remote ID Storage - ContentPublication is Now Canonical
 *
 * The wp_post_id field on this model is transitional and will be deprecated.
 * Use ContentPublication.remote_id as the canonical source for remote identifiers.
 *
 * This model remains useful for:
 * - Language-specific targeting (WPML, Polylang integration)
 * - SEO sync tracking per destination
 * - Featured media tracking
 *
 * @deprecated wp_post_id field - Use ContentPublication.remote_id instead
 * @deprecated target_identifier field - Overlaps with ContentPublication.remote_id
 *
 * @see \App\Models\ContentPublication for canonical remote ID storage
 * @see \App\Models\Content::getCanonicalRemoteId() for backwards-compatible resolution
 *
 * TODO: Phase 2 - Audit wp_post_id usage and migrate to ContentPublication
 * TODO: Phase 3 - Consider merging with ContentPublication or repurposing
 */
class ContentPublishTarget extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'content_publish_targets';

    protected $fillable = [
        'content_id',
        'client_site_id',
        'content_destination_id',
        'target_type',
        'target_identifier',
        'language',
        'wp_language_plugin',
        'wp_language_term_id',
        'wp_post_id',
        'wp_featured_media_id',
        'remote_permalink',
        'remote_edit_link',
        'external_key',
        'sync_status',
        'seo_sync_status',
        'seo_synced_at',
        'seo_sync_mode',
        'seo_sync_error',
        'seo_synced_fields',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'seo_synced_fields' => 'array',
        'last_synced_at' => 'datetime',
        'seo_synced_at' => 'datetime',
        'language' => SupportedLanguage::class,
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function syncAttempts()
    {
        return $this->hasMany(ContentDestinationSyncAttempt::class);
    }

    public function scopeForLanguage($query, SupportedLanguage $language)
    {
        return $query->where('language', $language->value);
    }

    public function hasWordPressPost(): bool
    {
        return $this->wp_post_id !== null;
    }

    public function usesLanguagePlugin(): bool
    {
        return $this->wp_language_plugin !== null;
    }

    public function getWordPressLanguagePluginLabel(): ?string
    {
        return match ($this->wp_language_plugin) {
            'polylang' => 'Polylang',
            'wpml' => 'WPML',
            default => null,
        };
    }

    // =========================================================================
    // Remote ID Resolution (Phase 1 Refactor - Transitional Helpers)
    // =========================================================================

    /**
     * Get the canonical remote ID from ContentPublication.
     *
     * This method delegates to ContentPublication as the single source of truth,
     * falling back to local wp_post_id for backwards compatibility.
     *
     * @return string|null
     */
    public function getCanonicalRemoteId(): ?string
    {
        // Try ContentPublication first (canonical source)
        $publication = ContentPublication::query()
            ->where('content_id', $this->content_id)
            ->when($this->content_destination_id, fn ($q) => $q->where('destination_id', $this->content_destination_id))
            ->when(! $this->content_destination_id && $this->client_site_id, fn ($q) => $q->where('client_site_id', $this->client_site_id))
            ->whereNotNull('remote_id')
            ->first();

        if ($publication) {
            return $publication->remote_id;
        }

        // Legacy fallback: local wp_post_id
        // TODO: Phase 2 - Remove this fallback
        return $this->getLegacyWpPostId();
    }

    /**
     * @deprecated Use getCanonicalRemoteId() instead.
     *
     * Legacy accessor for wp_post_id with fallback to target_identifier and meta.
     */
    public function getLegacyWpPostId(): ?string
    {
        if (trim((string) $this->wp_post_id) !== '') {
            return $this->wp_post_id;
        }

        if (trim((string) $this->target_identifier) !== '') {
            return $this->target_identifier;
        }

        $meta = is_array($this->meta) ? $this->meta : [];

        return trim((string) ($meta['wp_post_id'] ?? '')) !== ''
            ? $meta['wp_post_id']
            : null;
    }

    /**
     * Get the associated ContentPublication if one exists.
     *
     * @return ContentPublication|null
     */
    public function getPublication(): ?ContentPublication
    {
        return ContentPublication::query()
            ->where('content_id', $this->content_id)
            ->when($this->content_destination_id, fn ($q) => $q->where('destination_id', $this->content_destination_id))
            ->when(! $this->content_destination_id && $this->client_site_id, fn ($q) => $q->where('client_site_id', $this->client_site_id))
            ->first();
    }
}
