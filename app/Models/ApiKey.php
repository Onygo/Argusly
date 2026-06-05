<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    use HasFactory;
    use HasUuids;

    public const MANAGED_VIA_WORKSPACE = 'workspace';
    public const MANAGED_VIA_SITE_INTEGRATION = 'site_integration';
    public const MANAGED_VIA_LEGACY_ORGANIZATION = 'legacy_organization';

    public const ORIGIN_TYPE_SITE_TOKEN = 'site_token';
    public const ORIGIN_TYPE_ORGANIZATION = 'organization';

    protected $fillable = [
        'workspace_id',
        'content_destination_id',
        'origin_type',
        'origin_id',
        'origin_label',
        'is_legacy_import',
        'managed_via',
        'notes',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'is_legacy_import' => 'boolean',
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'key_hash',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requestLogs()
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function operations()
    {
        return $this->hasMany(AsyncOperationRun::class);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = is_array($this->scopes) ? $this->scopes : [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
