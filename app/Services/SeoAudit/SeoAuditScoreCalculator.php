<?php

namespace App\Services\SeoAudit;

use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use Illuminate\Support\Collection;

class SeoAuditScoreCalculator
{
    /**
     * @var array<string,int>
     */
    private const DEDUCTIONS = [
        'title_missing' => 25,
        'title_long' => 10,
        'meta_description_missing' => 15,
        'canonical_missing' => 10,
        'h1_missing' => 15,
        'broken_links_detected' => 10,
    ];

    /**
     * @param  array<int,string>  $issueCodes
     */
    public function scoreFromIssueCodes(array $issueCodes): int
    {
        $score = 100;

        foreach (array_values(array_unique($issueCodes)) as $code) {
            $score -= self::DEDUCTIONS[$code] ?? 0;
        }

        return max(0, min(100, $score));
    }

    /**
     * @param  Collection<int,SeoAuditIssue>  $issues
     */
    public function scoreForPage(SeoAuditPage $page, Collection $issues): int
    {
        $codes = $issues
            ->where('seo_audit_page_id', $page->id)
            ->pluck('code')
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->map(fn ($code): string => (string) $code)
            ->values()
            ->all();

        return $this->scoreFromIssueCodes($codes);
    }

    /**
     * @param  Collection<int,SeoAuditPage>  $pages
     * @param  Collection<int,SeoAuditIssue>  $issues
     */
    public function scoreForAudit(Collection $pages, Collection $issues): float
    {
        if ($pages->isEmpty()) {
            return 0.0;
        }

        $total = $pages
            ->map(fn (SeoAuditPage $page): int => $this->scoreForPage($page, $issues))
            ->sum();

        return round($total / $pages->count(), 1);
    }

    /**
     * @return array{label:string,key:string,classes:string}
     */
    public function levelForScore(float $score): array
    {
        if ($score >= 80) {
            return [
                'label' => 'Good',
                'key' => 'good',
                'classes' => 'text-success',
            ];
        }

        if ($score >= 60) {
            return [
                'label' => 'Needs improvement',
                'key' => 'needs_improvement',
                'classes' => 'text-amber-600',
            ];
        }

        return [
            'label' => 'Poor',
            'key' => 'poor',
            'classes' => 'text-rose-600',
        ];
    }

    /**
     * @return array<string,int>
     */
    public function deductions(): array
    {
        return self::DEDUCTIONS;
    }
}
