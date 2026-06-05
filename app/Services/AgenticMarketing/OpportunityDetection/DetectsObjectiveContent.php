<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\SupportedLanguage;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;
use Illuminate\Database\Eloquent\Builder;

trait DetectsObjectiveContent
{
    /**
     * @param array<int,string> $columns
     */
    private function contentQuery(AgenticMarketingObjective $objective, array $columns = ['*']): Builder
    {
        return Content::query()
            ->where('workspace_id', $objective->workspace_id)
            ->when($objective->client_site_id, fn (Builder $query): Builder => $query->where('client_site_id', $objective->client_site_id))
            ->when($objective->locale, function (Builder $query) use ($objective): Builder {
                $locale = SupportedLanguage::fromStringOrDefault((string) $objective->locale)->value;

                return $query->where('language', $locale);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'archived');
            })
            ->select($columns);
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return trim((string) $value);
    }

    private function scoreFromSignals(int $base, mixed ...$signals): int
    {
        $score = $base;

        foreach ($signals as $signal) {
            if (is_bool($signal)) {
                $score += $signal ? 8 : 0;
            } elseif (is_numeric($signal)) {
                $score += (int) $signal;
            }
        }

        return max(1, min(100, $score));
    }
}
