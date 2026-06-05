<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgenticMarketingObjective extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'name',
        'goal',
        'locale',
        'audience',
        'target_market',
        'languages',
        'industry',
        'priority',
        'kpi_type',
        'monthly_credit_budget',
        'brand_entities',
        'competitors',
        'channels',
        'tone',
        'approval_mode',
        'status',
        'payload',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'monthly_credit_budget' => 'integer',
        'languages' => 'array',
        'brand_entities' => 'array',
        'competitors' => 'array',
        'channels' => 'array',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::created(function (AgenticMarketingObjective $objective): void {
            app(\App\Services\AgenticMarketing\AgenticMarketingAuditLogger::class)
                ->record($objective, 'objective.created', null, $objective->attributesToArray());
        });

        static::updated(function (AgenticMarketingObjective $objective): void {
            app(\App\Services\AgenticMarketing\AgenticMarketingAuditLogger::class)
                ->record($objective, 'objective.updated', $objective->getOriginal(), $objective->getChanges());
        });
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

    public function opportunities(): HasMany
    {
        return $this->hasMany(AgenticMarketingOpportunity::class, 'objective_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgenticMarketingAction::class, 'objective_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgenticMarketingRun::class, 'objective_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'agentic_marketing_objective_id');
    }
}
