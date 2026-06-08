<?php

namespace App\Services;

use App\Models\EarlyAccessSignup;

class PilotQualificationService
{
    public function score(array|EarlyAccessSignup $application): int
    {
        $data = $application instanceof EarlyAccessSignup ? $application->getAttributes() : $application;
        $score = 20;

        $score += $this->has($data, 'company_name') ? 10 : 0;
        $score += $this->has($data, 'website') ? 10 : 0;
        $score += $this->has($data, 'job_title') ? 8 : 0;
        $score += $this->has($data, 'phone') ? 5 : 0;
        $score += $this->has($data, 'industry') ? 8 : 0;
        $score += $this->has($data, 'country') ? 4 : 0;
        $score += $this->useCaseScore((string) ($data['use_case'] ?? $data['notes'] ?? ''));
        $score += $this->companySizeScore((string) ($data['company_size'] ?? ''));
        $score += $this->priorityScore((string) ($data['priority'] ?? ''));

        return max(0, min(100, $score));
    }

    public function label(int|string|null $score): string
    {
        $score = is_numeric($score) ? (int) $score : 0;

        return match (true) {
            $score >= 75 => 'Hot',
            $score >= 45 => 'Warm',
            default => 'Cold',
        };
    }

    public function scoreAndLabel(array|EarlyAccessSignup $application): array
    {
        $score = $this->score($application);

        return [
            'score' => $score,
            'label' => $this->label($score),
        ];
    }

    private function has(array $data, string $key): bool
    {
        return trim((string) ($data[$key] ?? '')) !== '';
    }

    private function useCaseScore(string $value): int
    {
        $length = mb_strlen(trim($value));
        $score = $length >= 80 ? 18 : ($length >= 30 ? 10 : 0);
        $value = mb_strtolower($value);

        foreach (['workflow', 'governance', 'seo', 'publishing', 'content', 'automation', 'team', 'sites'] as $signal) {
            if (str_contains($value, $signal)) {
                $score += 2;
            }
        }

        return min(25, $score);
    }

    private function companySizeScore(string $value): int
    {
        $normalized = mb_strtolower(trim($value));

        if ($normalized === '') {
            return 0;
        }

        if (preg_match('/\b(51|100|101|250|500|1000|enterprise|large)\b/', $normalized) === 1) {
            return 10;
        }

        if (preg_match('/\b(11|25|26|50|medium|mid)\b/', $normalized) === 1) {
            return 7;
        }

        return 4;
    }

    private function priorityScore(string $value): int
    {
        return match (mb_strtolower(trim($value))) {
            'high' => 10,
            'medium' => 5,
            'low' => 0,
            default => 0,
        };
    }
}
