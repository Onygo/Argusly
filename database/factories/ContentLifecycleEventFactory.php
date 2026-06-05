<?php

namespace Database\Factories;

use App\Enums\ContentLifecycleStatus;
use App\Models\Content;
use App\Models\ContentLifecycleEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContentLifecycleEvent>
 */
class ContentLifecycleEventFactory extends Factory
{
    protected $model = ContentLifecycleEvent::class;

    public function definition(): array
    {
        $stages = ContentLifecycleStatus::canonicalStages();
        $fromStageIndex = fake()->numberBetween(0, count($stages) - 2);
        $toStageIndex = $fromStageIndex + 1;

        return [
            'content_id' => Content::factory(),
            'from_stage' => $stages[$fromStageIndex]->value,
            'to_stage' => $stages[$toStageIndex]->value,
            'event_type' => ContentLifecycleEvent::TYPE_TRANSITION,
            'user_id' => User::factory(),
            'actor_type' => ContentLifecycleEvent::ACTOR_USER,
            'notes' => fake()->optional(0.3)->sentence(),
            'metadata' => null,
        ];
    }

    public function transition(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => ContentLifecycleEvent::TYPE_TRANSITION,
        ]);
    }

    public function approval(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => ContentLifecycleEvent::TYPE_APPROVAL,
            'from_stage' => ContentLifecycleStatus::REVIEW->value,
            'to_stage' => ContentLifecycleStatus::APPROVED->value,
        ]);
    }

    public function rejection(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => ContentLifecycleEvent::TYPE_REJECTION,
            'from_stage' => ContentLifecycleStatus::REVIEW->value,
            'to_stage' => ContentLifecycleStatus::DRAFT->value,
            'metadata' => [
                'rejection_reason' => fake()->sentence(),
            ],
        ]);
    }

    public function assignment(): static
    {
        return $this->state(function (array $attributes) {
            $assignee = User::factory()->create();

            return [
                'event_type' => ContentLifecycleEvent::TYPE_ASSIGNMENT,
                'from_stage' => $attributes['to_stage'] ?? ContentLifecycleStatus::DRAFT->value,
                'to_stage' => $attributes['to_stage'] ?? ContentLifecycleStatus::DRAFT->value,
                'metadata' => [
                    'assignee_id' => $assignee->id,
                    'assignee_name' => $assignee->name,
                ],
            ];
        });
    }

    public function reviewerAssignment(): static
    {
        return $this->state(function (array $attributes) {
            $reviewer = User::factory()->create();

            return [
                'event_type' => ContentLifecycleEvent::TYPE_REVIEWER_ASSIGNMENT,
                'metadata' => [
                    'reviewer_id' => $reviewer->id,
                    'reviewer_name' => $reviewer->name,
                ],
            ];
        });
    }

    public function comment(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => ContentLifecycleEvent::TYPE_COMMENT,
            'notes' => fake()->paragraph(),
        ]);
    }

    public function systemAction(): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_type' => ContentLifecycleEvent::ACTOR_SYSTEM,
            'user_id' => null,
        ]);
    }

    public function automationAction(): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_type' => ContentLifecycleEvent::ACTOR_AUTOMATION,
            'user_id' => null,
        ]);
    }

    public function forContent(Content $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content_id' => $content->id,
        ]);
    }

    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'actor_type' => ContentLifecycleEvent::ACTOR_USER,
        ]);
    }
}
