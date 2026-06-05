<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoApplyLog extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'suggestion_id',
        'seo_audit_id',
        'seo_audit_page_id',
        'issue_code',
        'content_id',
        'draft_id',
        'applied_by',
        'apply_target',
        'changed_fields',
        'old_values',
        'new_values',
        'apply_status',
        'applied_at',
    ];

    protected $casts = [
        'changed_fields' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
        'applied_by' => 'integer',
        'applied_at' => 'datetime',
    ];

    public function suggestion()
    {
        return $this->belongsTo(SeoAuditFixSuggestion::class, 'suggestion_id');
    }

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function draft()
    {
        return $this->belongsTo(Draft::class);
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
