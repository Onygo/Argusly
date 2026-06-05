<?php

namespace App\Services\Content;

use App\Models\ContentImprovementEvent;
use App\Models\ContentImprovementRun;
use Illuminate\Support\Facades\Log;

class ContentImprovementLifecycleLogger
{
    /**
     * @param array<string,mixed> $payload
     */
    public function record(ContentImprovementRun $run, string $eventType, string $message, array $payload = []): void
    {
        Log::channel('stack')->info('content_improvement.' . strtolower($eventType), [
            'run_id' => (string) $run->id,
            'content_id' => (string) $run->content_id,
            'type' => (string) $run->type,
            'status' => (string) $run->status,
        ] + $payload);

        ContentImprovementEvent::query()->create([
            'content_improvement_run_id' => (string) $run->id,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => $payload,
        ]);
    }
}
