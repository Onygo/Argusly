<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaignMetric extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'email_campaign_export_id',
        'sent',
        'delivered',
        'opens',
        'unique_opens',
        'clicks',
        'unique_clicks',
        'bounces',
        'unsubscribes',
        'conversions',
        'revenue',
        'raw',
        'measured_at',
    ];

    protected $casts = [
        'sent' => 'integer',
        'delivered' => 'integer',
        'opens' => 'integer',
        'unique_opens' => 'integer',
        'clicks' => 'integer',
        'unique_clicks' => 'integer',
        'bounces' => 'integer',
        'unsubscribes' => 'integer',
        'conversions' => 'integer',
        'revenue' => 'decimal:2',
        'raw' => 'array',
        'measured_at' => 'datetime',
    ];

    public function export(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignExport::class, 'email_campaign_export_id');
    }
}
