<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SocialAccount extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'tenant_id',
        'user_id',
        'workspace_id',
        'distribution_channel_id',
        'provider',
        'provider_member_urn',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'platform',
        'account_type',
        'display_name',
        'platform_account_id',
        'status',
        'oauth',
        'token_ref',
        'profile',
        'publishing_rules',
        'rate_limit_policy',
        'connected_at',
        'last_verified_at',
        'rate_limited_until',
        'last_error',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'scopes' => 'array',
        'platform' => SocialPlatform::class,
        'status' => SocialAccountStatus::class,
        'oauth' => 'array',
        'token_ref' => 'array',
        'profile' => 'array',
        'publishing_rules' => 'array',
        'rate_limit_policy' => 'array',
        'connected_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'rate_limited_until' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function distributionChannel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(SocialEngagementMetric::class);
    }

    public function rateLimitWindows(): HasMany
    {
        return $this->hasMany(SocialRateLimitWindow::class);
    }

    public function isConnected(): bool
    {
        return in_array($this->status, [SocialAccountStatus::CONNECTED, SocialAccountStatus::ACTIVE], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isRateLimited(): bool
    {
        return $this->rate_limited_until !== null && $this->rate_limited_until->isFuture();
    }

    public function isPublishable(): bool
    {
        return $this->isConnected()
            && ! $this->isRateLimited()
            && $this->hasPermission('publish');
    }

    public function isSchedulable(): bool
    {
        return $this->isPublishable() && $this->hasPermission('schedule');
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = (array) data_get($this->publishing_rules, 'permissions', ['draft', 'schedule', 'publish']);

        return in_array($permission, $permissions, true);
    }

    public function ownerLabel(): string
    {
        return $this->user?->name ?: 'Workspace';
    }

    public function actorLabel(): string
    {
        $labels = $this->labels();

        return $labels[0] ?? Str::headline((string) $this->account_type ?: 'Account');
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return collect(data_get($this->profile, 'labels', []))
            ->map(fn ($label): string => trim((string) $label))
            ->filter()
            ->values()
            ->all();
    }

    public function toneProfile(): ?string
    {
        $tone = trim((string) data_get($this->profile, 'tone_profile', ''));

        return $tone !== '' ? $tone : null;
    }

    public function engagementRole(): ?string
    {
        $role = trim((string) data_get($this->profile, 'engagement_role', ''));

        return $role !== '' ? $role : null;
    }

    public function avatarUrl(): ?string
    {
        $profile = (array) ($this->profile ?? []);

        $candidates = [
            data_get($profile, 'picture'),
            data_get($profile, 'profilePicture.displayImage'),
            data_get($profile, 'profilePicture.displayImage~.elements.0.identifiers.0.identifier'),
            data_get($profile, 'profilePicture.displayImage~.elements.0.identifiers.0.file'),
            data_get($profile, 'profile_image_url'),
            data_get($profile, 'avatar_url'),
        ];

        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if ($url !== '' && Str::startsWith($url, ['https://', 'http://'])) {
                return $url;
            }
        }

        return null;
    }

    public function initials(): string
    {
        $name = trim((string) $this->display_name);
        if ($name === '') {
            return 'LI';
        }

        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('') ?: 'LI';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
