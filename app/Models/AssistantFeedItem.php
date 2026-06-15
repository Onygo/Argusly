<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AssistantFeedItem extends Model
{
    use HasUuids;

    public const STATE_FOUND = 'found';
    public const STATE_RECOMMEND = 'recommend';
    public const STATE_PREPARED = 'prepared';
    public const STATE_COMPLETED = 'completed';
    public const STATE_NEEDS_INPUT = 'needs_input';

    public const CATEGORY_OPPORTUNITY = 'opportunity';
    public const CATEGORY_RECOMMENDATION = 'recommendation';
    public const CATEGORY_LEARNING = 'learning';
    public const CATEGORY_CONTENT_ACTION = 'content_action';
    public const CATEGORY_EXECUTION = 'execution';
    public const CATEGORY_APPROVAL = 'approval';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_READ = 'read';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'user_id',
        'source_type',
        'source_id',
        'source_signature',
        'category',
        'assistant_state',
        'title',
        'summary',
        'i_found',
        'i_recommend',
        'i_prepared',
        'i_completed',
        'i_need_your_input',
        'priority_score',
        'priority_label',
        'status',
        'primary_cta_label',
        'primary_cta_url',
        'secondary_cta_label',
        'secondary_cta_url',
        'metadata',
        'visible_at',
        'read_at',
        'completed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'priority_score' => 'integer',
        'metadata' => 'array',
        'visible_at' => 'datetime',
        'read_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function assistantStates(): array
    {
        return [
            self::STATE_FOUND,
            self::STATE_RECOMMEND,
            self::STATE_PREPARED,
            self::STATE_COMPLETED,
            self::STATE_NEEDS_INPUT,
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->whereNull('visible_at')->orWhere('visible_at', '<=', now());
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function hasInputRequest(): bool
    {
        return trim((string) $this->i_need_your_input) !== '';
    }

    public function messageSections(): array
    {
        return [
            'I found' => $this->i_found,
            'I recommend' => $this->i_recommend,
            'I prepared' => $this->i_prepared,
            'I completed' => $this->i_completed,
            'I need your input' => $this->i_need_your_input,
        ];
    }
}
