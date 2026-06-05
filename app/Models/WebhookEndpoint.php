<?php
// app/Models/WebhookEndpoint.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WebhookEndpoint extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_site_id',
        'event_type',
        'url',
        'signing_method',
        'secret',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function isHmac(): bool
    {
        return $this->signing_method === 'hmac_sha256';
    }
}
