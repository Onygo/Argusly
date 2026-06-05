<?php

namespace App\Services\Drafts\Intelligence;

class DraftIntelligenceRubricRegistry
{
    public const VERSION = 'phase4.v1';

    /**
     * @return array<string,mixed>
     */
    public function metric(string $metric): array
    {
        return match ($metric) {
            'seo' => [
                'label' => 'SEO',
                'summary' => 'Evaluate natural keyword placement, metadata presence, and on-page search relevance.',
                'criteria' => [
                    'Primary keyword in title',
                    'Primary keyword in intro',
                    'Primary keyword in headings',
                    'Related term presence',
                    'Meta title present',
                    'Meta description present',
                    'No obvious keyword stuffing',
                    'Internal link presence when relevant',
                ],
                'improvement_goals' => [
                    'Strengthen natural keyword placement in title, intro, and headings.',
                    'Tighten meta title and description coverage.',
                    'Avoid keyword stuffing or robotic repetition.',
                ],
            ],
            'readability' => [
                'label' => 'Readability',
                'summary' => 'Evaluate sentence flow, paragraph density, scanability, and structural ease of reading.',
                'criteria' => [
                    'Sentence length control',
                    'Paragraph length control',
                    'Heading frequency',
                    'List presence where useful',
                    'Scanability',
                    'Dense block avoidance',
                    'Transition quality',
                ],
                'improvement_goals' => [
                    'Shorten dense paragraphs and overly long sentences.',
                    'Improve section scanning with headings or lists when helpful.',
                    'Strengthen transitions between ideas without changing meaning.',
                ],
            ],
            'cta' => [
                'label' => 'CTA',
                'summary' => 'Evaluate whether the article gives the reader a clear, relevant next step that matches funnel stage.',
                'criteria' => [
                    'CTA present',
                    'CTA near the end',
                    'Action verb presence',
                    'Specificity',
                    'Topic relevance',
                    'Audience fit',
                    'Funnel-stage fit',
                ],
                'improvement_goals' => [
                    'Make the next step explicit and easy to act on.',
                    'Keep the CTA relevant to the article topic and audience.',
                    'Match CTA strength to funnel stage instead of forcing hard sales language.',
                ],
            ],
            'headings' => [
                'label' => 'Headings',
                'summary' => 'Evaluate heading clarity, hierarchy, coverage, and descriptiveness.',
                'criteria' => [
                    'H1 present',
                    'Hierarchy consistency',
                    'Section coverage',
                    'Heading descriptiveness',
                    'Duplicate heading avoidance',
                    'Generic heading avoidance',
                ],
                'improvement_goals' => [
                    'Use a single clear H1 and consistent heading levels.',
                    'Make section headings descriptive and specific.',
                    'Avoid duplicated or generic heading labels.',
                ],
            ],
            'llm_visibility' => [
                'label' => 'LLM Visibility',
                'summary' => 'Evaluate whether the draft is easy for AI systems to extract, summarize, and cite accurately.',
                'criteria' => [
                    'Explicit answer presence',
                    'Question-to-answer alignment',
                    'Definitional clarity',
                    'Entity clarity',
                    'Concise authoritative passages',
                    'Summary block presence',
                    'Comparison or step-based framing',
                    'Structured lists and scannable sections',
                    'Low ambiguity',
                    'Strong intro and conclusion framing',
                ],
                'improvement_goals' => [
                    'Make the core answer explicit near the start of the article.',
                    'Add concise passages, summary blocks, or structured steps that are easy to extract.',
                    'Replace vague references with named entities and concrete recommendations.',
                ],
                'score_bands' => [
                    ['min' => 0, 'max' => 20, 'label' => '0-20: very poor extractability'],
                    ['min' => 21, 'max' => 40, 'label' => '21-40: weak AI extractability'],
                    ['min' => 41, 'max' => 60, 'label' => '41-60: moderate AI extractability'],
                    ['min' => 61, 'max' => 80, 'label' => '61-80: strong AI extractability'],
                    ['min' => 81, 'max' => 100, 'label' => '81-100: excellent AI extractability'],
                ],
            ],
            'brand_voice_fit' => [
                'label' => 'Brand Voice Fit',
                'summary' => 'Evaluate whether the draft matches the intended tone, positioning, terminology, and audience sophistication.',
                'criteria' => [
                    'Tone consistency',
                    'Formality fit',
                    'Audience sophistication fit',
                    'Approved terminology usage',
                    'Discouraged phrasing avoidance',
                    'Positioning and value proposition alignment',
                    'Consistency between intro, body, and CTA',
                ],
                'improvement_goals' => [
                    'Keep the article in one consistent voice from intro to CTA.',
                    'Use approved terminology when brand guidance exists.',
                    'Match the target audience without sounding generic or off-brand.',
                ],
            ],
            'conversion_fit' => [
                'label' => 'Conversion Fit',
                'summary' => 'Evaluate whether the article supports the intended next step for its funnel stage and content type.',
                'criteria' => [
                    'CTA fit to funnel stage',
                    'Next-step clarity',
                    'Decision-support clarity',
                    'Relevance of suggested action',
                    'Conversion path support',
                    'Alignment between article promise and next action',
                ],
                'improvement_goals' => [
                    'Make the next step explicit and matched to the article promise.',
                    'Support the CTA with enough context for the reader to act confidently.',
                    'Keep the conversion path clear without forcing a harder sell than the funnel stage needs.',
                ],
            ],
            'trust_evidence' => [
                'label' => 'Trust and Evidence',
                'summary' => 'Evaluate whether the content feels concrete, measured, credible, and grounded.',
                'criteria' => [
                    'Specificity',
                    'Concrete examples',
                    'Evidence-style framing',
                    'Balanced wording',
                    'Unsupported hype avoidance',
                    'Overclaim avoidance',
                    'Practical recommendation clarity',
                ],
                'improvement_goals' => [
                    'Replace vague claims with concrete framing.',
                    'Add examples or evidence-style support where useful.',
                    'Keep recommendations practical and measured instead of overclaimed.',
                ],
            ],
            'publish_readiness' => [
                'label' => 'Publish Readiness',
                'summary' => 'Evaluate whether the draft is coherent, complete, and ready to publish based on the full editorial quality profile.',
                'criteria' => [
                    'Critical elements present',
                    'No blocking weaknesses',
                    'Overall coherence',
                    'Metadata readiness',
                    'Conversion readiness',
                    'Trustworthiness',
                    'Structural completeness',
                ],
                'improvement_goals' => [
                    'Resolve blocking issues before publishing.',
                    'Raise the weakest quality metrics above minimum thresholds.',
                    'Keep the article coherent across SEO, readability, trust, and conversion.',
                ],
                'score_bands' => [
                    ['min' => 0, 'max' => 20, 'label' => '0-20: not ready to publish'],
                    ['min' => 21, 'max' => 40, 'label' => '21-40: major blockers remain'],
                    ['min' => 41, 'max' => 60, 'label' => '41-60: needs revision'],
                    ['min' => 61, 'max' => 80, 'label' => '61-80: nearly ready'],
                    ['min' => 81, 'max' => 100, 'label' => '81-100: ready to publish'],
                ],
            ],
            default => [
                'label' => ucfirst($metric),
                'summary' => '',
                'criteria' => [],
                'improvement_goals' => [],
            ],
        };
    }

