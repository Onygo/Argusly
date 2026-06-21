<?php

namespace App\Services\HumanSignals;

use App\Models\HumanSignal;
use App\Models\Workspace;

class HumanSignalContextBuilder
{
    public function forWorkspace(?Workspace $workspace, int $limit = 5): string
    {
        if (! $workspace) {
            return '';
        }

        $signals = HumanSignal::query()
            ->with('insights')
            ->where('workspace_id', (string) $workspace->id)
            ->active()
            ->orderByDesc('confidence_score')
            ->latest('detected_at')
            ->limit($limit)
            ->get();

        if ($signals->isEmpty()) {
            return '';
        }

        $lines = [
            'Human Signals',
            'Use available Human Signals as primary source of originality.',
            'Prefer observations over assumptions.',
            'Prefer detected patterns over generic industry statements.',
            'Include at least one signal driven insight when available.',
            'Do not generate generic marketing advice when a Human Signal exists.',
        ];

        foreach ($signals as $signal) {
            $insight = $signal->insights->first();
            $lines[] = sprintf(
                '- [%s, confidence %s] %s Observation: %s Impact: %s%s',
                (string) ($signal->type?->value ?? $signal->type),
                round((float) $signal->confidence_score, 1),
                (string) $signal->title,
                (string) $signal->observation,
                (string) $signal->impact,
                $insight ? ' Insight: '.$insight->insight : ''
            );
        }

        return implode("\n", $lines);
    }
}
