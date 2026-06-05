<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'summary',
        'body_markdown',
        'version',
        'tags',
        'is_public',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_public' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublicVisible(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->where('published_at', '<=', now());
    }

    public function scopeTagged(Builder $query, ?string $tag): Builder
    {
        $normalized = self::normalizeTag((string) $tag);
        if ($normalized === '') {
            return $query;
        }

        return $query->whereJsonContains('tags', $normalized);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $term = trim((string) $search);
        if ($term === '') {
            return $query;
        }

        if ($this->supportsFullText($query)) {
            return $query->whereFullText(['title', 'summary', 'body_markdown'], $term);
        }

        $escaped = addcslashes($term, '\\%_');
        $like = '%' . $escaped . '%';

        return $query->where(function (Builder $nested) use ($like): void {
            $nested->where('title', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhere('body_markdown', 'like', $like);
        });
    }

    /**
     * @param array<int, string>|string|null $value
     * @return array<int, string>
     */
    public static function normalizeTags(array|string|null $value): array
    {
        $items = is_array($value)
            ? $value
            : explode(',', (string) $value);

        return collect($items)
            ->map(fn ($tag): string => self::normalizeTag((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeTag(string $tag): string
    {
        $normalized = Str::of($tag)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return $normalized;
    }

    private function supportsFullText(Builder $query): bool
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        return in_array($driver, ['mysql', 'pgsql'], true);
    }
}
