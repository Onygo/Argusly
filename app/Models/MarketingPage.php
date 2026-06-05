<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingPage extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'key',
        'section',
        'template',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(MarketingPageTranslation::class);
    }

    public function translation(string $locale): ?MarketingPageTranslation
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations
                ->first(fn (MarketingPageTranslation $translation): bool => $translation->locale === strtolower($locale));
        }

        return $this->translations()
            ->where('locale', strtolower($locale))
            ->first();
    }

    public function translationOrFail(string $locale): MarketingPageTranslation
    {
        $translation = $this->translation($locale);

        if ($translation instanceof MarketingPageTranslation) {
            return $translation;
        }

        throw new \InvalidArgumentException(sprintf('Missing marketing page translation [%s] for locale [%s].', $this->key, $locale));
    }
}
