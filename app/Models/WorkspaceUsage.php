<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ClientSite;

class WorkspaceUsage extends Model
{
    protected $table = 'workspace_usage';

    protected $fillable = [
        'workspace_id',
        'site_id',
        'year_month',
        'period_ym',
        'briefs_count',
        'drafts_count',
        'articles_generated',
        'llm_queries_run',
        'audit_pages_crawled',
    ];

    protected $casts = [
        'site_id' => 'string',
        'briefs_count' => 'integer',
        'drafts_count' => 'integer',
        'articles_generated' => 'integer',
        'llm_queries_run' => 'integer',
        'audit_pages_crawled' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }
}
