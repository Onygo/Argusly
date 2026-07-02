<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class OpportunityExecutionPlan extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const TITLE_MAX_LENGTH = 220;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'opportunity_id',
        'status',
        'title',
        'summary',
        'objective',
        'recommended_channel',
        'recommended_format',
        'priority_score',
        'estimated_effort',
        'expected_impact',
        'planned_steps',
        'source_evidence',
        'metadata',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'priority_score' => 'float',
        'estimated_effort' => 'float',
        'expected_impact' => 'float',
        'planned_steps' => 'array',
        'source_evidence' => 'array',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function setTitleAttribute(mixed $value): void
    {
        $result = TitleSanitizer::normalizeWithMetadata($value, self::TITLE_MAX_LENGTH, 'Opportunity execution plan');
        $this->attributes['title'] = $result['title'];

        if ($result['was_shortened']) {
            Log::notice('opportunity_execution_plan.title_shortened', [
                'plan_id' => $this->getKey(),
                'opportunity_id' => (string) ($this->opportunity_id ?? ''),
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'max_length' => $result['max_length'],
            ]);
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function markReviewing(): self
    {
        return $this->transitionTo(self::STATUS_REVIEWING);
    }

    public function approve(User $user): self
    {
        return $this->transitionTo(self::STATUS_APPROVED, [
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function markPlanned(): self
    {
        return $this->transitionTo(self::STATUS_PLANNED);
    }

    public function archive(): self
    {
        return $this->transitionTo(self::STATUS_ARCHIVED);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function transitionTo(string $status, array $attributes = []): self
    {
        $this->forceFill(array_merge(['status' => $status], $attributes))->save();

        return $this->refresh();
    }
}
