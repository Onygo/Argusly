<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqPageAssignment extends Model
{
    protected $fillable = [
        'faq_id',
        'page_type',
        'page_slug',
        'locale',
        'weight',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    public function faqQuestion(): BelongsTo
    {
        return $this->belongsTo(FaqQuestion::class, 'faq_id');
    }
}
