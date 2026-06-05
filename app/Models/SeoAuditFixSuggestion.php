<?php

namespace App\Models;

use App\Enums\SeoAuditSuggestionState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoAuditFixSuggestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_FAILED = 'failed';

    public const STATE_SUGGESTED = SeoAuditSuggestionState::SUGGESTED->value;
    public const STATE_APPLIED_LOCAL = SeoAuditSuggestionState::APPLIED_LOCAL->value;
    public const STATE_SYNCED_EXTERNAL = SeoAuditSuggestionState::SYNCED_EXTERNAL->value;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'seo_audit_id',
        'seo_audit_page_id',
        'issue_code',
        'status',
        'suggestion_state',
        'input_snapshot',
        'suggestion',
        'token_usage',
        'credits_reserved',
        'credits_charged',
        'created_by',
        'applied_by',
    ];

    protected $casts = [
        'suggestion_state' => SeoAuditSuggestionState::class,
        'input_snapshot' => 'array',
        'suggestion' => 'array',
        'token_usage' => 'array',
        'credits_reserved' => 'integer',
        'credits_charged' => 'integer',
        'created_by' => 'integer',
        'applied_by' => 'integer',
    ];

    public function audit()
    {
        return $this->belongsTo(SeoAudit::class, 'seo_audit_id');
    }

    public function page()
    {
        return $this->belongsTo(SeoAuditPage::class, 'seo_audit_page_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function applyLog()
    {
        return $this->hasOne(SeoApplyLog::class, 'suggestion_id');
    }
}
