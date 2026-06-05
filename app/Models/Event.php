<?php
// app/Models/Event.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Event extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_site_id',
        'type',
        'occurred_at',
        'data',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'data' => 'array',
    ];

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }
}