    /**
     * @return array<int,array{min:int,max:int,label:string}>
     */
    public function scoreBands(): array
    {
        return [
            ['min' => 0, 'max' => 20, 'label' => '0-20: no real strength or critical issues'],
            ['min' => 21, 'max' => 40, 'label' => '21-40: weak or incomplete'],
            ['min' => 41, 'max' => 60, 'label' => '41-60: present but inconsistent'],
            ['min' => 61, 'max' => 80, 'label' => '61-80: clear, relevant, actionable'],
            ['min' => 81, 'max' => 100, 'label' => '81-100: highly effective and well matched'],
        ];
    }

    /**
     * @return array{min:int,max:int,label:string}
     */
    public function bandForScore(int $score, ?string $metric = null): array
    {
        $bands = (array) data_get($metric ? $this->metric($metric) : [], 'score_bands', $this->scoreBands());

        foreach ($bands as $band) {
            if ($score >= $band['min'] && $score <= $band['max']) {
                return $band;
            }
        }

        return ['min' => 0, 'max' => 100, 'label' => '0-100: unclassified'];
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return [
            'version' => self::VERSION,
            'metrics' => [
                'seo' => $this->metric('seo'),
                'readability' => $this->metric('readability'),
                'cta' => $this->metric('cta'),
                'headings' => $this->metric('headings'),
                'llm_visibility' => $this->metric('llm_visibility'),
                'brand_voice_fit' => $this->metric('brand_voice_fit'),
                'conversion_fit' => $this->metric('conversion_fit'),
                'trust_evidence' => $this->metric('trust_evidence'),
                'publish_readiness' => $this->metric('publish_readiness'),
            ],
            'score_bands' => $this->scoreBands(),
        ];
    }
}
