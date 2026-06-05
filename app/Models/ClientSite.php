<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientSite extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const TYPE_WORDPRESS = 'wordpress';
    public const TYPE_LARAVEL = 'laravel';

    protected $fillable = [
        'workspace_id',
        'type',
        'connector_platform',
        'seo_provider',
        'supports_meta_title',
        'supports_meta_description',
        'supports_canonical',
        'supports_og_tags',
        'name',
        'site_url',
        'base_url',
        'allowed_domains',
        'is_active',
        'status',
        'last_seen_at',
        'last_healthcheck_at',
        'last_heartbeat_at',
        'last_error',
        'wp_version',
        'plugin_version',
        'connector_version',
        'capabilities',
        'connector_meta',
        'automation_settings',
        'created_by_user_id',
        'disabled_at',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'is_active' => 'boolean',
        'supports_meta_title' => 'boolean',
        'supports_meta_description' => 'boolean',
        'supports_canonical' => 'boolean',
        'supports_og_tags' => 'boolean',
        'capabilities' => 'array',
        'connector_meta' => 'array',
        'automation_settings' => 'array',
        'last_seen_at' => 'datetime',
        'last_healthcheck_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'disabled_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ClientSite $site): void {
            $site->type = self::normalizeType((string) $site->type);

            $source = (string) ($site->base_url ?: $site->site_url);
            $site->base_url = SiteUrl::normalizeBaseUrl($source);

            if (! $site->site_url) {
                $site->site_url = $site->base_url;
            }

            if (! $site->status) {
                $site->status = $site->is_active ? 'pending' : 'disabled';
            }
        });
    }

    /**
     * @return array<int,string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_WORDPRESS,
            self::TYPE_LARAVEL,
        ];
    }

    public static function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return in_array($normalized, self::allowedTypes(), true)
            ? $normalized
            : self::TYPE_WORDPRESS;
    }

    public function isWordPress(): bool
    {
        return self::normalizeType((string) $this->type) === self::TYPE_WORDPRESS;
    }

    public function isLaravel(): bool
    {
        return self::normalizeType((string) $this->type) === self::TYPE_LARAVEL;
    }

    /**
     * Get the heartbeat status based on last_heartbeat_at.
     *
     * @return string online|warning|offline
     */
    public function getHeartbeatStatusAttribute(): string
    {
        if (! $this->last_heartbeat_at) {
            return 'offline';
        }

        $minutes = $this->last_heartbeat_at->diffInMinutes(now());

        return match (true) {
            $minutes <= 10 => 'online',
            $minutes <= 30 => 'warning',
            default => 'offline',
        };
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->whereHas('workspace', function ($workspaceQuery) use ($organizationId) {
            $workspaceQuery->where('organization_id', $organizationId);
        });
    }

    public function drafts()
    {
        return $this->hasMany(Draft::class);
    }

    public function briefs()
    {
        return $this->hasMany(Brief::class);
    }

    public function siteTokens()
    {
        return $this->hasMany(SiteToken::class);
    }

    public function llmTrackingQueries()
    {
        return $this->hasMany(LlmTrackingQuery::class, 'client_site_id');
    }

    public function llmTrackingQuerySets()
    {
        return $this->hasMany(LlmTrackingQuerySet::class, 'client_site_id');
    }

    public function competitors()
    {
        return $this->hasMany(SiteCompetitor::class, 'client_site_id');
    }

    public function seoAudits()
    {
        return $this->hasMany(SeoAudit::class, 'client_site_id');
    }

    public function contentBatches()
    {
        return $this->hasMany(ContentBatch::class, 'client_site_id');
    }

    public function contentAutomations()
    {
        return $this->hasMany(ContentAutomation::class, 'client_site_id');
    }

    public function contentAutomationRuns()
    {
        return $this->hasMany(ContentAutomationRun::class, 'client_site_id');
    }

    public function researchProjects()
    {
        return $this->hasMany(ResearchProject::class, 'client_site_id');
    }

    public function draftComparisons()
    {
        return $this->hasMany(DraftComparison::class, 'client_site_id');
    }

    public function contentSeries()
    {
        return $this->hasMany(ContentSeries::class, 'site_id');
    }

    public function analyticsSite()
    {
        return $this->hasOne(AnalyticsSite::class);
    }

    public function creditAllocation()
    {
        return $this->hasOne(SiteCreditAllocation::class);
    }
}
