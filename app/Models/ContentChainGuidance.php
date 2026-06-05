<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentChainGuidance extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'content_id',
        'is_source_enabled',
        'preferred_angle',
        'goal_type',
        'priority',
        'target_keyword',
        'target_audience',
        'target_intent',
        'explicit_topic',
        'editor_notes',
        'inline_link_mode',
        'allow_heading_links',
        'max_inline_links',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_source_enabled' => 'boolean',
        'allow_heading_links' => 'boolean',
        'max_inline_links' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
