<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'content_destination_id',
        'started_at',
        'finished_at',
        'status',
        'pages_crawled',
        'issue_counts',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'pages_crawled' => 'integer',
        'issue_counts' => 'array',
        'meta' => 'array',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function pages()
    {
        return $this->hasMany(SeoAuditPage::class);
    }

    public function issues()
    {
        return $this->hasMany(SeoAuditIssue::class);
    }

    public function fixSuggestions()
    {
        return $this->hasMany(SeoAuditFixSuggestion::class);
    }
}
