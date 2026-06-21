<?php

namespace App\Models;

use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FaqQuestion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'question',
        'answer',
        'language',
        'faq_type',
        'search_intent',
        'funnel_stage',
        'priority',
        'seo_score',
        'ai_visibility_score',
        'conversion_score',
        'is_global',
        'status',
        'internal_links',
        'recommended_cta',
        'source_context',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'faq_type' => FaqType::class,
        'search_intent' => FaqSearchIntent::class,
        'funnel_stage' => FaqFunnelStage::class,
        'status' => FaqStatus::class,
        'priority' => 'integer',
        'seo_score' => 'decimal:2',
        'ai_visibility_score' => 'decimal:2',
        'conversion_score' => 'decimal:2',
        'is_global' => 'boolean',
        'internal_links' => 'array',
        'source_context' => 'array',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(FaqPageAssignment::class, 'faq_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', FaqStatus::PUBLISHED->value);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('language', strtolower($locale));
    }
}
