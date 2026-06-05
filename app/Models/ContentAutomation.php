<?php

namespace App\Models;

use App\Enums\ContentAutomationFrequencyUnit;
use App\Enums\ContentAutomationMode;
use App\Enums\ContentAutomationPublicationMode;
use App\Enums\SupportedLanguage;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentAutomation extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'content_destination_id',
        'name',
        'is_active',
        'is_paused',
        'mode',
        'publication_mode',
        'generation_frequency_value',
        'generation_frequency_unit',
        'next_run_at',
        'last_run_at',
        'end_at',
        'max_runs',
        'run_count',
        'chain_size',
        'locale',
        'locales',
        'topic_scope',
        'content_goal',
        'company_context_override',
        'use_brand_voice_id',
        'use_team_persona_id',
        'use_buyer_persona_id',
        'include_internal_linking',
        'include_translation',
        'avoid_topic_overlap',
        'funnel_stage',
        'campaign_context',
        'pillar_strategy',
        'settings',
        'created_by',
        'updated_by',
        'paused_at',
        'last_failure_message',
        'last_failure_code',
        'last_failure_run_id',
        'last_failure_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_paused' => 'boolean',
        'mode' => ContentAutomationMode::class,
        'publication_mode' => ContentAutomationPublicationMode::class,
        'generation_frequency_value' => 'integer',
        'generation_frequency_unit' => ContentAutomationFrequencyUnit::class,
        'max_runs' => 'integer',
        'run_count' => 'integer',
        'chain_size' => 'integer',
        'locale' => SupportedLanguage::class,
        'locales' => 'array',
        'include_internal_linking' => 'boolean',
        'include_translation' => 'boolean',
        'avoid_topic_overlap' => 'boolean',
        'pillar_strategy' => 'array',
        'settings' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'end_at' => 'datetime',
        'paused_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function contentDestination(): BelongsTo
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class, 'use_brand_voice_id');
    }

    public function teamPersona(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'use_team_persona_id');
    }

    public function buyerPersona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'use_buyer_persona_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ContentAutomationRun::class, 'automation_id')->latest('created_at');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ContentAutomationRun::class, 'automation_id')->latestOfMany('created_at');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'automation_id');
    }

    public function runItems(): HasMany
    {
        return $this->hasMany(ContentAutomationRunItem::class, 'automation_id');
    }

    public function isPaused(): bool
    {
        return (bool) ($this->is_paused || $this->paused_at !== null);
    }

    public function isCompleted(?Carbon $at = null): bool
    {
        return $this->completionReason($at) !== null;
    }

    public function isActive(?Carbon $at = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isPaused()) {
            return false;
        }

        return $this->completionReason($at) === null;
    }

    public function canRun(?Carbon $at = null): bool
    {
        $at ??= now();

        if (! $this->isActive($at) || ! $this->next_run_at) {
            return false;
        }

        return $this->next_run_at->lte($at);
    }

    public function skipReason(?Carbon $at = null): ?string
    {
        $at ??= now();

        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->isPaused()) {
            return 'paused';
        }

        return $this->completionReason($at);
    }

    public function completionReason(?Carbon $at = null): ?string
    {
        $at ??= now();

        if ($this->end_at && $this->end_at->lt($at)) {
            return 'end_at_reached';
        }

        if ($this->max_runs !== null && (int) $this->run_count >= (int) $this->max_runs) {
            return 'max_runs_reached';
        }

        return null;
    }

    public function pause(): void
    {
        $this->forceFill([
            'is_paused' => true,
            'paused_at' => now(),
        ])->save();
    }

    public function resume(): void
    {
        $this->forceFill([
            'is_paused' => false,
            'paused_at' => null,
            'next_run_at' => $this->calculateNextRunAt(),
        ])->save();
    }

    public function calculateNextRunAt(?Carbon $from = null): Carbon
    {
        $from ??= now();
        $value = max(1, (int) $this->generation_frequency_value);
        $unit = $this->generation_frequency_unit instanceof ContentAutomationFrequencyUnit
            ? $this->generation_frequency_unit
            : ContentAutomationFrequencyUnit::from((string) $this->generation_frequency_unit);

        return match ($unit) {
            ContentAutomationFrequencyUnit::HOURS => $from->copy()->addHours($value),
            ContentAutomationFrequencyUnit::WEEKS => $from->copy()->addWeeks($value),
            default => $from->copy()->addDays($value),
        };
    }

    public function lifecycleStatus(?Carbon $at = null): string
    {
        if ($this->isCompleted($at)) {
            return 'completed';
        }

        if ($this->isPaused()) {
            return 'paused';
        }

        return $this->is_active ? 'active' : 'inactive';
    }

    public function sourceLocale(): string
    {
        $workspaceDefault = $this->workspace?->defaultContentLanguageCode();
        $rawLocale = $this->locale instanceof SupportedLanguage
            ? $this->locale->value
            : (string) $this->locale;
        $normalized = SupportedLanguage::normalizeLocale($rawLocale);

        if ($normalized !== null) {
            return $normalized;
        }

        if (is_string($workspaceDefault) && $workspaceDefault !== '') {
            return SupportedLanguage::fromStringOrDefault($workspaceDefault)->value;
        }

        return SupportedLanguage::fromStringOrDefault(config('app.fallback_locale', 'en'))->value;
    }

    /**
     * @return array<int, string>
     */
    public function configuredLocales(): array
    {
        $locales = collect((array) $this->locales)
            ->map(fn (mixed $locale): string => SupportedLanguage::fromStringOrDefault((string) $locale)->value)
            ->prepend($this->sourceLocale())
            ->unique()
            ->values()
            ->all();

        return $locales === [] ? [$this->sourceLocale()] : $locales;
    }

    /**
     * @return array<int,string>
     */
    public function targetLocales(): array
    {
        return collect($this->configuredLocales())
            ->reject(fn (string $locale): bool => $locale === $this->sourceLocale())
            ->values()
            ->all();
    }

    public function autoTranslateGeneratedContent(): bool
    {
        return (bool) $this->include_translation;
    }

    public function autoPublishTranslationsWithSource(): bool
    {
        $value = data_get($this->settings, 'auto_publish_translations');

        return $value === null ? true : (bool) $value;
    }

    public function familyPublishMode(): string
    {
        $mode = trim((string) data_get($this->settings, 'publish_mode', 'synced'));

        return in_array($mode, ['independent', 'synced'], true) ? $mode : 'synced';
    }

    public function usesSyncedFamilyPublishing(): bool
    {
        return $this->familyPublishMode() === 'synced';
    }
}
