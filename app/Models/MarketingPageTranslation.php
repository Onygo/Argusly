<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingPageTranslation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'marketing_page_id',
        'locale',
        'title',
        'slug',
        'seo_title',
        'meta_description',
        'canonical_path',
        'content',
    ];

    protected $casts = [
        'content' => 'array',
    ];

    public function marketingPage(): BelongsTo
    {
        return $this->belongsTo(MarketingPage::class);
    }
}
