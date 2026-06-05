<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LlmSettingsAuditLog extends Model
{
    use HasUuids;

    protected $table = 'llm_settings_audit_logs';

    protected $fillable = [
        'actor_user_id',
        'scope_type',
        'scope_id',
        'action',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
