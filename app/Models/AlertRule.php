<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AlertRule extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const TRIGGER_NEW_BRAND_PAGE = 'new_page_mentioning_brand';
    public const TRIGGER_NEGATIVE_SENTIMENT = 'negative_sentiment';
    public const TRIGGER_COMPETITOR_CAMPAIGN_PAGE = 'competitor_campaign_page_discovered';
    public const TRIGGER_HIGH_PR_VALUE_PAGE = 'high_pr_value_page_discovered';
    public const TRIGGER_SERP_TOP_10_GAIN = 'serp_top_10_gain';
    public const TRIGGER_SERP_TOP_10_LOSS = 'serp_top_10_loss';
    public const TRIGGER_SERP_COMPETITOR_TOP_10_GAIN = 'serp_competitor_top_10_gain';
    public const TRIGGER_SERP_FEATURED_SNIPPET_GAIN = 'serp_featured_snippet_gain';
    public const TRIGGER_SERP_FEATURED_SNIPPET_LOSS = 'serp_featured_snippet_loss';
    public const TRIGGER_GEO_CITATION_GAIN = 'geo_citation_gain';
    public const TRIGGER_GEO_CITATION_LOSS = 'geo_citation_loss';
    public const TRIGGER_GEO_COMPETITOR_CITATION_GAIN = 'geo_competitor_citation_gain';
    public const TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT = 'geo_competitor_displaced_client';
    public const TRIGGER_CAMPAIGN_PICKUP = 'campaign_pickup_detected';
    public const TRIGGER_PR_VALUE_SPIKE = 'pr_value_spike';
    public const TRIGGER_HIGH_RISK_NEGATIVE_PAGE = 'high_risk_negative_page';
    public const TRIGGER_COMPETITOR_PRESSURE_SPIKE = 'competitor_pressure_spike';
    public const TRIGGER_HIGH_OPPORTUNITY_PAGE = 'high_opportunity_page';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'name',
        'trigger',
        'conditions_json',
        'cooldown_minutes',
        'severity',
        'is_active',
        'last_evaluated_at',
        'last_fired_at',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'conditions_json' => 'array',
        'cooldown_minutes' => 'integer',
        'is_active' => 'boolean',
        'last_evaluated_at' => 'datetime',
        'last_fired_at' => 'datetime',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public static function triggers(): array
    {
        return [
            self::TRIGGER_NEW_BRAND_PAGE,
            self::TRIGGER_NEGATIVE_SENTIMENT,
            self::TRIGGER_COMPETITOR_CAMPAIGN_PAGE,
            self::TRIGGER_HIGH_PR_VALUE_PAGE,
            self::TRIGGER_SERP_TOP_10_GAIN,
            self::TRIGGER_SERP_TOP_10_LOSS,
            self::TRIGGER_SERP_COMPETITOR_TOP_10_GAIN,
            self::TRIGGER_SERP_FEATURED_SNIPPET_GAIN,
            self::TRIGGER_SERP_FEATURED_SNIPPET_LOSS,
            self::TRIGGER_GEO_CITATION_GAIN,
            self::TRIGGER_GEO_CITATION_LOSS,
            self::TRIGGER_GEO_COMPETITOR_CITATION_GAIN,
            self::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT,
            self::TRIGGER_CAMPAIGN_PICKUP,
            self::TRIGGER_PR_VALUE_SPIKE,
            self::TRIGGER_HIGH_RISK_NEGATIVE_PAGE,
            self::TRIGGER_COMPETITOR_PRESSURE_SPIKE,
            self::TRIGGER_HIGH_OPPORTUNITY_PAGE,
        ];
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(PageAlert::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
