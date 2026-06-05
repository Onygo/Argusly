<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'default_internal_linking_enabled',
        'external_suggestions_enabled',
        'max_outbound_links_per_article',
        'max_cross_domain_links_per_month',
        'min_similarity_threshold',
        'min_audience_overlap_threshold',
    ];

    protected $casts = [
        'default_internal_linking_enabled' => 'boolean',
        'external_suggestions_enabled' => 'boolean',
        'max_outbound_links_per_article' => 'integer',
        'max_cross_domain_links_per_month' => 'integer',
        'min_similarity_threshold' => 'float',
        'min_audience_overlap_threshold' => 'float',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
