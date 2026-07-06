<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LlmTrackingQueryRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'llm_tracking_query_id',
        'run_at',
        'provider',
        'model',
        'prompt_variant_key',
        'prompt_variant_text',
        'prompt_variant_intent',
        'provider_model_key',
        'status',
        'raw_response',
        'parsed_payload',
        'answer_text',
        'normalized_response',
        'answer_json',
        'brand_hits',
        'competitor_hits',
        'detected_brands',
        'detected_competitors',
        'authority_entities',
        'entity_presence',
        'url_hits',
        'citation_ranking',
        'sources',
        'detected_domains',
        'first_mention_index',
        'first_mention_block',
        'first_mention_context',
        'share_of_voice_snapshot',
        'suggestions',
        'cached_key',
        'is_cached',
        'brand_mentioned',
        'urls_cited',
        'competitors_mentioned',
        'presence_score',
        'position_score',
        'citation_score',
        'context_score',
        'context_label',
        'sentiment_score',
        'sentiment_label',
        'competitive_score',
        'competitor_share_score',
        'owned_visibility_score',
        'earned_visibility_score',
        'competitor_pressure_score',
        'citation_diversity_score',
        'model_confidence_score',
        'real_world_gap_score',
        'ai_visibility_score',
        'visibility_breakdown',
        'error_message',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'parsed_payload' => 'array',
        'answer_json' => 'array',
        'brand_hits' => 'array',
        'competitor_hits' => 'array',
        'detected_brands' => 'array',
        'detected_competitors' => 'array',
        'authority_entities' => 'array',
        'entity_presence' => 'array',
        'url_hits' => 'array',
        'citation_ranking' => 'array',
        'sources' => 'array',
        'detected_domains' => 'array',
        'share_of_voice_snapshot' => 'array',
        'suggestions' => 'array',
        'is_cached' => 'boolean',
        'brand_mentioned' => 'boolean',
        'urls_cited' => 'boolean',
        'competitors_mentioned' => 'boolean',
        'presence_score' => 'float',
        'position_score' => 'float',
        'citation_score' => 'float',
        'context_score' => 'float',
        'sentiment_score' => 'float',
        'competitive_score' => 'float',
        'competitor_share_score' => 'float',
        'owned_visibility_score' => 'float',
        'earned_visibility_score' => 'float',
        'competitor_pressure_score' => 'float',
        'citation_diversity_score' => 'float',
        'model_confidence_score' => 'float',
        'real_world_gap_score' => 'float',
        'ai_visibility_score' => 'float',
        'visibility_breakdown' => 'array',
    ];

    public function trackingQuery()
    {
        return $this->belongsTo(LlmTrackingQuery::class, 'llm_tracking_query_id');
    }

    public function geoObservations(): HasMany
    {
        return $this->hasMany(PageGeoObservation::class, 'llm_tracking_query_run_id');
    }
}
