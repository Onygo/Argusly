<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Models\OpportunitySignal;
use Illuminate\Support\Collection;

class RecommendedActionBuilder
{
    /**
     * @param Collection<int, OpportunitySignal> $signals
     * @return array<int, array<string,mixed>>
     */
    public function build(OpportunityCategory $category, Collection $signals): array
    {
        $topic = (string) ($signals->first()?->topic ?: 'this topic');
        $contentId = $signals->pluck('content_id')->filter()->first();

        return match ($category) {
            OpportunityCategory::CONTENT_GAP => [
                $this->action('create_supporting_content', "Create supporting content for {$topic}", 'Fill missing coverage before competitors own the query path.', ['topic' => $topic]),
                $this->action('link_to_cluster', 'Attach to a content cluster', 'Use internal links to connect the new asset to a pillar page.', ['content_cluster_id' => $signals->pluck('content_cluster_id')->filter()->first()]),
            ],
            OpportunityCategory::TREND_OPPORTUNITY => [
                $this->action('create_timely_article', "Publish a timely angle on {$topic}", 'Search trend evidence suggests rising demand.', ['topic' => $topic]),
                $this->action('schedule_linkedin_variant', 'Create LinkedIn variants', 'Use social distribution to validate messaging momentum.', ['platform' => 'linkedin']),
            ],
            OpportunityCategory::REFRESH_OPPORTUNITY => [
                $this->action('refresh_existing_content', 'Refresh declining content', 'Update stale sections, examples, schema, and internal links.', ['content_id' => $contentId]),
                $this->action('add_answer_block', 'Add answer blocks', 'Improve extractability for AI and search answers.', ['content_id' => $contentId]),
            ],
            OpportunityCategory::COMPETITOR_MOVEMENT => [
                $this->action('create_competitive_response', "Respond to competitor movement on {$topic}", 'Competitor signals show active pressure or new coverage.', ['topic' => $topic]),
                $this->action('update_campaign_plan', 'Add this to a campaign', 'Coordinate content, distribution, and internal linking around the response.', []),
            ],
            OpportunityCategory::AI_VISIBILITY_OPPORTUNITY => [
                $this->action('improve_ai_citation_coverage', 'Improve AI citation coverage', 'Strengthen entity coverage, direct answers, and source clarity.', ['content_id' => $contentId, 'topic' => $topic]),
                $this->action('track_queries', 'Track related AI queries', 'Monitor whether citation and answer inclusion improve after changes.', ['topic' => $topic]),
            ],
            OpportunityCategory::BRAND_VISIBILITY => [
                $this->action('review_brand_visibility_evidence', "Review brand visibility evidence for {$topic}", 'Promoted Signal Intelligence evidence indicates a brand visibility movement.', ['topic' => $topic]),
                $this->action('strengthen_brand_entity_coverage', 'Strengthen brand entity coverage', 'Improve source clarity, owned references, and answer-ready brand positioning.', ['topic' => $topic]),
            ],
            OpportunityCategory::ENGAGEMENT_OPPORTUNITY => [
                $this->action('repurpose_high_engagement_topic', "Repurpose engagement around {$topic}", 'Engagement signals suggest audience interest worth extending.', ['topic' => $topic]),
                $this->action('create_repost_suggestion', 'Prepare repost or follow-up', 'Turn social momentum into a governed distribution action.', ['platform' => 'linkedin']),
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function action(string $type, string $label, string $rationale, array $payload): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'rationale' => $rationale,
            'payload' => array_filter($payload, fn (mixed $value): bool => filled($value)),
            'requires_approval' => true,
        ];
    }
}
