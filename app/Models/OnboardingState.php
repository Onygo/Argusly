<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OnboardingState extends Model
{
    use HasUuids;

    public const PHASE_REGISTERED = 'registered';
    public const PHASE_EMAIL_UNVERIFIED = 'email_unverified';
    public const PHASE_VERIFIED = 'verified';
    public const PHASE_FIRST_LOGIN = 'first_login';
    public const PHASE_ACTIVATED = 'activated';
    public const PHASE_COLD = 'cold';

    protected $fillable = [
        'user_id',
        'organization_id',
        'workspace_id',
        'phase',
        'intent',
        'registered_at',
        'verified_at',
        'first_login_at',
        'first_value_at',
        'last_activity_at',
        'last_email_sent_at',
        'emails_sent_json',
        'completed_steps_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'registered_at' => 'datetime',
        'verified_at' => 'datetime',
        'first_login_at' => 'datetime',
        'first_value_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'last_email_sent_at' => 'datetime',
        'emails_sent_json' => 'array',
        'completed_steps_json' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function wasEmailSent(string $key): bool
    {
        $sent = is_array($this->emails_sent_json) ? $this->emails_sent_json : [];

        return array_key_exists($key, $sent);
    }
}

