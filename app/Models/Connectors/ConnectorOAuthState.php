<?php

namespace App\Models\Connectors;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorOAuthState extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'connector_oauth_states';

    protected $fillable = [
        'state_hash',
        'nonce_hash',
        'workspace_id',
        'user_id',
        'connector_provider_id',
        'connector_account_id',
        'provider_key',
        'redirect_uri',
        'scopes_json',
        'pkce_code_verifier',
        'pkce_code_challenge',
        'pkce_code_challenge_method',
        'context_json',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'scopes_json' => 'array',
        'pkce_code_verifier' => 'encrypted',
        'context_json' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ConnectorProvider::class, 'connector_provider_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }
}
