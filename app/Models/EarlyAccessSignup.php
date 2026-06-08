<?php

namespace App\Models;

use App\Enums\EarlyAccessSignupStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EarlyAccessSignup extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'country',
        'job_title',
        'company_name',
        'company_size',
        'industry',
        'website',
        'use_case',
        'notes',
        'source',
        'priority',
        'qualification_score',
        'assigned_admin_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'marketing_consent',
        'status',
        'internal_notes',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'invited_at',
        'activated_at',
        'rejected_at',
        'invited_by',
        'activated_user_id',
        'workspace_id',
    ];

    protected $casts = [
        'status' => EarlyAccessSignupStatus::class,
        'qualification_score' => 'integer',
        'marketing_consent' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'invited_at' => 'datetime',
        'activated_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function invites()
    {
        return $this->hasMany(EarlyAccessInvite::class)->latest('created_at');
    }

    public function latestInvite()
    {
        return $this->hasOne(EarlyAccessInvite::class)->latestOfMany('created_at');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function assignedAdmin()
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function activatedUser()
    {
        return $this->belongsTo(User::class, 'activated_user_id');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function pilotCosts()
    {
        return $this->hasMany(EarlyAccessPilotCost::class, 'early_access_signup_id');
    }
}
