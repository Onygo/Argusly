<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class IngestAnalyticsEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_destination_id' => ['nullable', 'uuid'],
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_type' => ['required', 'string', 'max:40'],
            'events.*.article_identifier' => ['nullable', 'string', 'max:255'],
            'events.*.page_url' => ['required', 'url', 'max:2048'],
            'events.*.timestamp' => ['required', 'date'],
            'events.*.session_id' => ['nullable', 'string', 'max:128'],
            'events.*.visitor_id' => ['nullable', 'string', 'max:128'],
            'events.*.meta' => ['nullable', 'array'],
        ];
    }
}
