<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoAuditIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'seo_audit_id',
        'seo_audit_page_id',
        'severity',
        'code',
        'title',
        'description',
        'recommendation',
        'context_json',
    ];

    protected $casts = [
        'context_json' => 'array',
    ];

    public function audit()
    {
        return $this->belongsTo(SeoAudit::class, 'seo_audit_id');
    }

    public function page()
    {
        return $this->belongsTo(SeoAuditPage::class, 'seo_audit_page_id');
    }
}
