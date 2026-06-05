<?php

use App\Enums\ContentLifecycleStatus;
use App\Exceptions\InvalidLifecycleTransitionException;
use App\Models\Content;
use App\Models\ContentLifecycleEvent;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\ContentLifecycleTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org', 'slug' => 'test-org', 'status' => 'active']);
    $this->workspace = Workspace::create(['name' => 'Test Workspace', 'organization_id' => $this->org->id]);
    $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
    $this->service = app(ContentLifecycleTransitionService::class);
});

function createContent(array $attributes = []): Content
{
    return Content::create(array_merge([
        'workspace_id' => test()->workspace->id,
        'title' => 'Test Content',
        'type' => 'article',
        'status' => 'draft',
        'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ], $attributes));
}

describe('transition', function () {
    it('transitions content to valid target stage', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        $result = $this->service->transition($content, ContentLifecycleStatus::REVIEW, $this->user);

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::REVIEW);
        expect($result->status)->toBe('review'); // Legacy status synced
    });

    it('throws exception for invalid transition', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        expect(fn () => $this->service->transition($content, ContentLifecycleStatus::PUBLISHED, $this->user))
            ->toThrow(InvalidLifecycleTransitionException::class);
    });

    it('allows same stage transition (no-op)', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        $result = $this->service->transition($content, ContentLifecycleStatus::DRAFT, $this->user);

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::DRAFT);
    });

    it('records audit event for transition', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        $this->service->transition($content, ContentLifecycleStatus::REVIEW, $this->user, 'Test notes');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event)->not->toBeNull();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_TRANSITION);
        expect($event->from_stage)->toBe(ContentLifecycleStatus::DRAFT->value);
        expect($event->to_stage)->toBe(ContentLifecycleStatus::REVIEW->value);
        expect($event->user_id)->toBe($this->user->id);
        expect($event->notes)->toBe('Test notes');
    });

    it('syncs legacy status field', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $result = $this->service->transition($content, ContentLifecycleStatus::APPROVED, $this->user);

        expect($result->status)->toBe('ready_to_deliver');
    });
});

describe('approve', function () {
    it('transitions to approved and sets approval timestamps', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $result = $this->service->approve($content, $this->user, 'Great content!');

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::APPROVED);
        expect($result->approved_at)->not->toBeNull();
        expect($result->approved_by)->toBe($this->user->id);
    });

    it('clears rejection fields on approval', function () {
        $content = createContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'rejected_at' => now()->subDay(),
            'rejected_by' => $this->user->id,
            'rejection_reason' => 'Previous rejection',
        ]);

        $result = $this->service->approve($content, $this->user);

        expect($result->rejected_at)->toBeNull();
        expect($result->rejected_by)->toBeNull();
        expect($result->rejection_reason)->toBeNull();
    });

    it('records approval event', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $this->service->approve($content, $this->user);

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_APPROVAL);
        expect($event->to_stage)->toBe(ContentLifecycleStatus::APPROVED->value);
    });
});

describe('reject', function () {
    it('transitions to draft and sets rejection fields', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $result = $this->service->reject($content, $this->user, 'Needs more work', 'Additional notes');

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::DRAFT);
        expect($result->rejected_at)->not->toBeNull();
        expect($result->rejected_by)->toBe($this->user->id);
        expect($result->rejection_reason)->toBe('Needs more work');
    });

    it('clears approval fields on rejection', function () {
        $content = createContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'approved_at' => now()->subDay(),
            'approved_by' => $this->user->id,
        ]);

        $result = $this->service->reject($content, $this->user, 'Changes needed');

        expect($result->approved_at)->toBeNull();
        expect($result->approved_by)->toBeNull();
    });

    it('records rejection event with reason', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $this->service->reject($content, $this->user, 'Rejection reason');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_REJECTION);
        expect($event->metadata['rejection_reason'])->toBe('Rejection reason');
    });
});

describe('assign', function () {
    it('assigns content to user', function () {
        $content = createContent();
        $assignee = User::factory()->create(['organization_id' => $this->org->id]);

        $result = $this->service->assign($content, $assignee, $this->user, 'Please handle this');

        expect($result->assigned_user_id)->toBe($assignee->id);
    });

    it('records assignment event', function () {
        $content = createContent();
        $assignee = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'John Doe']);

        $this->service->assign($content, $assignee, $this->user);

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_ASSIGNMENT);
        expect($event->metadata['assignee_id'])->toBe($assignee->id);
        expect($event->metadata['assignee_name'])->toBe('John Doe');
    });
});

