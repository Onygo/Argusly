<?php

namespace App\Models;

use App\Enums\SupportedLanguage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentRenderArtifact extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_STALE = 'stale';
    public const STATUS_INELIGIBLE = 'ineligible';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_CURRENT_REVISION = 'current_revision';
    public const SOURCE_CURRENT_VERSION = 'current_version';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_REBUILD = 'rebuild';

    protected $fillable = [
        'content_id',
        'content_version_id',
        'rendered_html',
        'rendered_markdown',
        'markdown_checksum',
        'markdown_generated_at',
        'markdown_version',
        'markdown_locale',
        'markdown_status',
        'markdown_source',
        'markdown_excerpt',
        'meta',
    ];

    protected $casts = [
        'markdown_generated_at' => 'datetime',
        'markdown_version' => 'integer',
        'markdown_locale' => SupportedLanguage::class,
        'meta' => 'array',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function contentVersion()
    {
        return $this->belongsTo(ContentVersion::class, 'content_version_id');
    }

    public function scopeForLocale($query, string|SupportedLanguage $locale)
    {
        $resolved = $locale instanceof SupportedLanguage ? $locale->value : strtolower(trim((string) $locale));

        return $query->where('markdown_locale', $resolved);
    }

    public function scopeReady($query)
    {
        return $query->where('markdown_status', self::STATUS_READY);
    }

    public function scopeNeedsRegeneration($query)
    {
        return $query->whereIn('markdown_status', [
            self::STATUS_PENDING,
            self::STATUS_STALE,
            self::STATUS_FAILED,
        ]);
    }

    public function hasMarkdown(): bool
    {
        return trim((string) $this->rendered_markdown) !== ''
            && $this->markdown_status === self::STATUS_READY;
    }

    public function isEligible(): bool
    {
        return $this->markdown_status !== self::STATUS_INELIGIBLE;
    }
}
