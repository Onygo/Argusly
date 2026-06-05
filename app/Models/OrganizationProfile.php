<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationProfile extends Model
{
    use HasFactory;

    public const SECTION_KEYS = [
        'brand_summary',
        'tone_of_voice',
        'audience_profiles',
        'offerings',
        'differentiators',
        'strategic_topics',
        'seo_topics',
        'visual_direction',
    ];

    protected $fillable = [
        'organization_id',
        'brand_summary',
        'tone_of_voice',
        'audience_profiles',
        'offerings',
        'differentiators',
        'strategic_topics',
        'seo_topics',
        'visual_direction',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'audience_profiles' => 'array',
        'offerings' => 'array',
        'differentiators' => 'array',
        'strategic_topics' => 'array',
        'seo_topics' => 'array',
        'visual_direction' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
