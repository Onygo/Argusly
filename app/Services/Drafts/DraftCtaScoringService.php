<?php

namespace App\Services\Drafts;

use App\Models\Draft;
use Illuminate\Support\Str;

class DraftCtaScoringService
{
    /**
     * @return array{
     *   score:int,
     *   band_label:string,
     *   explanation:string,
     *   improvements:array<int,string>,
     *   cta_excerpt:?string,
     *   funnel_stage:string,
     *   signals:array<string,mixed>
     * }
     */
    public function evaluateDraft(Draft $draft): array
    {
        $draft->loadMissing('brief');

        return $this->evaluateContent(
            html: (string) ($draft->content_html ?? ''),
            context: [
                'title' => (string) ($draft->title ?? ''),
                'primary_keyword' => (string) ($draft->brief?->primary_keyword ?? ''),
                'secondary_keywords' => (array) ($draft->brief?->secondary_keywords ?? []),
                'call_to_action' => (string) ($draft->brief?->call_to_action ?? ''),
                'target_audience' => (string) ($draft->brief?->target_audience ?: $draft->brief?->audience ?: ''),
                'funnel_stage' => (string) ($draft->brief?->funnel_stage ?? ''),
                'search_intent' => (string) ($draft->brief?->search_intent ?? ''),
            ],
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array{
     *   score:int,
     *   band_label:string,
     *   explanation:string,
     *   improvements:array<int,string>,
     *   cta_excerpt:?string,
     *   funnel_stage:string,
     *   signals:array<string,mixed>
     * }
     */
    public function evaluateContent(string $html, array $context = []): array
    {
        $plainText = $this->normalizeText(strip_tags($html));
        $segments = $this->segmentsFromHtml($html);
        $funnelStage = $this->normalizeFunnelStage((string) ($context['funnel_stage'] ?? ''));
        $ctaExcerpt = $this->ctaExcerpt($segments);

        if ($plainText === '') {
            return [
                'score' => 0,
                'band_label' => $this->bandLabel(0),
                'explanation' => 'The draft has no readable body content, so there is no CTA to evaluate.',
                'improvements' => [
                    'Add a closing section that tells the reader what to do next.',
                ],
                'cta_excerpt' => null,
                'funnel_stage' => $funnelStage,
                'signals' => [
                    'cta_detected' => false,
                    'stage_fit' => 0,
                ],
            ];
        }

        $candidateText = $ctaExcerpt ?? $this->tailExcerpt($segments);
        $actionSignals = $this->actionSignalCount($candidateText);
        $hardPressureSignals = $this->hardPressureSignalCount($candidateText);
        $softPressureSignals = $this->softPressureSignalCount($candidateText);
        $clarity = $this->clarityScore($candidateText, $actionSignals);
        $actionability = $this->actionabilityScore($candidateText, $actionSignals);
        $relevance = $this->relevanceScore($candidateText, $plainText, $context);
        $specificity = $this->specificityScore($candidateText);
        $audienceFit = $this->audienceFitScore($candidateText, $context);
        $stageFit = $this->stageFitScore($candidateText, $funnelStage, $softPressureSignals, $hardPressureSignals);
        $ctaDetected = $this->ctaDetected($candidateText, $actionSignals, $softPressureSignals, $hardPressureSignals);

        $score = $ctaDetected
            ? (int) round(
                ($clarity * 0.24)
                + ($actionability * 0.22)
                + ($relevance * 0.18)
                + ($specificity * 0.16)
                + ($audienceFit * 0.10)
                + ($stageFit * 0.10)
            )
            : $this->weakOrMissingCtaScore($candidateText, $relevance, $softPressureSignals, $hardPressureSignals);

        $score = max(0, min(100, $score));
        $bandLabel = $this->bandLabel($score);

        return [
            'score' => $score,
            'band_label' => $bandLabel,
            'explanation' => $this->buildExplanation(
                score: $score,
                funnelStage: $funnelStage,
                ctaDetected: $ctaDetected,
                candidateText: $candidateText,
                clarity: $clarity,
                actionability: $actionability,
                relevance: $relevance,
                specificity: $specificity,
                stageFit: $stageFit,
            ),
            'improvements' => $this->buildImprovements($score, $funnelStage, $candidateText),
            'cta_excerpt' => $ctaExcerpt,
            'funnel_stage' => $funnelStage,
            'signals' => [
                'cta_detected' => $ctaDetected,
                'clarity' => $clarity,
                'actionability' => $actionability,
                'relevance' => $relevance,
                'specificity' => $specificity,
                'audience_fit' => $audienceFit,
                'stage_fit' => $stageFit,
                'soft_pressure_signals' => $softPressureSignals,
                'hard_pressure_signals' => $hardPressureSignals,
                'action_signals' => $actionSignals,
            ],
        ];
    }

    /**
     * @param array<int,string> $segments
     */
    public function excerptFromHtml(string $html, int $tailSegments = 3): ?string
    {
        return $this->ctaExcerpt($this->segmentsFromHtml($html), $tailSegments);
    }

    /**
     * @param array<int,string> $segments
     */
    private function ctaExcerpt(array $segments, int $tailSegments = 3): ?string
    {
        if ($segments === []) {
            return null;
        }

        $tail = array_slice($segments, -1 * max(2, $tailSegments));
        $ctaSegments = collect($segments)
            ->filter(fn (string $segment): bool => $this->actionSignalCount($segment) > 0 || $this->softPressureSignalCount($segment) > 0 || $this->hardPressureSignalCount($segment) > 0)
            ->values()
            ->all();

        $combined = collect(array_merge($tail, $ctaSegments))
            ->map(fn (string $segment): string => $this->normalizeText($segment))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($combined === []) {
            return null;
        }

        return implode("\n\n", array_slice($combined, -1 * max(2, $tailSegments)));
    }

    /**
     * @param array<int,string> $segments
     */
    private function tailExcerpt(array $segments): string
    {
        return implode(' ', array_slice($segments, -3));
    }

    /**
     * @param array<int,string> $segments
     */
    private function segmentsFromHtml(string $html): array
    {
        $normalized = preg_replace('/<\/(p|li|h[1-6]|div|section|article|blockquote)>/iu', "\n", $html) ?? $html;
        $normalized = preg_replace('/<br\s*\/?>/iu', "\n", $normalized) ?? $normalized;
        $plain = strip_tags($normalized);

        return collect(preg_split('/\n+/u', $plain) ?: [])
            ->map(fn (string $segment): string => $this->normalizeText($segment))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeFunnelStage(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->toString();

        return match ($normalized) {
            'awareness', 'top_of_funnel', 'tof' => 'awareness',
            'decision', 'bottom_of_funnel', 'bof', 'conversion' => 'decision',
            default => 'consideration',
        };
    }

    private function ctaDetected(string $candidateText, int $actionSignals, int $softPressureSignals, int $hardPressureSignals): bool
    {
        if ($candidateText === '') {
            return false;
        }

        return $actionSignals > 0 || $softPressureSignals > 0 || $hardPressureSignals > 0;
    }

    private function clarityScore(string $candidateText, int $actionSignals): int
    {
        if ($candidateText === '') {
            return 0;
        }

        $score = 20;
        $score += min(30, $actionSignals * 12);
        $score += preg_match('/\b(je|jij|u|your|you)\b/iu', $candidateText) ? 10 : 0;
        $score += preg_match('/\?/', $candidateText) ? 8 : 0;
        $score += preg_match('/\b(plan|boek|vraag|start|begin|use|plan|book|request|get started|zet|gebruik|verken|bepaal)\b/iu', $candidateText) ? 18 : 0;

        return max(0, min(100, $score));
    }

    private function actionabilityScore(string $candidateText, int $actionSignals): int
    {
        if ($candidateText === '') {
            return 0;
        }

        $score = 10 + min(30, $actionSignals * 10);
        $score += preg_match('/\b(gesprek|meeting|demo|trial|pilot|workflow|checklist|assessment|audit|download|contact)\b/iu', $candidateText) ? 25 : 0;
        $score += preg_match('/\b(vandaag|today|nu|now|eerste stap|next step)\b/iu', $candidateText) ? 12 : 0;
        $score += preg_match('/\b(plan|boek|vraag|start|begin|gebruik|zet|bespreek|schedule|book|request|download|use)\b/iu', $candidateText) ? 18 : 0;

        return max(0, min(100, $score));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function relevanceScore(string $candidateText, string $plainText, array $context): int
    {
        if ($candidateText === '') {
            return 0;
        }

        $keywordPool = collect([
            $context['title'] ?? null,
            $context['primary_keyword'] ?? null,
            $context['call_to_action'] ?? null,
            $context['target_audience'] ?? null,
        ])
            ->merge((array) ($context['secondary_keywords'] ?? []))
            ->filter()
            ->map(fn (mixed $value): string => (string) $value)
            ->implode(' ');

        $candidateTokens = $this->meaningfulTokens($candidateText);
        $contextTokens = $this->meaningfulTokens($keywordPool . ' ' . $plainText);

        if ($candidateTokens === [] || $contextTokens === []) {
            return 35;
        }

        $overlap = count(array_intersect($candidateTokens, $contextTokens));
        $score = 35 + min(45, $overlap * 9);

        if (preg_match('/\b(checklist|workflow|pilot|proces|process|automation|automatiseren|telecom)\b/iu', $candidateText)) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }

    private function specificityScore(string $candidateText): int
    {
        if ($candidateText === '') {
            return 0;
        }

        $score = 20;
        $score += preg_match('/\b\d+\b/u', $candidateText) ? 22 : 0;
        $score += preg_match('/\b(dag|dagen|week|weken|maand|maanden|day|days|week|weeks|month|months)\b/iu', $candidateText) ? 14 : 0;
        $score += preg_match('/\b(cto|operations|team|pilot|workflow|checklist|roadmap|plan)\b/iu', $candidateText) ? 22 : 0;
        $score += preg_match('/\b(kernproces|proces|workflow|pilot|team|gesprek|checklist)\b/iu', $candidateText) ? 12 : 0;

        return max(0, min(100, $score));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function audienceFitScore(string $candidateText, array $context): int
    {
        if ($candidateText === '') {
            return 0;
        }

        $targetAudience = $this->normalizeText((string) ($context['target_audience'] ?? ''));
        $score = 60;

        if ($targetAudience !== '') {
            $audienceTokens = $this->meaningfulTokens($targetAudience);
            $candidateTokens = $this->meaningfulTokens($candidateText);
            $overlap = count(array_intersect($audienceTokens, $candidateTokens));
            $score += min(20, $overlap * 10);
        }

        if (preg_match('/\b(team|cto|operations|marketing|leadership|buyers|engineers)\b/iu', $candidateText)) {
            $score += 15;
        }

        return max(0, min(100, $score));
    }

    private function stageFitScore(string $candidateText, string $funnelStage, int $softPressureSignals, int $hardPressureSignals): int
    {
        if ($candidateText === '') {
            return 0;
        }

        return match ($funnelStage) {
            'awareness' => $hardPressureSignals > 0
                ? 55
                : ($softPressureSignals > 0 ? 92 : 72),
            'decision' => $hardPressureSignals > 0
                ? 95
                : ($softPressureSignals > 0 ? 64 : 52),
            default => $hardPressureSignals > 0
                ? 78
                : ($softPressureSignals > 0 ? 94 : 70),
        };
    }

    private function weakOrMissingCtaScore(string $candidateText, int $relevance, int $softPressureSignals, int $hardPressureSignals): int
    {
        if ($candidateText === '') {
            return 0;
        }

        $score = 8;
        $score += min(10, $softPressureSignals * 4);
        $score += min(8, $hardPressureSignals * 4);
        $score += min(8, (int) round($relevance / 20));

        return max(0, min(40, $score));
    }

    private function actionSignalCount(string $text): int
    {
        $patterns = [
            '/\b(plan|boek|vraag|start|begin|gebruik|zet|verken|bepaal|bespreek|download|ontdek|neem contact op|plan dan|schedule|book|request|start|begin|use|take|explore|contact|download|subscribe|get started|learn more)\b/iu',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $matches = preg_match_all($pattern, $text);
            $count += $matches === false ? 0 : $matches;
        }

        return $count;
    }

    private function softPressureSignalCount(string $text): int
    {
        $patterns = [
            '/\b(plan een gesprek|kort gesprek|verken|bespreek|gebruik dit artikel|checklist|eerste stap|pilot|determine|review|use this article|first step)\b/iu',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $matches = preg_match_all($pattern, $text);
            $count += $matches === false ? 0 : $matches;
        }

        return $count;
    }

    private function hardPressureSignalCount(string $text): int
    {
        $patterns = [
            '/\b(book a demo|request a demo|schedule a demo|talk to sales|buy now|start your trial|start free|contact us|get started)\b/iu',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $matches = preg_match_all($pattern, $text);
            $count += $matches === false ? 0 : $matches;
        }

        return $count;
    }

    /**
     * @return array<int,string>
     */
    private function meaningfulTokens(string $text): array
    {
        $text = mb_strtolower($this->normalizeText($text));
        $tokens = preg_split('/[^[:alnum:]]+/u', $text) ?: [];

        return collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->reject(fn (string $token): bool => in_array($token, [
                'deze', 'this', 'that', 'with', 'voor', 'door', 'from', 'your', 'jouw', 'dein', 'about',
                'article', 'draft', 'content', 'their', 'naar', 'over', 'waar', 'when', 'what', 'which',
            ], true))
            ->unique()
            ->values()
            ->all();
    }

    private function bandLabel(int $score): string
    {
        return match (true) {
            $score <= 20 => '0-20: no real CTA',
            $score <= 40 => '21-40: vague or weak CTA',
            $score <= 60 => '41-60: present but generic CTA',
            $score <= 80 => '61-80: clear, relevant, actionable CTA',
            default => '81-100: highly compelling, specific, well-matched CTA',
        };
    }

    private function buildExplanation(
        int $score,
        string $funnelStage,
        bool $ctaDetected,
        string $candidateText,
        int $clarity,
        int $actionability,
        int $relevance,
        int $specificity,
        int $stageFit,
    ): string {
        if (! $ctaDetected) {
            return 'The draft does not end with a clear next step for the reader, so the CTA remains weak or missing.';
        }

        $bandPrefix = match (true) {
            $score <= 40 => 'The CTA is present but still vague.',
            $score <= 60 => 'The CTA is visible but generic.',
            $score <= 80 => 'The CTA is clear, relevant, and actionable.',
            default => 'The CTA is highly specific, compelling, and well matched to the article.',
        };

        $stageSentence = match ($funnelStage) {
            'awareness' => 'It stays appropriately soft for awareness-stage content instead of pushing a hard sale.',
            'decision' => 'It provides a strong next step that suits decision-stage intent.',
            default => 'It fits consideration-stage content by giving the reader a concrete next step without forcing a hard-sales ask.',
        };

        $detailParts = [];
        if ($specificity >= 70 && preg_match('/\b\d+\b/u', $candidateText)) {
            $detailParts[] = 'The CTA includes concrete details that make the next step feel real.';
        }
        if ($clarity >= 70 && $actionability >= 70) {
            $detailParts[] = 'The reader can tell exactly what to do next.';
        }
        if ($relevance >= 65) {
            $detailParts[] = 'The ask stays tied to the article topic rather than feeling bolted on.';
        }
        if ($stageFit < 70) {
            $detailParts[] = 'The CTA pressure is somewhat mismatched to the funnel stage.';
        }

        return trim(implode(' ', array_filter([
            $bandPrefix,
            $stageSentence,
            implode(' ', $detailParts),
        ])));
    }

    /**
     * @return array<int,string>
     */
    private function buildImprovements(int $score, string $funnelStage, string $candidateText): array
    {
        if ($score <= 20) {
            return [
                'Add one closing paragraph that tells the reader exactly what to do next.',
                'Tie the CTA directly to the article topic instead of ending on information alone.',
                $funnelStage === 'decision'
                    ? 'Use a more explicit conversion step such as booking a demo or requesting contact.'
                    : 'Use a soft but concrete next step that matches the reader\'s stage.',
            ];
        }

        if ($score <= 40) {
            return [
                'Replace vague encouragement with one explicit next action.',
                'Add concrete details such as a timeframe, owner, or output.',
                'Make the CTA feel more connected to the article\'s core workflow or problem.',
            ];
        }

        if ($score <= 60) {
            return [
                'Sharpen the CTA into one unmistakable next step.',
                'Add one specific detail that reduces ambiguity for the reader.',
                'Make the closing action feel more tailored to this audience and funnel stage.',
            ];
        }

        if ($score <= 80) {
            return [
                'Add one friction-reducing detail such as who should lead the next step or what to prepare.',
                'Keep the CTA close to the conclusion so it remains easy to act on.',
            ];
        }

        return [
            'Keep the CTA specific and tightly coupled to the article topic.',
        ];
    }
}
