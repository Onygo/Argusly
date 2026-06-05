<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Services\SocialDistribution\LinkedInPostTextRenderer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SocialPostVariant extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'social_post_id',
        'campaign_id',
        'campaign_content_id',
        'campaign_distribution_plan_id',
        'content_id',
        'social_account_id',
        'platform',
        'post_type',
        'variant_type',
        'status',
        'variant_number',
        'hook',
        'body',
        'hashtags',
        'mentions',
        'media_refs',
        'generation_prompt_context',
        'generation_result',
        'generation_model',
        'estimated_character_count',
        'quality_score',
        'score',
        'selected',
        'generated_at',
        'submitted_for_approval_at',
        'approved_at',
        'approved_by',
        'approval_notes',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'platform' => SocialPlatform::class,
        'post_type' => SocialPostType::class,
        'status' => SocialPostVariantStatus::class,
        'variant_number' => 'integer',
        'hashtags' => 'array',
        'mentions' => 'array',
        'media_refs' => 'array',
        'generation_prompt_context' => 'array',
        'generation_result' => 'array',
        'estimated_character_count' => 'integer',
        'quality_score' => 'integer',
        'score' => 'integer',
        'selected' => 'boolean',
        'generated_at' => 'datetime',
        'submitted_for_approval_at' => 'datetime',
        'approved_at' => 'datetime',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            $variant->body = $variant->bodyWithoutRepeatedHook();
            $variant->estimated_character_count = Str::length($variant->publishingText());
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function campaignContent(): BelongsTo
    {
        return $this->belongsTo(CampaignContent::class);
    }

    public function distributionPlan(): BelongsTo
    {
        return $this->belongsTo(CampaignDistributionPlan::class, 'campaign_distribution_plan_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }

    public function isApproved(): bool
    {
        return $this->status === SocialPostVariantStatus::APPROVED
            || $this->approved_at !== null;
    }

    public function bodyWithoutRepeatedHook(): string
    {
        $body = trim((string) $this->body);
        $hook = trim((string) $this->hook);

        if ($body === '' || $hook === '') {
            return $body;
        }

        $normalizedBody = Str::of($body)->squish()->lower()->toString();
        $normalizedHook = Str::of($hook)->squish()->lower()->toString();

        if ($normalizedBody === $normalizedHook) {
            return '';
        }

        if (Str::startsWith($normalizedBody, $normalizedHook.' ')) {
            return trim(Str::substr($body, Str::length($hook)));
        }

        $prefix = $hook."\n\n";
        if (Str::startsWith($body, $prefix)) {
            return trim(Str::after($body, $prefix));
        }

        return $body;
    }

    public function publishingText(): string
    {
        return app(LinkedInPostTextRenderer::class)->renderVariant($this);
    }

    public function sourceUrl(): ?string
    {
        $url = trim((string) data_get($this->generation_prompt_context, 'source_url', ''));

        if ($url !== '') {
            return $url;
        }

        $url = trim((string) data_get($this->metadata, 'source_url', ''));

        return $url !== '' ? $url : null;
    }

    public function languageCode(): string
    {
        $language = trim((string) data_get($this->generation_prompt_context, 'language', ''));

        return $language !== '' ? $language : 'en';
    }

    public function hashtagsLine(): string
    {
        return collect((array) $this->hashtags)
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->filter()
            ->map(fn (string $tag): string => Str::startsWith($tag, '#') ? $tag : '#'.$tag)
            ->unique()
            ->take(8)
            ->implode(' ');
    }

}
