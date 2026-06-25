<?php

namespace App\Models;

use App\Enums\EmailMarketingExportStatus;
use App\Enums\EmailMarketingProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailCampaignExport extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'campaign_id',
        'campaign_content_id',
        'email_marketing_connection_id',
        'provider',
        'status',
        'remote_campaign_id',
        'remote_template_id',
        'remote_url',
        'idempotency_key',
        'payload',
        'provider_response',
        'error_message',
        'exported_at',
        'last_synced_at',
    ];

    protected $casts = [
        'provider' => EmailMarketingProvider::class,
        'status' => EmailMarketingExportStatus::class,
        'payload' => 'array',
        'provider_response' => 'array',
        'exported_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function campaignContent(): BelongsTo
    {
        return $this->belongsTo(CampaignContent::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EmailMarketingConnection::class, 'email_marketing_connection_id');
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(EmailCampaignMetric::class);
    }
}
