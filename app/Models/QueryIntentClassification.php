<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QueryIntentClassification extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'classifiable_type',
        'classifiable_id',
        'source_type',
        'source_key',
        'locale',
        'title',
        'query',
        'text_excerpt',
        'primary_intent',
        'secondary_intents',
        'funnel_stage',
        'buyer_role',
        'urgency',
        'business_impact',
        'intent_confidence',
        'urgency_score',
        'business_impact_score',
        'priority_score',
        'score_breakdown',
        'signals',
        'ai_enrichment',
        'normalized_payload',
        'payload_hash',
        'classified_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'secondary_intents' => 'array',
        'intent_confidence' => 'float',
        'urgency_score' => 'float',
        'business_impact_score' => 'float',
        'priority_score' => 'float',
        'score_breakdown' => 'array',
        'signals' => 'array',
        'ai_enrichment' => 'array',
        'normalized_payload' => 'array',
        'classified_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function classifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
