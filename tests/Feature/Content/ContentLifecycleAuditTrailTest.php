<?php

use App\Enums\ContentLifecycleStatus;
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

function createAuditContent(array $attributes = []): Content
{
    return Content::create(array_merge([
        'workspace_id' => test()->workspace->id,
        'title' => 'Audit Test Content',
        'type' => 'article',
        'status' => 'draft',
        'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ], $attributes));
}

describe('Event Recording', function () {
    it('records transition events', function () {
        $content = createAuditContent();

        $this->service->transition($content, ContentLifecycleStatus::REVIEW, $this->user, 'Moving to review');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event)->not->toBeNull();
        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_TRANSITION);
        expect($event->from_stage)->toBe(ContentLifecycleStatus::DRAFT->value);
        expect($event->to_stage)->toBe(ContentLifecycleStatus::REVIEW->value);
        expect($event->user_id)->toBe($this->user->id);
        expect($event->actor_type)->toBe(ContentLifecycleEvent::ACTOR_USER);
        expect($event->notes)->toBe('Moving to review');
    });

    it('records approval events', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $this->service->approve($content, $this->user, 'Great work!');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_APPROVAL);
        expect($event->to_stage)->toBe(ContentLifecycleStatus::APPROVED->value);
        expect($event->notes)->toBe('Great work!');
    });

    it('records rejection events with reason in metadata', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $this->service->reject($content, $this->user, 'Missing introduction');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_REJECTION);
        expect($event->metadata)->toHaveKey('rejection_reason');
        expect($event->metadata['rejection_reason'])->toBe('Missing introduction');
    });

    it('records assignment events with assignee info', function () {
        $content = createAuditContent();
        $assignee = User::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'John Writer',
        ]);

        $this->service->assign($content, $assignee, $this->user);

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_ASSIGNMENT);
        expect($event->metadata['assignee_id'])->toBe($assignee->id);
        expect($event->metadata['assignee_name'])->toBe('John Writer');
    });

    it('records reviewer assignment events', function () {
        $content = createAuditContent();
        $reviewer = User::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Jane Reviewer',
        ]);

        $this->service->setReviewer($content, $reviewer, $this->user);

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_REVIEWER_ASSIGNMENT);
        expect($event->metadata['reviewer_id'])->toBe($reviewer->id);
        expect($event->metadata['reviewer_name'])->toBe('Jane Reviewer');
    });

    it('records due date change events', function () {
        $content = createAuditContent();
        $dueDate = now()->addWeek();

        $this->service->setDueDate($content, $dueDate, $this->user, 'Priority deadline');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->event_type)->toBe(ContentLifecycleEvent::TYPE_DUE_DATE_CHANGE);
        expect($event->metadata)->toHaveKey('new_due_date');
        expect($event->notes)->toBe('Priority deadline');
    });
});

describe('Event Metadata', function () {
    it('stores correct metadata structure', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $this->service->reject($content, $this->user, 'Needs work', 'Additional feedback');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->metadata)->toBeArray();
        expect($event->metadata)->toHaveKey('rejection_reason');
    });

    it('records system actions without user', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value]);

        // Mark refresh needed without user (system action)
        $this->service->markRefreshNeeded($content, null, 'Automated decay detection');

        $event = ContentLifecycleEvent::where('content_id', $content->id)->first();

        expect($event->user_id)->toBeNull();
        expect($event->actor_type)->toBe(ContentLifecycleEvent::ACTOR_SYSTEM);
    });
});

