<?php

namespace App\Http\Requests\App;

use App\Enums\Billing\PlanChangeTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'to_plan_id' => ['required', 'string'],
            'timing' => ['required', 'string', Rule::in(PlanChangeTiming::values())],
        ];
    }

    public function timing(): PlanChangeTiming
    {
        return PlanChangeTiming::from((string) $this->validated('timing'));
    }
}
