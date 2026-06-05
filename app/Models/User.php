<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_code_hash',
        'email_code_expires_at',
        'email_code_verified_at',
        'email_code_sent_at',
        'email_code_attempts',
        'email_code_last_attempt_at',
        'organization_id',
        'role',
        'active',
        'approved_at',
        'is_admin',
        'admin_role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_code_expires_at' => 'datetime',
            'email_code_verified_at' => 'datetime',
            'email_code_sent_at' => 'datetime',
            'email_code_last_attempt_at' => 'datetime',
            'email_code_attempts' => 'integer',
            'approved_at' => 'datetime',
            'is_admin' => 'boolean',
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isAdminAreaUser(): bool
    {
        if (! $this->is_admin) {
            return false;
        }

        $role = $this->resolvedAdminRole();

        return in_array($role, ['admin', 'superadmin'], true);
    }

    public function isSuperadmin(): bool
    {
        if (! $this->is_admin) {
            return false;
        }

        return $this->resolvedAdminRole() === 'superadmin';
    }

    public function hasAdminRole(string $role): bool
    {
        return $this->resolvedAdminRole() === trim(strtolower($role));
    }

    public function resolvedAdminRole(): string
    {
        $role = trim(strtolower((string) ($this->admin_role ?? '')));

        if ($role !== '') {
            return $role;
        }

        // Backward compatibility for legacy platform-admin rows without explicit admin_role.
        return $this->is_admin ? 'superadmin' : 'user';
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function accessOverrides(): HasMany
    {
        return $this->hasMany(AccessOverride::class);
    }

    public function latestAccessOverride(): HasOne
    {
        return $this->hasOne(AccessOverride::class)->latestOfMany('created_at');
    }

    public function createdAccessOverrides(): HasMany
    {
        return $this->hasMany(AccessOverride::class, 'created_by_user_id');
    }

    public function endedAccessOverrides(): HasMany
    {
        return $this->hasMany(AccessOverride::class, 'ended_by_user_id');
    }

    public function onboardingState()
    {
        return $this->hasOne(OnboardingState::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function createdNotifications()
    {
        return $this->hasMany(Notification::class, 'created_by_admin_id');
    }

    public function contentSeries()
    {
        return $this->hasMany(ContentSeries::class, 'created_by');
    }

    public function createdResearchProjects()
    {
        return $this->hasMany(ResearchProject::class, 'created_by');
    }

    public function isApproved(): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return $this->approved_at !== null && $this->organization?->status === 'active';
    }

    public function needsEmailCodeVerification(): bool
    {
        if ($this->is_admin) {
            return false;
        }

        return $this->email_code_verified_at === null
            && trim((string) ($this->email_code_hash ?? '')) !== '';
    }
}
