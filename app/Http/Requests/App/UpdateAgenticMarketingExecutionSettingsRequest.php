<?php

namespace App\Http\Requests\App;

use App\Models\AgenticMarketingExecutionSetting;
use App\Services\AgenticMarketing\AutonomyPresetService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgenticMarketingExecutionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allowed_site_ids' => $this->normalizedUuidArray('allowed_site_ids'),
            'allowed_publishing_destination_ids' => $this->normalizedUuidArray('allowed_publishing_destination_ids'),
            'autonomous_publication_enabled' => $this->boolean('autonomous_publication_enabled'),
            'autonomous_refresh_enabled' => $this->boolean('autonomous_refresh_enabled'),
            'autonomous_internal_linking_enabled' => $this->boolean('autonomous_internal_linking_enabled'),
            'autonomous_brief_generation_enabled' => $this->boolean('autonomous_brief_generation_enabled'),
            'autonomous_chained_plans_enabled' => $this->boolean('autonomous_chained_plans_enabled'),
            'require_approval_for_new_pages' => $this->boolean('require_approval_for_new_pages', true),
            'require_approval_for_external_publication' => $this->boolean('require_approval_for_external_publication', true),
            'notification_email_enabled' => $this->boolean('notification_email_enabled', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'agentic_execution_mode' => ['required', 'string', Rule::in(AgenticMarketingExecutionSetting::modes())],
            'autonomy_preset' => ['nullable', 'string', Rule::in(app(AutonomyPresetService::class)->keys())],
            'autonomous_publication_enabled' => ['nullable', 'boolean'],
            'autonomous_refresh_enabled' => ['nullable', 'boolean'],
            'autonomous_internal_linking_enabled' => ['nullable', 'boolean'],
            'autonomous_brief_generation_enabled' => ['nullable', 'boolean'],
            'autonomous_chained_plans_enabled' => ['nullable', 'boolean'],
            'max_autonomous_actions_per_day' => ['required', 'integer', 'min:1', 'max:100'],
            'max_autonomous_credits_per_month' => ['required', 'integer', 'min:1', 'max:1000000'],
            'require_approval_above_priority_score' => ['required', 'integer', 'min:0', 'max:100'],
            'require_approval_for_new_pages' => ['nullable', 'boolean'],
            'require_approval_for_external_publication' => ['nullable', 'boolean'],
            'allowed_site_ids' => ['nullable', 'array'],
            'allowed_site_ids.*' => ['uuid'],
            'allowed_publishing_destination_ids' => ['nullable', 'array'],
            'allowed_publishing_destination_ids.*' => ['uuid'],
            'notification_email_enabled' => ['nullable', 'boolean'],
            'autonomous_opt_in_confirmation' => ['exclude_unless:agentic_execution_mode,autonomous', 'accepted'],
        ];
    }

    private function normalizedUuidArray(string $key): array
    {
        return collect((array) $this->input($key, []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
