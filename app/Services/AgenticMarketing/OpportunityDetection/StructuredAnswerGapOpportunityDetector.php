<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;

class StructuredAnswerGapOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    use DetectsObjectiveContent;

    public function detect(AgenticMarketingObjective $objective): array
    {
        return $this->contentQuery($objective, [
            'id',
            'workspace_id',
            'client_site_id',
            'title',
            'language',
            'aeo_score',
            'answer_block_score',
            'answer_block_visibility',
            'answer_block_render_mode',
            'answer_block_generation_persisted_count',
        ])
            ->where(function ($query): void {
                $query->whereNull('answer_block_generation_persisted_count')
                    ->orWhere('answer_block_generation_persisted_count', 0)
                    ->orWhere('answer_block_score', '<', 65)
                    ->orWhere('answer_block_visibility', Content::ANSWER_BLOCK_VISIBILITY_HIDDEN);
            })
            ->orderBy('answer_block_score')
            ->limit(50)
            ->get()
            ->map(fn (Content $content): DetectedOpportunity => $this->opportunity($content))
            ->all();
    }

    private function opportunity(Content $content): DetectedOpportunity
    {
        $answerScore = (int) ($content->answer_block_score ?? 0);
        $aeoScore = (int) ($content->aeo_score ?? 0);
        $persistedCount = (int) ($content->answer_block_generation_persisted_count ?? 0);

        return new DetectedOpportunity(
            title: 'Add structured answer coverage to ' . (string) $content->title,
            type: AgenticMarketingOpportunityType::AnswerCoverage,
            priorityScore: $this->scoreFromSignals(
                50,
                $persistedCount === 0 ? 16 : 0,
                $answerScore > 0 ? max(0, 65 - $answerScore) / 2 : 10,
                $aeoScore > 0 ? max(0, 65 - $aeoScore) / 3 : 0,
            ),
            payload: [
                'detector' => 'structured_answer_gaps',
                'content_id' => (string) $content->id,
                'signals' => [
                    'aeo_score' => $aeoScore,
                    'answer_block_score' => $answerScore,
                    'answer_block_visibility' => (string) ($content->answer_block_visibility ?? ''),
                    'answer_block_render_mode' => (string) ($content->answer_block_render_mode ?? ''),
                    'answer_block_generation_persisted_count' => $persistedCount,
                ],
            ],
            contentId: (string) $content->id,
        );
    }
}
