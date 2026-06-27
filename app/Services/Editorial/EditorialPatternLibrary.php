<?php

namespace App\Services\Editorial;

use Illuminate\Support\Str;

class EditorialPatternLibrary
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function patterns(): array
    {
        return [
            [
                'key' => 'problem_to_discovery',
                'name' => 'Problem to Discovery',
                'when_to_use' => 'Use when the reader has a practical problem but likely does not yet understand the hidden cause or decision behind it.',
                'article_movement' => 'Start from the visible problem, uncover the less obvious cause, test the reader assumption, then arrive at a practical discovery and action.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['observed friction', 'specific operational cause', 'reader decision moment'],
                'heading_guidance' => 'Use headings that reveal what the reader discovers next, not generic steps.',
                'rhythm_guidance' => 'Alternate problem evidence, interpretation, and practical discovery moments.',
                'avoid' => ['Do not over-explain the obvious problem.', 'Avoid a predictable problem-solution template.'],
            ],
            [
                'key' => 'prediction_to_evidence',
                'name' => 'Prediction to Evidence',
                'when_to_use' => 'Use when the topic contains future-facing claims, market change, forecasts, or strategic bets that need proof.',
                'article_movement' => 'State the prediction, show the signals behind it, test counter-signals, then translate the evidence into a practical decision.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'high',
                'required_human_signals' => ['trend signal', 'counter-signal', 'evidence boundary'],
                'heading_guidance' => 'Make headings specific to the signal or implication being tested.',
                'rhythm_guidance' => 'Move from claim to signal to caveat to practical implication.',
                'avoid' => ['Avoid unsupported forecasts.', 'Do not use vague future-of language.'],
            ],
            [
                'key' => 'myth_to_reality',
                'name' => 'Myth to Reality',
                'when_to_use' => 'Use when readers hold a common misconception, simplified belief, or vendor-shaped assumption about the topic.',
                'article_movement' => 'Name the myth, explain why it feels true, show the reality, then give the reader a better operating model.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['common misconception', 'real-world constraint', 'better decision rule'],
                'heading_guidance' => 'Use contrastive headings that make the misconception and reality explicit.',
                'rhythm_guidance' => 'Use short myth statements followed by evidence-led reality checks.',
                'avoid' => ['Do not create strawman myths.', 'Avoid smug or overly contrarian phrasing.'],
            ],
            [
                'key' => 'field_observation',
                'name' => 'Field Observation',
                'when_to_use' => 'Use when research findings, support conversations, sales calls, usage data, or practitioner experience are available.',
                'article_movement' => 'Open with an observed pattern, unpack what it reveals, connect it to decisions, then show what teams should do differently.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'low',
                'required_human_signals' => ['observed behavior', 'context of observation', 'practical implication'],
                'heading_guidance' => 'Headings should sound like field notes with clear implications.',
                'rhythm_guidance' => 'Keep a measured cadence: observation, interpretation, recommendation.',
                'avoid' => ['Do not pretend the observation is universal.', 'Avoid anonymized anecdote without a lesson.'],
            ],
            [
                'key' => 'case_study',
                'name' => 'Case Study',
                'when_to_use' => 'Use when there is a concrete customer, project, internal example, before-after result, or implementation story.',
                'article_movement' => 'Set the context, define the constraint, show the intervention, describe the outcome, then extract transferable lessons.',
                'suitable_intents' => ['commercial', 'transactional'],
                'risk_of_ai_fingerprint' => 'low',
                'required_human_signals' => ['specific actor', 'constraint', 'outcome or learning'],
                'heading_guidance' => 'Use headings around the situation, constraint, action, and transferable lesson.',
                'rhythm_guidance' => 'Let the story carry momentum, then pause for practical interpretation.',
                'avoid' => ['Do not invent customer details.', 'Avoid turning the article into a sales proof page.'],
            ],
            [
                'key' => 'timeline',
                'name' => 'Timeline',
                'when_to_use' => 'Use when chronology matters: launches, regulation, maturity stages, migration, or changing market behavior.',
                'article_movement' => 'Move through the sequence of change, explain what shifted at each stage, then show the current decision point.',
                'suitable_intents' => ['informational'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['dated event or phase', 'cause of change', 'current implication'],
                'heading_guidance' => 'Use phase-specific headings rather than numbered history labels.',
                'rhythm_guidance' => 'Keep each phase concise: what changed, why it mattered, what it means now.',
                'avoid' => ['Do not list history without interpretation.', 'Avoid chronology if time does not change the decision.'],
            ],
            [
                'key' => 'decision_guide',
                'name' => 'Decision Guide',
                'when_to_use' => 'Use when the reader must choose a path, vendor, workflow, threshold, or implementation approach.',
                'article_movement' => 'Define the decision, name the criteria, compare tradeoffs, recommend a path, and clarify the next action.',
                'suitable_intents' => ['commercial', 'transactional'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['selection criteria', 'tradeoff', 'recommendation boundary'],
                'heading_guidance' => 'Headings should name decisions, criteria, and tradeoffs directly.',
                'rhythm_guidance' => 'Use compact criteria blocks followed by consultative recommendations.',
                'avoid' => ['Do not become a generic checklist.', 'Avoid pretending one answer fits every reader.'],
            ],
            [
                'key' => 'contrarian_view',
                'name' => 'Contrarian View',
                'when_to_use' => 'Use when the brief has a strong point of view that challenges market consensus or shallow best practice.',
                'article_movement' => 'State the accepted view, explain what it misses, prove the alternative view, then make the safer recommendation.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'high',
                'required_human_signals' => ['market consensus', 'evidence of limitation', 'credible alternative'],
                'heading_guidance' => 'Use headings that challenge assumptions without sounding clickbait.',
                'rhythm_guidance' => 'Balance assertion with proof, caveat, and practical restraint.',
                'avoid' => ['Do not be contrarian for style.', 'Avoid absolute claims without evidence.'],
            ],
            [
                'key' => 'question_driven',
                'name' => 'Question Driven',
                'when_to_use' => 'Use when the topic is best answered through a sequence of reader questions or answer-engine style subquestions.',
                'article_movement' => 'Answer the core question, then progress through the follow-up questions that change reader understanding.',
                'suitable_intents' => ['informational'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['real reader question', 'answer boundary', 'follow-up implication'],
                'heading_guidance' => 'Use natural question headings only where the section genuinely answers that question.',
                'rhythm_guidance' => 'Keep answers direct, then add nuance and practical consequence.',
                'avoid' => ['Do not create an FAQ dump.', 'Avoid obvious questions with thin answers.'],
            ],
            [
                'key' => 'framework_analysis',
                'name' => 'Framework Analysis',
                'when_to_use' => 'Use when the topic needs a reusable model, rubric, operating framework, or diagnostic lens.',
                'article_movement' => 'Introduce the framework, define its parts, apply it to the topic, then show how readers can use it.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['named dimension', 'application rule', 'practical example'],
                'heading_guidance' => 'Headings should name framework dimensions and what they diagnose.',
                'rhythm_guidance' => 'Move between model explanation and applied judgment.',
                'avoid' => ['Do not invent a decorative acronym.', 'Avoid abstract framework language without examples.'],
            ],
            [
                'key' => 'comparison',
                'name' => 'Comparison',
                'when_to_use' => 'Use when readers need to compare alternatives, approaches, categories, vendors, or tradeoffs.',
                'article_movement' => 'Define the alternatives, compare on meaningful criteria, show where each wins, then recommend by use case.',
                'suitable_intents' => ['commercial', 'transactional'],
                'risk_of_ai_fingerprint' => 'medium',
                'required_human_signals' => ['comparison axis', 'tradeoff', 'use-case fit'],
                'heading_guidance' => 'Headings should name comparison criteria and use-case differences.',
                'rhythm_guidance' => 'Use balanced comparison passages with short recommendation moments.',
                'avoid' => ['Do not compare superficial features.', 'Avoid fake neutrality when the evidence supports a recommendation.'],
            ],
            [
                'key' => 'investigation',
                'name' => 'Investigation',
                'when_to_use' => 'Use when the topic asks why something is happening, why performance changed, or what explains a hidden pattern.',
                'article_movement' => 'Pose the investigation, examine the signals, rule out weak explanations, then identify the most useful explanation.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'low',
                'required_human_signals' => ['investigative question', 'signal', 'ruled-out explanation'],
                'heading_guidance' => 'Use evidence-led headings that show the line of inquiry.',
                'rhythm_guidance' => 'Build suspense through question, evidence, inference, and payoff.',
                'avoid' => ['Do not imply certainty where evidence is limited.', 'Avoid mystery framing without payoff.'],
            ],
            [
                'key' => 'trend_analysis',
                'name' => 'Trend Analysis',
                'when_to_use' => 'Use when the topic is about a pattern over time, market momentum, adoption behavior, or changing expectations.',
                'article_movement' => 'Name the trend, show signals, explain causes, test what is durable, then give a strategic response.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'high',
                'required_human_signals' => ['trend signal', 'cause', 'durability test'],
                'heading_guidance' => 'Headings should identify trend signals and strategic implications.',
                'rhythm_guidance' => 'Move from signal to cause to implication, with caveats before recommendations.',
                'avoid' => ['Do not hype the trend.', 'Avoid mistaking one data point for a trend.'],
            ],
            [
                'key' => 'lessons_learned',
                'name' => 'Lessons Learned',
                'when_to_use' => 'Use when the article can extract practical lessons from implementation, mistakes, experiments, or repeated observations.',
                'article_movement' => 'Set the experience context, name the lessons, explain why each lesson matters, then show what to change next time.',
                'suitable_intents' => ['informational', 'commercial'],
                'risk_of_ai_fingerprint' => 'low',
                'required_human_signals' => ['experience source', 'mistake or surprise', 'changed practice'],
                'heading_guidance' => 'Headings should name each lesson as a practical insight, not as Lesson 1 or Takeaway 1.',
                'rhythm_guidance' => 'Use compact lesson blocks with specific before-after implications.',
                'avoid' => ['Do not invent experience.', 'Avoid generic life-lesson phrasing.'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{primary:array<string,mixed>,secondary:?array<string,mixed>,scores:array<string,int>}
     */
    public function select(array $context): array
    {
        $scored = collect($this->patterns())
            ->map(function (array $pattern) use ($context): array {
                return $pattern + ['score' => $this->score($pattern, $context)];
            })
            ->sortByDesc('score')
            ->values();

        $primary = $this->withoutScore($scored->first() ?? $this->patterns()[0]);
        $secondaryCandidate = $scored
            ->skip(1)
            ->first(fn (array $pattern): bool => (int) $pattern['score'] >= max(25, (int) ($scored->first()['score'] ?? 0) - 14));

        return [
            'primary' => $primary,
            'secondary' => $secondaryCandidate ? $this->withoutScore($secondaryCandidate) : null,
            'scores' => $scored
                ->mapWithKeys(fn (array $pattern): array => [(string) $pattern['key'] => (int) $pattern['score']])
                ->all(),
        ];
    }

    /**
     * @param array<string,mixed> $pattern
     * @param array<string,mixed> $context
     */
    private function score(array $pattern, array $context): int
    {
        $topic = $this->normalizedText($context);
        $intent = $this->normalize((string) ($context['search_intent'] ?? ''));
        $funnelStage = $this->normalize((string) ($context['funnel_stage'] ?? ''));
        $researchInsights = (array) ($context['research_insights'] ?? []);
        $previousArticles = (array) ($context['previous_related_articles'] ?? []);
        $keyPoints = (array) ($context['key_points'] ?? []);

        $score = 0;
        if ($intent !== '' && in_array($intent, (array) $pattern['suitable_intents'], true)) {
            $score += 22;
        }

        $score += match ($pattern['key']) {
            'decision_guide' => $this->hasAny($topic, ['choose', 'select', 'decision', 'vendor', 'criteria', 'buy', 'purchase', 'implementation']) || in_array($intent, ['commercial', 'transactional'], true) || $funnelStage === 'decision' ? 35 : 0,
            'comparison' => $this->hasAny($topic, [' vs ', 'versus', 'compare', 'comparison', 'alternative', 'best', 'tool', 'platform']) ? 38 : 0,
            'case_study' => $this->hasAny($topic, ['case study', 'customer', 'client', 'example', 'before after', 'implementation story']) ? 42 : 0,
            'timeline' => $this->hasAny($topic, ['timeline', 'history', 'evolution', 'roadmap', 'migration', 'phase', 'maturity']) ? 38 : 0,
            'prediction_to_evidence' => $this->hasAny($topic, ['prediction', 'forecast', 'future', 'next year', 'will change', 'outlook']) ? 40 : 0,
            'trend_analysis' => $this->hasAny($topic, ['trend', 'trends', 'market shift', 'adoption', 'momentum', 'emerging']) ? 40 : 0,
            'myth_to_reality' => $this->hasAny($topic, ['myth', 'misconception', 'truth', 'reality', 'wrong', 'mistake']) ? 38 : 0,
            'contrarian_view' => $this->hasAny($topic, ['contrarian', 'unpopular', 'why not', 'overrated', 'wrong about', 'against']) ? 40 : 0,
            'question_driven' => str_contains((string) ($context['title'] ?? ''), '?') || $this->hasAny($topic, ['what is', 'how to', 'why', 'when should', 'can you']) ? 34 : 0,
            'framework_analysis' => $this->hasAny($topic, ['framework', 'model', 'rubric', 'method', 'system', 'strategy', 'playbook']) ? 34 : 0,
            'investigation' => $this->hasAny($topic, ['why', 'investigate', 'analysis', 'root cause', 'what explains', 'decline', 'drop']) ? 36 : 0,
            'lessons_learned' => $this->hasAny($topic, ['lessons', 'learned', 'mistakes', 'what we learned', 'experiment']) ? 39 : 0,
            'field_observation' => $researchInsights !== [] ? 32 : 0,
            'problem_to_discovery' => $this->hasAny($topic, ['problem', 'challenge', 'fix', 'improve', 'issue']) ? 30 : 0,
            default => 0,
        };

        if ($researchInsights !== [] && in_array($pattern['key'], ['field_observation', 'investigation', 'prediction_to_evidence', 'trend_analysis'], true)) {
            $score += 16;
        }

        if ($previousArticles !== [] && in_array($pattern['key'], ['framework_analysis', 'problem_to_discovery', 'field_observation'], true)) {
            $score += 8;
        }

        if ($keyPoints !== [] && in_array($pattern['key'], ['framework_analysis', 'decision_guide', 'lessons_learned'], true)) {
            $score += 7;
        }

        return $score;
    }

    /**
     * @param array<string,mixed> $pattern
     * @return array<string,mixed>
     */
    private function withoutScore(array $pattern): array
    {
        unset($pattern['score']);

        return $pattern;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function normalizedText(array $context): string
    {
        return $this->normalize(implode(' ', array_filter([
            (string) ($context['title'] ?? ''),
            (string) ($context['topic'] ?? ''),
            (string) ($context['primary_keyword'] ?? ''),
            implode(' ', (array) ($context['secondary_keywords'] ?? [])),
            (string) ($context['unique_angle'] ?? ''),
            (string) ($context['notes'] ?? ''),
        ])));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/\s+/', ' ')->trim()->value();
    }

    /**
     * @param array<int,string> $needles
     */
    private function hasAny(string $haystack, array $needles): bool
    {
        return collect($needles)->contains(fn (string $needle): bool => str_contains($haystack, $needle));
    }
}
