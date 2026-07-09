<?php

namespace App\Models\Connectors;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorScope extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPE_REQUIRED = 'required';
    public const TYPE_OPTIONAL = 'optional';
    public const TYPE_GRANTED = 'granted';

    protected $fillable = [
        'connector_account_id',
        'scope',
        'scope_type',
        'consent_status',
        'granted_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }
}
