<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

class EarlyAccessInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'early_access_signup_id',
        'email',
        'token_hash',
        'token_encrypted',
        'expires_at',
        'accepted_at',
        'invited_by',
    ];

    protected $hidden = [
        'token_hash',
        'token_encrypted',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function signup()
    {
        return $this->belongsTo(EarlyAccessSignup::class, 'early_access_signup_id');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getTokenAttribute(): string
    {
        $encrypted = (string) $this->token_encrypted;

        try {
            $token = Crypt::decryptString($encrypted);

            if ($this->looksSerialized($token)) {
                $legacy = @unserialize($token, ['allowed_classes' => false]);
                if (is_string($legacy)) {
                    return $legacy;
                }
            }

            return $token;
        } catch (\Throwable) {
            $legacy = Crypt::decrypt($encrypted);

            return is_string($legacy) ? $legacy : (string) Arr::first((array) $legacy, null, '');
        }
    }

    private function looksSerialized(string $value): bool
    {
        return preg_match('/^(?:s|i|d|b|a|O|C|N):/', $value) === 1;
    }
}
