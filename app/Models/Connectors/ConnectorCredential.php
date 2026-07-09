<?php

namespace App\Models\Connectors;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorCredential extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const TYPE_ENV = 'env';
    public const TYPE_OAUTH_CLIENT = 'oauth_client';
    public const TYPE_API_KEY = 'api_key';
    public const TYPE_SERVICE_ACCOUNT = 'service_account';

    protected $fillable = [
        'workspace_id',
        'connector_provider_id',
        'credential_type',
        'name',
        'encrypted_config',
        'status',
        'metadata_json',
    ];

    protected $casts = [
        'encrypted_config' => 'encrypted:array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ConnectorProvider::class, 'connector_provider_id');
    }
}
