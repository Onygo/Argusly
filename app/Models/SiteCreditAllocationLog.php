<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCreditAllocationLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'from_client_site_id',
        'to_client_site_id',
        'action',
        'amount',
        'user_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }
}
