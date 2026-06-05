<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmTrackingQuery extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'llm_tracking_query_set_id',
        'name',
        'query_text',
        'query_variants',
        'target_brand',
        'target_domain',
        'brand_terms',
        'competitor_terms',
        'target_urls',
        'tags',
        'locale',
        'frequency',
        'priority',
        'is_active',
        'last_run_at',
    ];

    protected $casts = [
        'brand_terms' => 'array',
        'query_variants' => 'array',
        'competitor_terms' => 'array',
        'target_urls' => 'array',
        'tags' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function querySet()
    {
        return $this->belongsTo(LlmTrackingQuerySet::class, 'llm_tracking_query_set_id');
    }

    public function runs()
    {
        return $this->hasMany(LlmTrackingQueryRun::class)->orderByDesc('run_at');
    }

    public function latestRun()
    {
        return $this->hasOne(LlmTrackingQueryRun::class)->latestOfMany('run_at');
    }

    public function aggregates()
    {
        return $this->hasMany(LlmTrackingAggregate::class, 'query_id');
    }

    public function authorityEntityCandidates()
    {
        return $this->hasMany(LlmAuthorityEntityCandidate::class, 'llm_tracking_query_id');
    }
}
