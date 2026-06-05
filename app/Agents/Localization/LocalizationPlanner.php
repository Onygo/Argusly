<?php

namespace App\Agents\Localization;

class LocalizationPlanner
{
    /**
     * @param array<int,array<string,mixed>> $issues
     * @return array<int,array<string,mixed>>
     */
    public function plan(array $issues): array
    {
        return collect($issues)
            ->flatMap(fn (array $issue): array => (array) ($issue['actions'] ?? []))
            ->map(function (array $action): array {
                $label = trim((string) ($action['label'] ?? 'Review'));

                return array_merge($action, [
                    'title' => $label,
                    'description' => trim((string) ($action['description'] ?? '')),
                ]);
            })
            ->unique(fn (array $action): string => implode(':', [
                (string) ($action['type'] ?? ''),
                (string) ($action['target_locale'] ?? ''),
                (string) ($action['content_id'] ?? ''),
                (string) ($action['draft_id'] ?? ''),
            ]))
            ->values()
            ->all();
    }
}
