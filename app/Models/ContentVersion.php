<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a version in the content's hierarchical version tree.
 *
 * ## Purpose: Hierarchical Content Versioning (Preferred)
 *
 * ContentVersion provides a tree structure to track the complete evolution of content
 * from initial brief through multiple drafts and revisions. Each version has a parent,
 * creating a traceable lineage.
 *
 * Key characteristics:
 * - **Hierarchical**: parent_version_id creates a version tree
 * - **Typed**: type field classifies the version (brief, draft, revision, published_snapshot)
 * - **Source-tracked**: source field indicates origin (pl, wp, api)
 * - **Metadata-rich**: meta stores generation details (provider, model, tokens, credits)
 *
 * ## Version Types
 *
 * - `brief`: Initial content specification from Brief model
 * - `draft`: First working version after brief (from initial Draft)
 * - `revision`: Subsequent regenerations/rewrites (from subsequent Drafts)
 * - `published_snapshot`: Snapshot captured at publish time (future use)
 *
 * ## Version Sources
 *
 * - `pl`: Created within Argusly (generation, editing)
 * - `wp`: Imported from WordPress
 * - `api`: Created via external API
 *
 * ## Phase 1 Refactor: Preferred Versioning System
 *
 * ContentVersion is the preferred versioning system for new code. It coexists with
 * the legacy ContentRevision system during the transition:
 *
 * - ContentRevision: Numbered snapshots (R1, R2...) - legacy compatibility
 * - ContentVersion: Hierarchical tree with types - preferred for new code
 *
 * ## Pointers on Content
 *
 * - Content.current_version_id: Points to the active ContentVersion
 * - Content.current_revision_id: Points to the active ContentRevision (legacy)
 *
 * @see \App\Models\ContentRevision for legacy numbered snapshots
 * @see \App\Services\Content\ContentLifecycleService for version creation
 * @see \App\Models\Content::currentVersion() relationship
 */
class ContentVersion extends Model
{
    use HasFactory;
    use HasUuids;

    // Version type constants
    public const TYPE_BRIEF = 'brief';
    public const TYPE_DRAFT = 'draft';
    public const TYPE_REVISION = 'revision';
    public const TYPE_PUBLISHED_SNAPSHOT = 'published_snapshot';

    // Source constants
    public const SOURCE_ARGUSLY = 'pl';
    public const SOURCE_WORDPRESS = 'wp';
    public const SOURCE_API = 'api';

    protected $fillable = [
        'content_id',
        'type',
        'parent_version_id',
        'body',
        'meta',
        'source',
        'created_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_version_id');
    }

    // =========================================================================
    // Type Helpers
    // =========================================================================

    /**
     * Check if this is a brief version.
     */
    public function isBrief(): bool
    {
        return $this->type === self::TYPE_BRIEF;
    }

    /**
     * Check if this is a draft version (first working version after brief).
     */
    public function isDraft(): bool
    {
        return $this->type === self::TYPE_DRAFT;
    }

    /**
     * Check if this is a revision version (subsequent regeneration).
     */
    public function isRevision(): bool
    {
        return $this->type === self::TYPE_REVISION;
    }

    /**
     * Check if this is a published snapshot.
     */
    public function isPublishedSnapshot(): bool
    {
        return $this->type === self::TYPE_PUBLISHED_SNAPSHOT;
    }

    /**
     * Check if this version contains actual content (draft or revision).
     */
    public function hasContent(): bool
    {
        return in_array($this->type, [self::TYPE_DRAFT, self::TYPE_REVISION, self::TYPE_PUBLISHED_SNAPSHOT], true);
    }

    // =========================================================================
    // Source Helpers
    // =========================================================================

    /**
     * Check if this version was created in Argusly.
     */
    public function isFromArgusly(): bool
    {
        return $this->source === self::SOURCE_ARGUSLY || $this->source === null;
    }

    /**
     * Check if this version was imported from WordPress.
     */
    public function isFromWordPress(): bool
    {
        return $this->source === self::SOURCE_WORDPRESS;
    }

    /**
     * Check if this version was created via API.
     */
    public function isFromApi(): bool
    {
        return $this->source === self::SOURCE_API;
    }

    // =========================================================================
    // Lineage Helpers
    // =========================================================================

    /**
     * Check if this is the current active version for its content.
     */
    public function isActive(): bool
    {
        return (string) $this->content?->current_version_id === (string) $this->id;
    }

    /**
     * Get the root version (usually the brief) in this version's lineage.
     */
    public function getRootVersion(): self
    {
        $current = $this;
        while ($current->parent_version_id !== null) {
            $parent = $current->parent;
            if (! $parent) {
                break;
            }
            $current = $parent;
        }

        return $current;
    }

    /**
     * Get the depth of this version in the tree (0 for root).
     */
    public function getDepth(): int
    {
        $depth = 0;
        $current = $this;
        while ($current->parent_version_id !== null) {
            $parent = $current->parent;
            if (! $parent) {
                break;
            }
            $current = $parent;
            $depth++;
        }

        return $depth;
    }
}
