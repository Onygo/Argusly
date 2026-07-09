<?php

namespace App\Models\Connectors;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorToken extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'connector_account_id',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'refreshed_at',
        'revoked_at',
        'rotation_metadata_json',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'rotation_metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }
}