describe('setReviewer', function () {
    it('sets reviewer for content', function () {
        $content = createContent();
        $reviewer = User::factory()->create(['organization_id' => $this->org->id]);

        $result = $this->service->setReviewer($content, $reviewer, $this->user);

        expect($result->reviewer_user_id)->toBe($reviewer->id);
    });

    it('records reviewer assignment event', function () {
        $content = createContent();
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Reviewer']);

        $this->service->setReviewer($content, $reviewer, $this->user);

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_REVIEWER_ASSIGNMENT);
        expect($event->metadata['reviewer_name'])->toBe('Jane Reviewer');
    });
});

describe('sendToReview', function () {
    it('transitions to review stage', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        $result = $this->service->sendToReview($content, $this->user);

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::REVIEW);
    });

    it('sets reviewer when provided', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);
        $reviewer = User::factory()->create(['organization_id' => $this->org->id]);

        $result = $this->service->sendToReview($content, $this->user, $reviewer);

        expect($result->reviewer_user_id)->toBe($reviewer->id);
    });
});

describe('markRefreshNeeded', function () {
    it('transitions published content to refresh_needed', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value]);

        $result = $this->service->markRefreshNeeded($content, $this->user, 'Content is outdated');

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::REFRESH_NEEDED);
    });

    it('records transition event with reason', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value]);

        $this->service->markRefreshNeeded($content, $this->user, 'Statistics are outdated');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event->notes)->toBe('Statistics are outdated');
    });
});

describe('archive', function () {
    it('transitions content to archived', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value]);

        $result = $this->service->archive($content, $this->user, 'No longer relevant');

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::ARCHIVED);
    });
});

describe('setDueDate', function () {
    it('sets due date and records event', function () {
        $content = createContent();
        $dueDate = now()->addWeek();

        $result = $this->service->setDueDate($content, $dueDate, $this->user, 'Priority deadline');

        expect($result->due_at->toDateString())->toBe($dueDate->toDateString());

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_DUE_DATE_CHANGE);
    });

    it('can clear due date', function () {
        $content = createContent(['due_at' => now()->addWeek()]);

        $result = $this->service->setDueDate($content, null, $this->user);

        expect($result->due_at)->toBeNull();
    });
});

describe('canTransition', function () {
    it('returns true for valid transitions', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        expect($this->service->canTransition($content, ContentLifecycleStatus::REVIEW))->toBeTrue();
    });

    it('returns false for invalid transitions', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        expect($this->service->canTransition($content, ContentLifecycleStatus::PUBLISHED))->toBeFalse();
    });
});

describe('getAllowedTransitions', function () {
    it('returns allowed transitions for current stage', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        $transitions = $this->service->getAllowedTransitions($content);
        $values = array_map(fn ($t) => $t->value, $transitions);

        expect($values)->toContain('review');
        expect($values)->toContain('brief');
        expect($values)->toContain('archived');
    });
});

describe('userCanTransitionTo', function () {
    it('allows admin to transition to any valid stage', function () {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        expect($this->service->userCanTransitionTo($content, ContentLifecycleStatus::REVIEW, $admin))->toBeTrue();
    });

    it('allows editor to transition through stages', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        expect($this->service->userCanTransitionTo($content, ContentLifecycleStatus::REVIEW, $editor))->toBeTrue();
    });

    it('allows reviewer to approve content when assigned as reviewer', function () {
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'reviewer_user_id' => $reviewer->id,
        ]);

        expect($this->service->userCanTransitionTo($content, ContentLifecycleStatus::APPROVED, $reviewer))->toBeTrue();
    });

    it('prevents reviewer from approving when not assigned', function () {
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $otherReviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'reviewer_user_id' => $otherReviewer->id,
        ]);

        expect($this->service->userCanTransitionTo($content, ContentLifecycleStatus::APPROVED, $reviewer))->toBeFalse();
    });

    it('returns false for invalid transitions regardless of role', function () {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);

        expect($this->service->userCanTransitionTo($content, ContentLifecycleStatus::PUBLISHED, $admin))->toBeFalse();
    });
});

describe('markPublished', function () {
    it('transitions approved content to published', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::APPROVED->value]);

        $result = $this->service->markPublished($content, $this->user, 'Published successfully');

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::PUBLISHED);
    });
});

describe('markScheduled', function () {
    it('transitions approved content to scheduled', function () {
        $content = createContent(['lifecycle_stage' => ContentLifecycleStatus::APPROVED->value]);

        $result = $this->service->markScheduled($content, $this->user, 'Scheduled for next week');

        expect($result->lifecycle_stage)->toBe(ContentLifecycleStatus::SCHEDULED);
    });
});
