<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SiteToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_site_id',
        'workspace_id',
        'name',
        'token_hash',
        'token_encrypted',
        'key_prefix',
        'scopes',
        'abilities',
        'revoked',
        'revoked_at',
        'last_used_at',
        'last_ip',
    ];

    protected $casts = [
        'scopes' => 'array',
        'abilities' => 'array',
        'revoked' => 'boolean',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = is_array($this->abilities) && count($this->abilities) > 0
            ? $this->abilities
            : ($this->scopes ?: []);

        return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }
}
