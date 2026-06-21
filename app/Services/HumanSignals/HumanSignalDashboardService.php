<?php

namespace App\Services\HumanSignals;

use App\Models\HumanSignal;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class HumanSignalDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public function forWorkspace(?Workspace $workspace): array
    {
        if (! $workspace) {
            return [
                'count' => 0,
                'top' => collect(),
                'latest' => collect(),
                'highest_confidence' => collect(),
            ];
        }

        $base = HumanSignal::query()
            ->with('insights')
            ->where('workspace_id', (string) $workspace->id)
            ->active();

        return [
            'count' => (clone $base)->count(),
            'top' => $this->rows((clone $base)->orderByDesc('metadata_json->quality->human_signal_score')->orderByDesc('confidence_score')->limit(4)->get()),
            'latest' => $this->rows((clone $base)->latest('detected_at')->limit(4)->get()),
            'highest_confidence' => $this->rows((clone $base)->orderByDesc('confidence_score')->limit(4)->get()),
        ];
    }

    /**
     * @param Collection<int,HumanSignal> $signals
     * @return Collection<int,array<string,mixed>>
     */
    private function rows(Collection $signals): Collection
    {
        return $signals->map(fn (HumanSignal $signal): array => [
            'id' => (string) $signal->id,
            'type' => (string) ($signal->type?->value ?? $signal->type),
            'title' => (string) $signal->title,
            'observation' => (string) $signal->observation,
            'impact' => (string) $signal->impact,
            'confidence_score' => (float) $signal->confidence_score,
            'human_signal_score' => (float) data_get($signal->metadata_json, 'quality.human_signal_score', $signal->confidence_score),
            'insight' => $signal->insights->first()?->insight,
        ])->values();
    }
}
