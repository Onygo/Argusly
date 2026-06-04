<?php

namespace App\Services;

class WebhookEventCatalog
{
    /**
     * @return array<string, string>
     */
    public function events(): array
    {
        return [
            'signal.created' => 'A new intelligence signal was created.',
            'signal.resolved' => 'An intelligence signal was resolved.',
            'visibility.run.completed' => 'An AI Visibility run completed.',
            'visibility.run.failed' => 'An AI Visibility run failed.',
            'briefing.approved' => 'A briefing was approved.',
            'agent.action.completed' => 'An agentic action completed.',
            'agent.action.failed' => 'An agentic action failed.',
            'report.snapshot.created' => 'A report snapshot was generated.',
        ];
    }

    public function exists(string $event): bool
    {
        return array_key_exists($event, $this->events());
    }
}
