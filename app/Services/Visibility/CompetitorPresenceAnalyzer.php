<?php

namespace App\Services\Visibility;

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\VisibilityCompetitorSnapshot;
use App\Models\VisibilityProviderRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CompetitorPresenceAnalyzer
{
    /**
     * @return array{competitor_presence_score: int, competitor_mentions: int, competitors: array<int, array{name: string, mentions: int, score: int}>}
     */
    public function analyze(VisibilityProviderRun $run, Brand $brand): array
    {
        $answer = (string) $run->normalized_answer;
        $competitors = Competitor::query()
            ->where('account_id', $brand->account_id)
            ->where('brand_id', $brand->id)
            ->active()
            ->orderBy('name')
            ->get();

        $rows = $competitors
            ->map(function (Competitor $competitor) use ($answer, $run): array {
                $mentions = $this->mentions($answer, $competitor->name);
                $score = min(100, $mentions * 35);

                if ($mentions > 0) {
                    VisibilityCompetitorSnapshot::query()->create([
                        'account_id' => $run->account_id,
                        'brand_id' => $run->brand_id,
                        'competitor_id' => $competitor->id,
                        'visibility_check_id' => $run->visibility_check_id,
                        'provider' => $run->provider,
                        'competitor_name' => $competitor->name,
                        'mentions_count' => $mentions,
                        'presence_score' => $score,
                        'captured_at' => $run->captured_at,
                        'metadata_json' => [
                            'provider_run_id' => $run->id,
                            'model' => $run->model,
                        ],
                    ]);
                }

                return [
                    'name' => $competitor->name,
                    'mentions' => $mentions,
                    'score' => $score,
                ];
            })
            ->filter(fn (array $row): bool => $row['mentions'] > 0)
            ->values();

        $mentions = $rows->sum('mentions');

        return [
            'competitor_presence_score' => min(100, (int) $rows->sum('score')),
            'competitor_mentions' => $mentions,
            'competitors' => $rows->all(),
        ];
    }

    private function mentions(string $answer, string $name): int
    {
        if (trim($answer) === '' || trim($name) === '') {
            return 0;
        }

        return substr_count(Str::lower($answer), Str::lower($name));
    }
}
