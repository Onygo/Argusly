<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a numbered revision snapshot of content HTML.
 *
 * ## Purpose: Historical Draft Snapshots
 *
 * ContentRevision stores numbered snapshots (R1, R2, R3...) of finalized content HTML
 * tied to specific Draft records. Each time a draft is approved, a new revision is created.
 *
 * Key characteristics:
 * - **Numbered**: Uses revision_number (1, 2, 3...) and label (R1, R2, R3...)
 * - **Draft-linked**: Each revision references the Draft that created it
 * - **Single active**: Only one revision is_active at a time per Content
 * - **HTML storage**: Stores the rendered content_html snapshot
 *
 * ## Phase 1 Refactor: Transition to ContentVersion
 *
 * ContentRevision is the legacy versioning system. A new ContentVersion model provides
 * hierarchical versioning with parent-child lineage. Both systems currently run in parallel:
 *
 * - ContentRevision: Numbered snapshots for historical compatibility
 * - ContentVersion: Hierarchical tree for richer version tracking (preferred for new code)
 *
 * The ContentLifecycleService::ensureRevisionFromDraft() creates BOTH:
 * - A ContentRevision (legacy numbered snapshot)
 * - A ContentVersion (new hierarchical version)
 *
 * ## Usage Guidelines
 *
 * - **Legacy reads**: ContentFeedback references ContentRevision
 * - **New reads**: Prefer ContentVersion with type filtering
 * - **Writes**: Use ContentLifecycleService which handles both systems
 *
 * @see \App\Models\ContentVersion for hierarchical versioning (preferred)
 * @see \App\Services\Content\ContentLifecycleService for creation logic
 * @see \App\Models\ContentFeedback for revision feedback (uses this model)
 *
 * TODO: Phase 2 - Evaluate consolidation with ContentVersion
 */
class ContentRevision extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'content_id',
        'draft_id',
        'revision_number',
        'label',
        'content_html',
        'meta',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'is_active' => 'boolean',
        'revision_number' => 'integer',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function draft()
    {
        return $this->belongsTo(Draft::class);
    }

    /**
     * Check if this is the active revision for its content.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Get the revision label (e.g., "R1", "R2").
     */
    public function getLabel(): string
    {
        return $this->label ?? 'R' . $this->revision_number;
    }
}
