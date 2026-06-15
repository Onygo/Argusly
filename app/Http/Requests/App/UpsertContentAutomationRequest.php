<?php

namespace App\Http\Requests\App;

use App\Enums\ContentAutomationFrequencyUnit;
use App\Enums\ContentAutomationMode;
use App\Enums\ContentAutomationPublicationMode;
use App\Enums\SupportedLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertContentAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $locales = collect((array) $this->input('locales', []))
            ->merge((array) $this->input('target_locales', []))
            ->map(fn (mixed $locale): ?string => SupportedLanguage::normalizeLocale((string) $locale))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $locale = SupportedLanguage::normalizeLocale((string) $this->input('source_locale', $this->input('locale', '')));
        if ($locale !== null && ! in_array($locale, $locales, true)) {
            array_unshift($locales, $locale);
        }

        $mode = $this->nullableTrim('mode');
        $includeTranslation = $this->boolean('include_translation');
        if (in_array($mode, [ContentAutomationMode::CHAIN->value, ContentAutomationMode::PILLAR_PLUS_CLUSTER->value], true) && count($locales) > 1) {
            $includeTranslation = true;
        }

        $this->merge([
            'name' => $this->nullableTrim('name'),
            'workspace_id' => $this->nullableTrim('workspace_id'),
            'client_site_id' => $this->nullableTrim('client_site_id'),
            'content_destination_id' => $this->nullableTrim('content_destination_id'),
            'mode' => $mode,
            'publication_mode' => $this->nullableTrim('publication_mode'),
            'generation_frequency_value' => (int) $this->input('generation_frequency_value', 3),
            'generation_frequency_unit' => $this->nullableTrim('generation_frequency_unit'),
            'chain_size' => (int) $this->input('chain_size', 5),
            'locale' => $locale,
            'locales' => $locales,
            'publish_mode' => $this->nullableTrim('publish_mode'),
            'topic_scope' => $this->nullableTrim('topic_scope'),
            'content_goal' => $this->nullableTrim('content_goal'),
            'company_context_override' => $this->nullableTrim('company_context_override'),
            'use_brand_voice_id' => $this->nullableTrim('use_brand_voice_id'),
            'use_team_persona_id' => $this->nullableInt('use_team_persona_id'),
            'use_buyer_persona_id' => $this->nullableInt('use_buyer_persona_id'),
            'funnel_stage' => $this->nullableTrim('funnel_stage'),
            'campaign_context' => $this->nullableTrim('campaign_context'),
            'preferred_length' => $this->nullableTrim('preferred_length'),
            'content_pillars' => $this->nullableTrim('content_pillars'),
            'end_at' => $this->nullableTrim('end_at'),
            'max_runs' => $this->nullableInt('max_runs'),
            'is_active' => $this->boolean('is_active', true),
            'include_internal_linking' => $this->boolean('include_internal_linking'),
            'include_translation' => $includeTranslation,
            'auto_publish_translations' => $this->boolean('auto_publish_translations', true),
            'avoid_topic_overlap' => $this->boolean('avoid_topic_overlap', true),
            'generate_structured_answers' => $this->boolean('generate_structured_answers'),
            'optimize_for_aeo' => $this->boolean('optimize_for_aeo'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'workspace_id' => ['required', 'uuid'],
            'client_site_id' => ['nullable', 'uuid'],
            'content_destination_id' => ['nullable', 'uuid'],
            'mode' => ['required', 'string', Rule::in(ContentAutomationMode::values())],
            'publication_mode' => ['required', 'string', Rule::in(ContentAutomationPublicationMode::values())],
            'generation_frequency_value' => ['required', 'integer', 'min:1', 'max:90'],
            'generation_frequency_unit' => ['required', 'string', Rule::in(ContentAutomationFrequencyUnit::values())],
            'chain_size' => ['required', 'integer', 'min:1', 'max:10'],
            'locale' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'locales' => ['nullable', 'array', 'min:1'],
            'locales.*' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'publish_mode' => ['nullable', Rule::in(['independent', 'synced'])],
            'topic_scope' => ['required', 'string', 'max:4000'],
            'content_goal' => ['nullable', 'string', 'max:2000'],
            'company_context_override' => ['nullable', 'string', 'max:5000'],
            'use_brand_voice_id' => ['nullable', 'uuid'],
            'use_team_persona_id' => ['nullable', 'integer'],
            'use_buyer_persona_id' => ['nullable', 'integer'],
            'funnel_stage' => ['nullable', 'string', 'max:64'],
            'campaign_context' => ['nullable', 'string', 'max:191'],
            'preferred_length' => ['nullable', Rule::in(['short', 'medium', 'long', 'pillar'])],
            'content_pillars' => ['nullable', 'string', 'max:2000'],
            'end_at' => ['nullable', 'date'],
            'max_runs' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'include_internal_linking' => ['nullable', 'boolean'],
            'include_translation' => ['nullable', 'boolean'],
            'auto_publish_translations' => ['nullable', 'boolean'],
            'avoid_topic_overlap' => ['nullable', 'boolean'],
            'generate_structured_answers' => ['nullable', 'boolean'],
            'optimize_for_aeo' => ['nullable', 'boolean'],
        ];
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
