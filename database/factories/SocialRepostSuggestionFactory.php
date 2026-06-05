<?php

namespace Database\Factories;

use App\Enums\SocialRepostSuggestionStatus;
use App\Models\SocialPublication;
use App\Models\SocialRepostSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialRepostSuggestion>
 */
class SocialRepostSuggestionFactory extends Factory
{
    protected $model = SocialRepostSuggestion::class;

    public function definition(): array
    {
        $publication = SocialPublication::factory()->create();

        return [
            'organization_id' => $publication->organization_id,
            'workspace_id' => $publication->workspace_id,
            'social_publication_id' => $publication->id,
            'campaign_id' => $publication->campaign_id,
            'platform' => $publication->platform,
            'status' => SocialRepostSuggestionStatus::PROPOSED,
            'suggested_for' => now()->addWeek(),
            'reason_code' => 'evergreen_follow_up',
            'reason' => 'Publication has reusable campaign context.',
            'suggested_angle' => [],
            'performance_snapshot' => [],
        ];
    }
}
