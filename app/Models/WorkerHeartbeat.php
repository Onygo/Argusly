<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['worker_name', 'queue', 'status', 'metadata', 'last_seen_at'])]
class WorkerHeartbeat extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (WorkerHeartbeat $heartbeat): void {
            $heartbeat->uuid ??= (string) Str::uuid();
            $heartbeat->status ??= 'running';
            $heartbeat->last_seen_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
