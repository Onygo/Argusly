<?php

namespace App\Models\Connectors;

use App\Models\ClientSite;
use App\Models\Connectors\Normalized\NormalizedAd;
use App\Models\Connectors\Normalized\NormalizedAdGroup;
use App\Models\Connectors\Normalized\NormalizedCampaign;
use App\Models\Connectors\Normalized\NormalizedCrmActivity;
use App\Models\Connectors\Normalized\NormalizedCrmCompany;
use App\Models\Connectors\Normalized\NormalizedCrmContact;
use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Connectors\Normalized\NormalizedMarketingAccount;
use App\Models\MarketingObservation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorAccount extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'connector_provider_id',
        'provider_key',
        'account_name',
        'external_account_id',
        'status',
        'connected_at',
        'disconnected_at',
        'last_synced_at',
        'sync_frequency',
        'next_sync_at',
        'health_status',
        'health_severity',
        'latest_health_event_id',
        'health_checked_at',
        'last_api_call_at',
        'last_error',
        'rate_limit_json',
        'health_score',
        'metadata_json',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'next_sync_at' => 'datetime',
        'health_checked_at' => 'datetime',
        'last_api_call_at' => 'datetime',
        'rate_limit_json' => 'array',
        'health_score' => 'integer',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ConnectorProvider::class, 'connector_provider_id');
    }

    public function token(): HasOne
    {
        return $this->hasOne(ConnectorToken::class)->latestOfMany();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ConnectorToken::class);
    }

    public function oauthStates(): HasMany
    {
        return $this->hasMany(ConnectorOAuthState::class);
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(ConnectorScope::class);
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(ConnectorDataset::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(ConnectorSyncRun::class);
    }

    public function healthEvents(): HasMany
    {
        return $this->hasMany(ConnectorHealthEvent::class);
    }

    public function marketingObservations(): HasMany
    {
        return $this->hasMany(MarketingObservation::class, 'connector_account_id');
    }

    public function rawRecords(): HasMany
    {
        return $this->hasMany(ConnectorRawRecord::class, 'connector_account_id');
    }

    public function quotaBudgets(): HasMany
    {
        return $this->hasMany(ConnectorQuotaBudget::class, 'connector_account_id');
    }

    public function backfillRanges(): HasMany
    {
        return $this->hasMany(ConnectorBackfillRange::class, 'connector_account_id');
    }

    public function asyncReportJobs(): HasMany
    {
        return $this->hasMany(ConnectorAsyncReportJob::class, 'connector_account_id');
    }

    public function fieldMappingPreparations(): HasMany
    {
        return $this->hasMany(ConnectorFieldMappingPreparation::class, 'connector_account_id');
    }

    public function webhookRegistration(): HasOne
    {
        return $this->hasOne(ConnectorWebhookRegistration::class, 'connector_account_id');
    }

    public function normalizationRuns(): HasMany
    {
        return $this->hasMany(NormalizationRun::class, 'connector_account_id');
    }

    public function normalizedMarketingAccounts(): HasMany
    {
        return $this->hasMany(NormalizedMarketingAccount::class, 'connector_account_id');
    }

    public function normalizedCampaigns(): HasMany
    {
        return $this->hasMany(NormalizedCampaign::class, 'connector_account_id');
    }

    public function normalizedAdGroups(): HasMany
    {
        return $this->hasMany(NormalizedAdGroup::class, 'connector_account_id');
    }

    public function normalizedAds(): HasMany
    {
        return $this->hasMany(NormalizedAd::class, 'connector_account_id');
    }

    public function normalizedDailyPerformances(): HasMany
    {
        return $this->hasMany(NormalizedDailyPerformance::class, 'connector_account_id');
    }

    public function normalizedCrmCompanies(): HasMany
    {
        return $this->hasMany(NormalizedCrmCompany::class, 'connector_account_id');
    }

    public function normalizedCrmContacts(): HasMany
    {
        return $this->hasMany(NormalizedCrmContact::class, 'connector_account_id');
    }

    public function normalizedCrmDeals(): HasMany
    {
        return $this->hasMany(NormalizedCrmDeal::class, 'connector_account_id');
    }

    public function normalizedCrmActivities(): HasMany
    {
        return $this->hasMany(NormalizedCrmActivity::class, 'connector_account_id');
    }
}
