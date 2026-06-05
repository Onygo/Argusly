<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyIntelligenceProfile extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const EMBEDDING_NOT_READY = 'not_ready';
    public const EMBEDDING_READY = 'ready';
    public const EMBEDDING_QUEUED = 'queued';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'company_profile_id',
        'brand_voice_id',
        'brand_key',
        'company_name',
        'company_description',
        'market_category',
        'positioning',
        'uvp',
        'products_services',
        'pricing_model',
        'regions',
        'locales',
        'icps',
        'personas',
        'buyer_roles',
        'pain_points',
        'objections',
        'buying_triggers',
        'funnel_stages',
        'tone_of_voice',
        'banned_phrases',
        'messaging_rules',
        'brand_differentiators',
        'proof_points',
        'primary_topics',
        'authority_areas',
        'target_entities',
        'strategic_keywords',
        'query_intents',
        'direct_competitors',
        'indirect_competitors',
        'aspirational_competitors',
        'normalized_payload',
        'normalized_payload_hash',
        'completeness_score',
        'completeness_breakdown',
        'embedding_status',
        'embedding_payload_hash',
        'embedding_vector',
        'source_type',
        'is_default',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'products_services' => 'array',
        'regions' => 'array',
        'locales' => 'array',
        'icps' => 'array',
        'personas' => 'array',
        'buyer_roles' => 'array',
        'pain_points' => 'array',
        'objections' => 'array',
        'buying_triggers' => 'array',
        'funnel_stages' => 'array',
        'banned_phrases' => 'array',
        'messaging_rules' => 'array',
        'brand_differentiators' => 'array',
        'proof_points' => 'array',
        'primary_topics' => 'array',
        'authority_areas' => 'array',
        'target_entities' => 'array',
        'strategic_keywords' => 'array',
        'query_intents' => 'array',
        'direct_competitors' => 'array',
        'indirect_competitors' => 'array',
        'aspirational_competitors' => 'array',
        'normalized_payload' => 'array',
        'completeness_score' => 'integer',
        'completeness_breakdown' => 'array',
        'is_default' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function companyProfile(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isEmbeddingReady(): bool
    {
        return $this->embedding_status === self::EMBEDDING_READY
            && $this->embedding_payload_hash === $this->normalized_payload_hash;
    }
}
