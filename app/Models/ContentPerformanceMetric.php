<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentPerformanceMetric extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'content_id',
        'views',
        'reads',
        'read_rate',
        'first_seen_at',
        'last_seen_at',
        'source_domain',
        'meta',
    ];

    protected $casts = [
        'views' => 'integer',
        'reads' => 'integer',
        'read_rate' => 'float',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }
}
