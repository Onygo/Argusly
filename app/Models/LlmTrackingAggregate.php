<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmTrackingAggregate extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'query_id',
        'period',
        'period_start',
        'provider',
        'model',
        'locale',
        'metrics',
    ];

    protected $casts = [
        'period_start' => 'date',
        'metrics' => 'array',
    ];

    public function trackingQuery()
    {
        return $this->belongsTo(LlmTrackingQuery::class, 'query_id');
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }
}
