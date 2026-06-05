<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingBlogRedirect extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_path',
        'source_locale',
        'source_slug',
        'target_path',
        'target_locale',
        'target_slug',
        'target_content_id',
        'redirect_kind',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function targetContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'target_content_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForSource($query, string $locale, string $slug)
    {
        return $query
            ->where('source_locale', strtolower(trim($locale)))
            ->where('source_slug', strtolower(trim($slug)));
    }
}
