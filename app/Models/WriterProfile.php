<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WriterProfile extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_UPLOADED_TEXTS = 'uploaded_texts';
    public const SOURCE_CONTENT_HISTORY = 'content_history';
    public const SOURCE_MIXED = 'mixed';

    public const SCOPE_AUTHOR = 'author';
    public const SCOPE_BRAND = 'brand';
    public const SCOPE_COMPANY = 'company';
    public const SCOPE_CAMPAIGN = 'campaign';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'workspace_id',
        'brand_id',
        'user_id',
        'name',
        'description',
        'source_type',
        'profile_scope',
        'tone_summary',
        'writing_style_summary',
        'structure_summary',
        'vocabulary_notes',
        'formatting_preferences',
        'do_rules',
        'dont_rules',
        'example_patterns',
        'confidence_score',
        'status',
        'retain_source_text',
        'channel_defaults',
        'last_analyzed_at',
        'metadata',
    ];

    protected $casts = [
        'do_rules' => 'array',
        'dont_rules' => 'array',
        'example_patterns' => 'array',
        'confidence_score' => 'float',
        'retain_source_text' => 'boolean',
        'channel_defaults' => 'array',
        'last_analyzed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class, 'brand_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(WriterProfileSource::class);
    }

    public function compactPromptContext(?string $channel = null): string
    {
        $lines = [
            'Writer profile (style direction only; lower priority than campaign, brand, persona, factual claims, and search intent):',
            'Name: '.$this->name,
            'Tone: '.($this->tone_summary ?: 'Not specified'),
            'Writing style: '.($this->writing_style_summary ?: 'Not specified'),
            'Structure: '.($this->structure_summary ?: 'Not specified'),
            'Vocabulary: '.($this->vocabulary_notes ?: 'Not specified'),
            'Formatting: '.($this->formatting_preferences ?: 'Not specified'),
        ];

        $doRules = $this->stringList($this->do_rules);
        if ($doRules !== '') {
            $lines[] = 'Do rules: '.$doRules;
        }

        $dontRules = $this->stringList($this->dont_rules);
        if ($dontRules !== '') {
            $lines[] = 'Do not rules: '.$dontRules;
        }

        $channelRule = $this->channelInstruction($channel);
        if ($channelRule !== '') {
            $lines[] = 'Channel adjustment: '.$channelRule;
        }

        $lines[] = 'Use this profile only as style guidance. Do not reuse unique sentences, claims, examples, anecdotes, or recognizable formulations from source material.';

        return implode("\n", $lines);
    }

    private function channelInstruction(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return match ($channel) {
            'blog', 'kb_article', 'article' => 'For blog content, preserve the profile rhythm while keeping SEO structure, headings, and factual completeness intact.',
            'linkedin', 'linkedin_post', 'social' => 'For LinkedIn, compress the style into a strong hook, short blocks, and a clear practical takeaway.',
            'newsletter' => 'For newsletters, keep the familiar voice but make the opening personal, scannable, and action-oriented.',
            'landing_page' => 'For landing pages, keep the style concise while prioritizing positioning, proof, clarity, and conversion hierarchy.',
            'meta', 'meta_title', 'meta_description' => 'For metadata, use the profile vocabulary lightly; click clarity and search relevance are more important than stylistic flourish.',
            'answer_block', 'aeo' => 'For answer blocks, keep the profile plain and direct while prioritizing answer completeness and neutral factual wording.',
            default => '',
        };
    }

    /**
     * @param  array<int, mixed>|null  $items
     */
    private function stringList(?array $items): string
    {
        return collect($items ?? [])
            ->map(fn ($item): string => trim(is_array($item) ? (string) ($item['rule'] ?? $item['text'] ?? '') : (string) $item))
            ->filter()
            ->take(8)
            ->implode('; ');
    }
}