describe('Event Queries', function () {
    it('retrieves events for specific content', function () {
        $content1 = createAuditContent();
        $content2 = createAuditContent(['title' => 'Other Content']);

        $this->service->transition($content1, ContentLifecycleStatus::REVIEW, $this->user);
        $this->service->transition($content2, ContentLifecycleStatus::REVIEW, $this->user);

        $events = ContentLifecycleEvent::forContent($content1->id)->get();

        expect($events)->toHaveCount(1);
        expect($events->first()->content_id)->toBe($content1->id);
    });

    it('filters events by type', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);
        $reviewer = User::factory()->create(['organization_id' => $this->org->id]);

        $this->service->setReviewer($content, $reviewer, $this->user);
        $this->service->approve($content, $this->user);

        $approvalEvents = ContentLifecycleEvent::ofType(ContentLifecycleEvent::TYPE_APPROVAL)->get();

        expect($approvalEvents)->toHaveCount(1);
    });

    it('orders events by created_at descending', function () {
        $content = createAuditContent();

        $this->service->transition($content, ContentLifecycleStatus::REVIEW, $this->user);

        // Small delay to ensure different timestamps
        usleep(10000);

        $content = $content->fresh();
        $this->service->approve($content, $this->user);

        $events = $content->lifecycleEvents;

        expect($events->first()->event_type)->toBe(ContentLifecycleEvent::TYPE_APPROVAL);
    });
});

describe('Event Cascade Delete', function () {
    it('deletes events when content is deleted', function () {
        $content = createAuditContent();

        $this->service->transition($content, ContentLifecycleStatus::REVIEW, $this->user);

        $contentId = $content->id;
        $content->forceDelete();

        $events = ContentLifecycleEvent::where('content_id', $contentId)->get();

        expect($events)->toHaveCount(0);
    });
});

describe('ContentLifecycleEvent Model', function () {
    it('creates event via factory method', function () {
        $content = createAuditContent();

        $event = ContentLifecycleEvent::recordTransition(
            $content,
            ContentLifecycleStatus::IDEA,
            ContentLifecycleStatus::BRIEF,
            $this->user,
            'Starting brief phase'
        );

        expect($event)->toBeInstanceOf(ContentLifecycleEvent::class);
        expect($event->exists)->toBeTrue();
    });

    it('has correct relationships', function () {
        $content = createAuditContent();

        $event = ContentLifecycleEvent::recordTransition(
            $content,
            ContentLifecycleStatus::DRAFT,
            ContentLifecycleStatus::REVIEW,
            $this->user
        );

        expect($event->content->id)->toBe($content->id);
        expect($event->user->id)->toBe($this->user->id);
    });

    it('has helper methods for event type checking', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $approvalEvent = ContentLifecycleEvent::recordApproval($content, $this->user);
        $rejectionEvent = ContentLifecycleEvent::recordRejection($content, $this->user, 'Needs work');

        expect($approvalEvent->isApproval())->toBeTrue();
        expect($approvalEvent->isRejection())->toBeFalse();
        expect($rejectionEvent->isRejection())->toBeTrue();
    });

    it('provides stage enums from string values', function () {
        $content = createAuditContent();

        $event = ContentLifecycleEvent::recordTransition(
            $content,
            ContentLifecycleStatus::DRAFT,
            ContentLifecycleStatus::REVIEW,
            $this->user
        );

        expect($event->fromStageEnum())->toBe(ContentLifecycleStatus::DRAFT);
        expect($event->toStageEnum())->toBe(ContentLifecycleStatus::REVIEW);
    });
});

describe('Content Model Lifecycle Methods', function () {
    it('provides lifecycleEvents relationship', function () {
        $content = createAuditContent();

        $this->service->transition($content, ContentLifecycleStatus::REVIEW, $this->user);
        $this->service->approve($content->fresh(), $this->user);

        $events = $content->fresh()->lifecycleEvents;

        expect($events)->toHaveCount(2);
    });

    it('provides lifecycleStageEnum helper', function () {
        $content = createAuditContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        expect($content->lifecycleStageEnum())->toBe(ContentLifecycleStatus::REVIEW);
    });

    it('provides isOverdue helper', function () {
        $overdueContent = createAuditContent(['due_at' => now()->subDay()]);
        $futureContent = createAuditContent(['due_at' => now()->addDay()]);
        $noDueContent = createAuditContent(['due_at' => null]);

        expect($overdueContent->isOverdue())->toBeTrue();
        expect($futureContent->isOverdue())->toBeFalse();
        expect($noDueContent->isOverdue())->toBeFalse();
    });

    it('considers published content not overdue', function () {
        $content = createAuditContent([
            'lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value,
            'due_at' => now()->subDay(),
        ]);

        expect($content->isOverdue())->toBeFalse();
    });
});
