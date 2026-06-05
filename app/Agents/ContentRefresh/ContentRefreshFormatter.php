<?php

namespace App\Agents\ContentRefresh;

class ContentRefreshFormatter
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $scorecard
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function format(array $input, array $scorecard, array $plan): array
    {
        $score = (int) ($scorecard['refresh_score'] ?? 0);
        $urgency = (string) ($scorecard['urgency_level'] ?? 'low');
        $siteName = (string) ($input['site']?->name ?? 'the selected site');
        $locale = strtoupper((string) ($input['locale'] ?? 'N/A'));

        $summary = match ($urgency) {
            'high' => sprintf('Refresh score %d/100. This %s article on %s is a strong refresh candidate.', $score, $locale, $siteName),
            'medium' => sprintf('Refresh score %d/100. This %s article on %s has clear refresh opportunities.', $score, $locale, $siteName),
            default => sprintf('Refresh score %d/100. This %s article on %s is relatively current, with lighter refresh needs.', $score, $locale, $siteName),
        };

        $warnings = [];
        if (trim((string) ($input['html'] ?? '')) === '') {
            $warnings[] = [
                'title' => 'Body content is missing',
                'description' => 'The score is based mainly on metadata because no editable body snapshot was available.',
            ];
        }

        return [
            'summary' => $summary,
            'warnings' => $warnings,
            'refresh_score' => $score,
            'urgency_level' => $urgency,
            'reasons' => (array) ($plan['reasons'] ?? []),
            'suggested_actions' => (array) ($plan['suggested_actions'] ?? []),
        ];
    }
}
