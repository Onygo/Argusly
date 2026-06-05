<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class Invite extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'invited_by',
        'email',
        'role',
        'token_hash',
        'token_encrypted',
        'accepted_at',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
        'token_encrypted',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
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

    public function scopePending($query)
    {
        return $query->whereNull('accepted_at');
    }
}
