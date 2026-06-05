<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LicenseKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_key_hash',
        'workspace_id',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isActiveAndValid(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }
}

