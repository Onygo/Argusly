<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_article_id',
        'target_article_id',
        'source_workspace_id',
        'target_workspace_id',
        'source_client_site_id',
        'target_client_site_id',
        'similarity_score',
        'shared_entities',
        'intent_match_score',
        'audience_overlap_score',
        'suggested_anchor_variants',
        'suggested_placement',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'applied_at',
    ];

    protected $casts = [
        'shared_entities' => 'array',
        'suggested_anchor_variants' => 'array',
        'similarity_score' => 'float',
        'intent_match_score' => 'float',
        'audience_overlap_score' => 'float',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function sourceArticle()
    {
        return $this->belongsTo(Draft::class, 'source_article_id');
    }

    public function targetArticle()
    {
        return $this->belongsTo(Draft::class, 'target_article_id');
    }

    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function sourceWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'source_workspace_id');
    }

    public function targetWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'target_workspace_id');
    }
}
