<?php

use App\Enums\ContentLifecycleStatus;
use App\Models\Content;
use App\Models\ContentLifecycleEvent;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org', 'slug' => 'test-org', 'status' => 'active']);
    $this->workspace = Workspace::create(['name' => 'Test Workspace', 'organization_id' => $this->org->id]);
});

function createLifecycleContent(array $attributes = []): Content
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

describe('Send to Review', function () {
    it('allows editor to send content to review', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.send-to-review', $content));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::REVIEW);
    });

    it('allows setting reviewer when sending to review', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.send-to-review', $content), [
                'reviewer_id' => $reviewer->id,
            ]);

        $response->assertRedirect();
        expect($content->fresh()->reviewer_user_id)->toBe($reviewer->id);
    });

    it('prevents viewer from sending to review', function () {
        $viewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'viewer']);
        $content = createLifecycleContent();

        $response = $this->actingAs($viewer)
            ->post(route('app.content.lifecycle.send-to-review', $content));

        $response->assertForbidden();
    });
});

describe('Approve Content', function () {
    it('allows reviewer to approve content when assigned', function () {
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'reviewer_user_id' => $reviewer->id,
        ]);

        $response = $this->actingAs($reviewer)
            ->post(route('app.content.lifecycle.approve', $content), [
                'notes' => 'Looks good!',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $content->refresh();
        expect($content->lifecycle_stage)->toBe(ContentLifecycleStatus::APPROVED);
        expect($content->approved_by)->toBe($reviewer->id);
        expect($content->approved_at)->not->toBeNull();
    });

    it('allows editor to approve content', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.approve', $content));

        $response->assertRedirect();
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::APPROVED);
    });

    it('allows admin to approve content', function () {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('app.content.lifecycle.approve', $content));

        $response->assertRedirect();
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::APPROVED);
    });

    it('prevents unassigned reviewer from approving', function () {
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $otherReviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'reviewer_user_id' => $otherReviewer->id,
        ]);

        $response = $this->actingAs($reviewer)
            ->post(route('app.content.lifecycle.approve', $content));

        $response->assertForbidden();
    });
});

describe('Reject Content', function () {
    it('requires rejection reason', function () {
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'reviewer_user_id' => $reviewer->id,
        ]);

        $response = $this->actingAs($reviewer)
            ->post(route('app.content.lifecycle.reject', $content), [
                // Missing reason
            ]);

        $response->assertSessionHasErrors('reason');
    });

    it('allows reviewer to reject content with reason', function () {
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            'reviewer_user_id' => $reviewer->id,
        ]);

        $response = $this->actingAs($reviewer)
            ->post(route('app.content.lifecycle.reject', $content), [
                'reason' => 'Needs more detail in the introduction',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $content->refresh();
        expect($content->lifecycle_stage)->toBe(ContentLifecycleStatus::DRAFT);
        expect($content->rejected_by)->toBe($reviewer->id);
        expect($content->rejection_reason)->toBe('Needs more detail in the introduction');
    });

    it('sends content back to draft on rejection', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.reject', $content), [
                'reason' => 'Missing key points',
            ]);

        $response->assertRedirect();
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::DRAFT);
    });
});

describe('Assign Content', function () {
    it('allows editor to assign content to user', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $assignee = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.assign', $content), [
                'assignee_id' => $assignee->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        expect($content->fresh()->assigned_user_id)->toBe($assignee->id);
    });

    it('prevents viewer from assigning content', function () {
        $viewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'viewer']);
        $assignee = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent();

        $response = $this->actingAs($viewer)
            ->post(route('app.content.lifecycle.assign', $content), [
                'assignee_id' => $assignee->id,
            ]);

        $response->assertForbidden();
    });
});

describe('Set Reviewer', function () {
    it('allows editor to set reviewer', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $reviewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'reviewer']);
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.set-reviewer', $content), [
                'reviewer_id' => $reviewer->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        expect($content->fresh()->reviewer_user_id)->toBe($reviewer->id);
    });
});

describe('Mark Refresh Needed', function () {
    it('allows marking published content for refresh', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.mark-refresh-needed', $content), [
                'reason' => 'Statistics are outdated',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::REFRESH_NEEDED);
    });
});

describe('Generic Transition', function () {
    it('allows valid stage transitions', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.transition', $content), [
                'target_stage' => 'review',
                'notes' => 'Ready for review',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::REVIEW);
    });

    it('rejects invalid stage transitions', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.transition', $content), [
                'target_stage' => 'published', // Invalid direct transition
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('transition');
    });

    it('validates target_stage is required', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.transition', $content), [
                // Missing target_stage
            ]);

        $response->assertSessionHasErrors('target_stage');
    });
});

describe('Lifecycle History', function () {
    it('displays lifecycle history for content', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent();

        // Create some events
        ContentLifecycleEvent::recordTransition(
            $content,
            ContentLifecycleStatus::IDEA,
            ContentLifecycleStatus::DRAFT,
            $editor
        );

        $response = $this->actingAs($editor)
            ->get(route('app.content.lifecycle.history', $content));

        $response->assertStatus(200);
        $response->assertViewIs('app.content.lifecycle.history');
    });

    it('allows viewer to see lifecycle history', function () {
        $viewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'viewer']);
        $content = createLifecycleContent();

        $response = $this->actingAs($viewer)
            ->get(route('app.content.lifecycle.history', $content));

        $response->assertStatus(200);
    });
});

describe('Legacy Status Sync', function () {
    it('syncs legacy status when lifecycle stage changes', function () {
        $editor = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'editor']);
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.lifecycle.approve', $content));

        $content->refresh();
        expect($content->status)->toBe('ready_to_deliver');
    });
});
