<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkOpportunity extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'workspace_id',
        'source_content_id',
        'target_content_id',
        'anchor_text_suggestion',
        'context_snippet',
        'status',
        'relevance_score',
        'meta',
    ];

    protected $casts = [
        'relevance_score' => 'float',
        'meta' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sourceContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'source_content_id');
    }

    public function targetContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'target_content_id');
    }
}
