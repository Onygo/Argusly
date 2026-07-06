<?php

use App\Enums\ContentLifecycleStatus;
use App\Models\Content;
use App\Models\ContentLifecycleEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org',
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Test Org BV',
        'billing_address_line1' => 'Mainstraat 1',
        'billing_country_code' => 'NL',
    ]);
    $this->workspace = Workspace::create(['name' => 'Test Workspace', 'organization_id' => $this->org->id]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'content-lifecycle-test-plan',
        'slug' => 'content-lifecycle-test-plan',
        'name' => 'Content Lifecycle Test Plan',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'is_active' => true,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'workspace_id' => $this->workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);
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

function createLifecycleUser(string $role): User
{
    return User::factory()->create([
        'organization_id' => test()->org->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
        'email_verified_at' => now(),
    ]);
}

describe('Send to Review', function () {
    it('allows editor to send content to review', function () {
        $editor = createLifecycleUser('editor');
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.send-to-review', $content));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::REVIEW);
    });

    it('allows setting reviewer when sending to review', function () {
        $editor = createLifecycleUser('editor');
        $reviewer = createLifecycleUser('reviewer');
        $content = createLifecycleContent();

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.send-to-review', $content), [
                'reviewer_id' => $reviewer->id,
            ]);

        $response->assertRedirect();
        expect($content->fresh()->reviewer_user_id)->toBe($reviewer->id);
    });

    it('prevents viewer from sending to review', function () {
        $viewer = createLifecycleUser('viewer');
        $content = createLifecycleContent();

        $response = $this->actingAs($viewer)
            ->post(route('app.content.lifecycle.send-to-review', $content));

        $response->assertForbidden();
    });
});

describe('Approve Content', function () {
    it('allows reviewer to approve content when assigned', function () {
        $reviewer = createLifecycleUser('reviewer');
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
        $editor = createLifecycleUser('editor');
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.approve', $content));

        $response->assertRedirect();
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::APPROVED);
    });

    it('allows admin to approve content', function () {
        $admin = createLifecycleUser('admin');
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('app.content.lifecycle.approve', $content));

        $response->assertRedirect();
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::APPROVED);
    });

    it('prevents unassigned reviewer from approving', function () {
        $reviewer = createLifecycleUser('reviewer');
        $otherReviewer = createLifecycleUser('reviewer');
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
        $reviewer = createLifecycleUser('reviewer');
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
        $reviewer = createLifecycleUser('reviewer');
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
        $editor = createLifecycleUser('editor');
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
        $editor = createLifecycleUser('editor');
        $assignee = createLifecycleUser('editor');
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
        $viewer = createLifecycleUser('viewer');
        $assignee = createLifecycleUser('editor');
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
        $editor = createLifecycleUser('editor');
        $reviewer = createLifecycleUser('reviewer');
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
        $editor = createLifecycleUser('editor');
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
        $editor = createLifecycleUser('editor');
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

    it('forbids invalid stage transitions before mutation', function () {
        $editor = createLifecycleUser('editor');
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        ]);

        $response = $this->actingAs($editor)
            ->post(route('app.content.lifecycle.transition', $content), [
                'target_stage' => 'published', // Invalid direct transition
            ]);

        $response->assertForbidden();
        expect($content->fresh()->lifecycle_stage)->toBe(ContentLifecycleStatus::DRAFT);
    });

    it('validates target_stage is required', function () {
        $editor = createLifecycleUser('editor');
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
        $editor = createLifecycleUser('editor');
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
        $viewer = createLifecycleUser('viewer');
        $content = createLifecycleContent();

        $response = $this->actingAs($viewer)
            ->get(route('app.content.lifecycle.history', $content));

        $response->assertStatus(200);
    });
});

describe('Legacy Status Sync', function () {
    it('syncs legacy status when lifecycle stage changes', function () {
        $editor = createLifecycleUser('editor');
        $content = createLifecycleContent([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $this->actingAs($editor)
            ->post(route('app.content.lifecycle.approve', $content));

        $content->refresh();
        expect($content->status)->toBe('ready_to_deliver');
    });
});
