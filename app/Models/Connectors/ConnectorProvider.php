<?php

namespace App\Models\Connectors;

use App\Models\MarketingObservation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectorProvider extends Model
{
    use HasFactory;
    use HasUuids;

    public const CATEGORY_SEARCH = 'search';
    public const CATEGORY_ANALYTICS = 'analytics';
    public const CATEGORY_SOCIAL = 'social';
    public const CATEGORY_ADS = 'ads';
    public const CATEGORY_CRM = 'crm';
    public const CATEGORY_OTHER = 'other';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'provider_key',
        'name',
        'category',
        'status',
        'config_json',
        'supports_oauth',
        'supports_sync',
        'supports_webhooks',
    ];

    protected $casts = [
        'config_json' => 'array',
        'supports_oauth' => 'boolean',
        'supports_sync' => 'boolean',
        'supports_webhooks' => 'boolean',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(ConnectorAccount::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ConnectorCredential::class);
    }

    public function oauthStates(): HasMany
    {
        return $this->hasMany(ConnectorOAuthState::class);
    }

    public function marketingObservations(): HasMany
    {
        return $this->hasMany(MarketingObservation::class, 'connector_provider_id');
    }
}
