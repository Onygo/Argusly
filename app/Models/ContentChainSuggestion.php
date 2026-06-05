<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ContentChainSuggestion extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    public const KIND_GROWTH = 'growth';
    public const KIND_INLINE_LINK = 'inline_link';
    public const KIND_FOOTER_LINK = 'footer_link';

    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_AUTO_APPLIED = 'auto_applied';
    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'workspace_id',
        'source_content_id',
        'target_content_id',
        'content_cluster_id',
        'fingerprint',
        'suggestion_kind',
        'suggestion_type',
        'status',
        'title',
        'goal_type',
        'anchor_text',
        'placement_type',
        'placement_label',
        'rationale',
        'score',
        'confidence_score',
        'score_breakdown',
        'source_snapshot',
        'placement_meta',
        'meta',
        'generated_content_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'applied_at',
    ];

    protected $casts = [
        'score' => 'float',
        'confidence_score' => 'float',
        'score_breakdown' => 'array',
        'source_snapshot' => 'array',
        'placement_meta' => 'array',
        'meta' => 'array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function setTitleAttribute(mixed $value): void
    {
        $result = TitleSanitizer::normalizeWithMetadata($value, fallback: 'Chained content suggestion');
        $this->attributes['title'] = $result['title'];

        if ($result['was_shortened']) {
            Log::notice('content_chain.suggestion_title_shortened', [
                'suggestion_id' => $this->getKey(),
                'workspace_id' => (string) ($this->workspace_id ?? ''),
                'source_content_id' => (string) ($this->source_content_id ?? ''),
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'max_length' => $result['max_length'],
            ]);
        }
    }

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

    public function contentCluster(): BelongsTo
    {
        return $this->belongsTo(ContentCluster::class, 'content_cluster_id');
    }

    public function generatedContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'generated_content_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
