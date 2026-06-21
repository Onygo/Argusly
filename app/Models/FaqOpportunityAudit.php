<?php

namespace App\Models;

use App\Enums\FaqWorkflowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FaqOpportunityAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'page_type',
        'page_slug',
        'locale',
        'page_title',
        'sector',
        'solution_type',
        'status',
        'faq_coverage_score',
        'faq_opportunity_score',
        'ai_visibility_impact_score',
        'seo_impact_score',
        'conversion_impact_score',
        'score_rationale',
        'missing_questions',
        'generated_faqs',
        'suggested_internal_links',
        'suggested_ctas',
        'error_message',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'faq_coverage_score' => 'decimal:2',
        'faq_opportunity_score' => 'decimal:2',
        'ai_visibility_impact_score' => 'decimal:2',
        'seo_impact_score' => 'decimal:2',
        'conversion_impact_score' => 'decimal:2',
        'score_rationale' => 'array',
        'missing_questions' => 'array',
        'generated_faqs' => 'array',
        'suggested_internal_links' => 'array',
        'suggested_ctas' => 'array',
        'status' => FaqWorkflowStatus::class,
        'completed_at' => 'datetime',
    ];
}
