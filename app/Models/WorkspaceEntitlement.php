<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceEntitlement extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'subscription_id',
        'plan_id',
        'feature_key',
        'value_type',
        'value_bool',
        'value_int',
        'value_string',
        'value_json',
        'source',
        'effective_at',
        'expires_at',
        'refreshed_at',
        'meta',
    ];

    protected $casts = [
        'value_bool' => 'boolean',
        'value_int' => 'integer',
        'value_json' => 'array',
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function typedValue(): mixed
    {
        return match ((string) $this->value_type) {
            'int' => $this->value_int,
            'string' => $this->value_string,
            'json' => $this->value_json,
            default => (bool) $this->value_bool,
        };
    }
}
