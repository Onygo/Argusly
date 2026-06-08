<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoAuditPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'seo_audit_id',
        'url',
        'status_code',
        'title',
        'meta_description',
        'canonical_url',
        'robots_meta',
        'h1',
        'word_count',
        'internal_links_count',
        'broken_links_count',
        'page_type',
        'argusly_content_id',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'word_count' => 'integer',
        'internal_links_count' => 'integer',
        'broken_links_count' => 'integer',
    ];

    public const PAGE_TYPE_ARGUSLY_ARTICLE = 'argusly_article';
    public const PAGE_TYPE_SITE_PAGE = 'site_page';
    public const PAGE_TYPE_SYSTEM_PAGE = 'system_page';

    public function audit()
    {
        return $this->belongsTo(SeoAudit::class, 'seo_audit_id');
    }

    public function issues()
    {
        return $this->hasMany(SeoAuditIssue::class);
    }

    public function arguslyArticle()
    {
        return $this->belongsTo(Content::class, 'argusly_content_id');
    }

    public function fixSuggestions()
    {
        return $this->hasMany(SeoAuditFixSuggestion::class, 'seo_audit_page_id');
    }
}
