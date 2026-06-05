<?php

namespace App\Models;

use App\Support\OrganizationSafetyGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Organization extends Model
{
    use HasFactory;

    // Organization lifecycle statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_ARCHIVED = 'archived';
    public const ACCESS_TIER_PAID = 'paid';
    public const ACCESS_TIER_EARLY_BIRD = 'early_bird';
    public const ACCESS_TIER_TRIAL = 'trial';
    public const ACCESS_TIER_FREE = 'free';

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'status',
        'approved_at',
        'approved_by',
        'primary_user_id',
        'active_subscription_id',
        'custom_domain',
        'notification_settings',
        // @deprecated Legacy organization-level API settings.
        // New integrations are managed via developer API keys/webhooks/destinations.
        'api_enabled',
        'api_key_encrypted',
        'api_key_hash',
        'webhook_url',
        'billing_company_name',
        'billing_email',
        'billing_address_line1',
        'billing_address_line2',
        'billing_postal_code',
        'billing_city',
        'billing_country_code',
        'billing_vat_number',
        'vat_id',
        'billing_kvk_number',
        'billing_address',
        'access_tier',
        'early_bird_started_at',
        'early_bird_ends_at',
        'early_bird_note',
        'converted_to_paid_at',
        'access_updated_by',
        'company_description',
        'positioning_statement',
        'target_audience',
        'industry',
        'tone_defaults',
        'brand_profile',
        'seo_profile',
        'design_profile',
        'technical_profile',
        'onboarding_scan_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'notification_settings' => 'array',
        'api_enabled' => 'boolean',
        'billing_address' => 'array',
        'early_bird_started_at' => 'datetime',
        'early_bird_ends_at' => 'datetime',
        'converted_to_paid_at' => 'datetime',
        'tone_defaults' => 'array',
        'brand_profile' => 'array',
        'seo_profile' => 'array',
        'design_profile' => 'array',
        'technical_profile' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            OrganizationSafetyGuard::assertAllowed($organization->name, $organization->slug);
        });
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function primaryUser()
    {
        return $this->belongsTo(User::class, 'primary_user_id');
    }

    public function activeSubscription()
    {
        return $this->belongsTo(Subscription::class, 'active_subscription_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function planChanges()
    {
        return $this->hasMany(SubscriptionPlanChange::class);
    }

    public function invites()
    {
        return $this->hasMany(Invite::class);
    }

    public function workspaces()
    {
        return $this->hasMany(Workspace::class);
    }

    public function clientSites()
    {
        return $this->hasManyThrough(ClientSite::class, Workspace::class);
    }

    public function taxonomySets()
    {
        return $this->belongsToMany(TaxonomySet::class, 'taxonomy_set_tenant', 'tenant_id', 'taxonomy_set_id')
            ->withTimestamps();
    }

    public function brandVoices()
    {
        return $this->hasMany(BrandVoice::class);
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function organizationProfile()
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    public function personas()
    {
        return $this->hasMany(Persona::class);
    }

    public function companyIntelligenceProfiles()
    {
        return $this->hasMany(CompanyIntelligenceProfile::class);
    }

    public function enrichmentRuns()
    {
        return $this->hasMany(EnrichmentRun::class);
    }

    public function contentSeries()
    {
        return $this->hasMany(ContentSeries::class);
    }

    public function onboardingScan()
    {
        return $this->belongsTo(WebsiteScan::class, 'onboarding_scan_id');
    }

    public function accessUpdatedBy()
    {
        return $this->belongsTo(User::class, 'access_updated_by');
    }

    public function websiteScans()
    {
        return $this->hasMany(WebsiteScan::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOnHold(): bool
    {
        return $this->status === self::STATUS_ON_HOLD;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Get human-readable status label.
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_ON_HOLD => 'On hold',
            self::STATUS_ARCHIVED => 'Archived',
            self::STATUS_PENDING => 'Pending',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * Get CSS classes for status badge styling.
     */
    public function getStatusBadgeClasses(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'border-emerald-300/80 bg-emerald-500/10 text-emerald-800',
            self::STATUS_ON_HOLD => 'border-amber-300/80 bg-amber-500/10 text-amber-800',
            self::STATUS_ARCHIVED => 'border-slate-300/80 bg-slate-500/10 text-slate-600',
            default => 'border-border bg-background text-textSecondary',
        };
    }

    public function hasCompleteBillingDetails(): bool
    {
        return trim((string) ($this->billing_company_name ?? $this->name ?? '')) !== ''
            && trim((string) ($this->billing_address_line1 ?? '')) !== ''
            && trim((string) ($this->billing_country_code ?? '')) !== '';
    }

    public function getApiKeyAttribute(): ?string
    {
        // @deprecated Kept for legacy integrations while migrating to workspace-scoped api_keys.
        if (! $this->api_key_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setApiKey(string $plain): void
    {
        // @deprecated Legacy organization-level key storage. New keys are hashed in api_keys.
        $token = trim($plain);

        $this->api_key_encrypted = Crypt::encryptString($token);
        $this->api_key_hash = hash('sha256', $token);
    }
}
