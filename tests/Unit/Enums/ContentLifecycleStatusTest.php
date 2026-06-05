<?php

use App\Enums\ContentLifecycleStatus;

describe('ContentLifecycleStatus enum', function () {
    it('has all expected cases', function () {
        $cases = ContentLifecycleStatus::cases();
        $values = array_map(fn ($c) => $c->value, $cases);

        expect($values)->toContain('idea');
        expect($values)->toContain('brief');
        expect($values)->toContain('draft');
        expect($values)->toContain('review');
        expect($values)->toContain('approved');
        expect($values)->toContain('scheduled');
        expect($values)->toContain('published');
        expect($values)->toContain('refresh_needed');
        expect($values)->toContain('archived');
    });

    it('returns correct labels', function () {
        expect(ContentLifecycleStatus::IDEA->label())->toBe('Idea');
        expect(ContentLifecycleStatus::BRIEF->label())->toBe('Brief');
        expect(ContentLifecycleStatus::DRAFT->label())->toBe('Draft');
        expect(ContentLifecycleStatus::REVIEW->label())->toBe('In Review');
        expect(ContentLifecycleStatus::APPROVED->label())->toBe('Approved');
        expect(ContentLifecycleStatus::SCHEDULED->label())->toBe('Scheduled');
        expect(ContentLifecycleStatus::PUBLISHED->label())->toBe('Published');
        expect(ContentLifecycleStatus::REFRESH_NEEDED->label())->toBe('Needs Refresh');
        expect(ContentLifecycleStatus::ARCHIVED->label())->toBe('Archived');
    });

    it('returns correct colors', function () {
        expect(ContentLifecycleStatus::IDEA->color())->toBe('slate');
        expect(ContentLifecycleStatus::BRIEF->color())->toBe('slate');
        expect(ContentLifecycleStatus::DRAFT->color())->toBe('amber');
        expect(ContentLifecycleStatus::REVIEW->color())->toBe('purple');
        expect(ContentLifecycleStatus::APPROVED->color())->toBe('emerald');
        expect(ContentLifecycleStatus::SCHEDULED->color())->toBe('sky');
        expect(ContentLifecycleStatus::PUBLISHED->color())->toBe('green');
        expect(ContentLifecycleStatus::REFRESH_NEEDED->color())->toBe('orange');
        expect(ContentLifecycleStatus::ARCHIVED->color())->toBe('gray');
    });

    it('returns icons for each stage', function () {
        foreach (ContentLifecycleStatus::canonicalStages() as $stage) {
            expect($stage->icon())->toBeString()->not->toBeEmpty();
        }
    });

    describe('allowedTransitions', function () {
        it('allows idea to transition to brief or archived', function () {
            $transitions = ContentLifecycleStatus::IDEA->allowedTransitions();
            $values = array_map(fn ($t) => $t->value, $transitions);

            expect($values)->toContain('brief');
            expect($values)->toContain('archived');
            expect($values)->not->toContain('published');
        });

        it('allows draft to transition to brief, review, or archived', function () {
            $transitions = ContentLifecycleStatus::DRAFT->allowedTransitions();
            $values = array_map(fn ($t) => $t->value, $transitions);

            expect($values)->toContain('brief');
            expect($values)->toContain('review');
            expect($values)->toContain('archived');
        });

        it('allows review to transition to draft, approved, or archived', function () {
            $transitions = ContentLifecycleStatus::REVIEW->allowedTransitions();
            $values = array_map(fn ($t) => $t->value, $transitions);

            expect($values)->toContain('draft');
            expect($values)->toContain('approved');
            expect($values)->toContain('archived');
        });

        it('allows approved to transition to review, scheduled, published, or archived', function () {
            $transitions = ContentLifecycleStatus::APPROVED->allowedTransitions();
            $values = array_map(fn ($t) => $t->value, $transitions);

            expect($values)->toContain('review');
            expect($values)->toContain('scheduled');
            expect($values)->toContain('published');
            expect($values)->toContain('archived');
        });

        it('allows published to transition to refresh_needed or archived', function () {
            $transitions = ContentLifecycleStatus::PUBLISHED->allowedTransitions();
            $values = array_map(fn ($t) => $t->value, $transitions);

            expect($values)->toContain('refresh_needed');
            expect($values)->toContain('archived');
        });

        it('allows archived to be restored to idea or draft', function () {
            $transitions = ContentLifecycleStatus::ARCHIVED->allowedTransitions();
            $values = array_map(fn ($t) => $t->value, $transitions);

            expect($values)->toContain('idea');
            expect($values)->toContain('draft');
        });
    });

    describe('canTransitionTo', function () {
        it('returns true for valid transitions', function () {
            expect(ContentLifecycleStatus::IDEA->canTransitionTo(ContentLifecycleStatus::BRIEF))->toBeTrue();
            expect(ContentLifecycleStatus::DRAFT->canTransitionTo(ContentLifecycleStatus::REVIEW))->toBeTrue();
            expect(ContentLifecycleStatus::REVIEW->canTransitionTo(ContentLifecycleStatus::APPROVED))->toBeTrue();
        });

        it('returns false for invalid transitions', function () {
            expect(ContentLifecycleStatus::IDEA->canTransitionTo(ContentLifecycleStatus::PUBLISHED))->toBeFalse();
            expect(ContentLifecycleStatus::DRAFT->canTransitionTo(ContentLifecycleStatus::PUBLISHED))->toBeFalse();
            expect(ContentLifecycleStatus::PUBLISHED->canTransitionTo(ContentLifecycleStatus::IDEA))->toBeFalse();
        });

        it('returns true for same stage transition (no-op)', function () {
            expect(ContentLifecycleStatus::DRAFT->canTransitionTo(ContentLifecycleStatus::DRAFT))->toBeTrue();
            expect(ContentLifecycleStatus::PUBLISHED->canTransitionTo(ContentLifecycleStatus::PUBLISHED))->toBeTrue();
        });
    });

    describe('fromLegacyStatus', function () {
        it('maps brief_received to BRIEF', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('brief_received'))->toBe(ContentLifecycleStatus::BRIEF);
        });

        it('maps brief to BRIEF', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('brief'))->toBe(ContentLifecycleStatus::BRIEF);
        });

        it('maps draft to DRAFT', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('draft'))->toBe(ContentLifecycleStatus::DRAFT);
        });

        it('maps generating to DRAFT', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('generating'))->toBe(ContentLifecycleStatus::DRAFT);
        });

        it('maps review to REVIEW', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('review'))->toBe(ContentLifecycleStatus::REVIEW);
        });

        it('maps ready_to_deliver to APPROVED', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('ready_to_deliver'))->toBe(ContentLifecycleStatus::APPROVED);
        });

        it('maps published to PUBLISHED', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('published'))->toBe(ContentLifecycleStatus::PUBLISHED);
        });

        it('maps delivered to PUBLISHED', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('delivered'))->toBe(ContentLifecycleStatus::PUBLISHED);
        });

        it('maps archived to ARCHIVED', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('archived'))->toBe(ContentLifecycleStatus::ARCHIVED);
        });

        it('maps unknown status to IDEA', function () {
            expect(ContentLifecycleStatus::fromLegacyStatus('unknown'))->toBe(ContentLifecycleStatus::IDEA);
            expect(ContentLifecycleStatus::fromLegacyStatus(null))->toBe(ContentLifecycleStatus::IDEA);
        });
    });

    describe('toLegacyStatus', function () {
        it('maps IDEA to brief', function () {
            expect(ContentLifecycleStatus::IDEA->toLegacyStatus())->toBe('brief');
        });

        it('maps BRIEF to brief', function () {
            expect(ContentLifecycleStatus::BRIEF->toLegacyStatus())->toBe('brief');
        });

        it('maps DRAFT to draft', function () {
            expect(ContentLifecycleStatus::DRAFT->toLegacyStatus())->toBe('draft');
        });

        it('maps REVIEW to review', function () {
            expect(ContentLifecycleStatus::REVIEW->toLegacyStatus())->toBe('review');
        });

        it('maps APPROVED to ready_to_deliver', function () {
            expect(ContentLifecycleStatus::APPROVED->toLegacyStatus())->toBe('ready_to_deliver');
        });

        it('maps SCHEDULED to scheduled', function () {
            expect(ContentLifecycleStatus::SCHEDULED->toLegacyStatus())->toBe('scheduled');
        });

        it('maps PUBLISHED to published', function () {
            expect(ContentLifecycleStatus::PUBLISHED->toLegacyStatus())->toBe('published');
        });

        it('maps REFRESH_NEEDED to published (stays visible)', function () {
            expect(ContentLifecycleStatus::REFRESH_NEEDED->toLegacyStatus())->toBe('published');
        });

        it('maps ARCHIVED to archived', function () {
            expect(ContentLifecycleStatus::ARCHIVED->toLegacyStatus())->toBe('archived');
        });
    });

    describe('helper methods', function () {
        it('isEditable returns true for editable stages', function () {
            expect(ContentLifecycleStatus::IDEA->isEditable())->toBeTrue();
            expect(ContentLifecycleStatus::BRIEF->isEditable())->toBeTrue();
            expect(ContentLifecycleStatus::DRAFT->isEditable())->toBeTrue();
            expect(ContentLifecycleStatus::REVIEW->isEditable())->toBeTrue();
            expect(ContentLifecycleStatus::REFRESH_NEEDED->isEditable())->toBeTrue();
        });

        it('isEditable returns false for non-editable stages', function () {
            expect(ContentLifecycleStatus::APPROVED->isEditable())->toBeFalse();
            expect(ContentLifecycleStatus::SCHEDULED->isEditable())->toBeFalse();
            expect(ContentLifecycleStatus::PUBLISHED->isEditable())->toBeFalse();
            expect(ContentLifecycleStatus::ARCHIVED->isEditable())->toBeFalse();
        });

        it('isDeliverable returns true for deliverable stages', function () {
            expect(ContentLifecycleStatus::APPROVED->isDeliverable())->toBeTrue();
            expect(ContentLifecycleStatus::SCHEDULED->isDeliverable())->toBeTrue();
        });

        it('isDeliverable returns false for non-deliverable stages', function () {
            expect(ContentLifecycleStatus::IDEA->isDeliverable())->toBeFalse();
            expect(ContentLifecycleStatus::DRAFT->isDeliverable())->toBeFalse();
            expect(ContentLifecycleStatus::PUBLISHED->isDeliverable())->toBeFalse();
        });

        it('isTerminal returns true only for ARCHIVED', function () {
            expect(ContentLifecycleStatus::ARCHIVED->isTerminal())->toBeTrue();
            expect(ContentLifecycleStatus::PUBLISHED->isTerminal())->toBeFalse();
            expect(ContentLifecycleStatus::DRAFT->isTerminal())->toBeFalse();
        });

        it('isActive returns opposite of isTerminal', function () {
            expect(ContentLifecycleStatus::ARCHIVED->isActive())->toBeFalse();
            expect(ContentLifecycleStatus::PUBLISHED->isActive())->toBeTrue();
            expect(ContentLifecycleStatus::DRAFT->isActive())->toBeTrue();
        });
    });

    describe('canonicalStages', function () {
        it('returns stages in correct order', function () {
            $stages = ContentLifecycleStatus::canonicalStages();
            $values = array_map(fn ($s) => $s->value, $stages);

            expect($values)->toBe([
                'idea',
                'brief',
                'draft',
                'review',
                'approved',
                'scheduled',
                'published',
                'refresh_needed',
                'archived',
            ]);
        });

        it('does not include legacy aliases', function () {
            $stages = ContentLifecycleStatus::canonicalStages();
            $values = array_map(fn ($s) => $s->value, $stages);

            expect($values)->not->toContain('ready_to_deliver');
            expect($values)->not->toContain('delivered');
        });
    });

    describe('activeStages', function () {
        it('returns all stages except ARCHIVED', function () {
            $stages = ContentLifecycleStatus::activeStages();
            $values = array_map(fn ($s) => $s->value, $stages);

            expect($values)->not->toContain('archived');
            expect(count($stages))->toBe(8);
        });
    });

    describe('normalized', function () {
        it('normalizes legacy values to canonical', function () {
            expect(ContentLifecycleStatus::READY_TO_DELIVER->normalized())->toBe(ContentLifecycleStatus::APPROVED);
            expect(ContentLifecycleStatus::DELIVERED->normalized())->toBe(ContentLifecycleStatus::PUBLISHED);
        });

        it('returns self for canonical values', function () {
            expect(ContentLifecycleStatus::APPROVED->normalized())->toBe(ContentLifecycleStatus::APPROVED);
            expect(ContentLifecycleStatus::PUBLISHED->normalized())->toBe(ContentLifecycleStatus::PUBLISHED);
            expect(ContentLifecycleStatus::DRAFT->normalized())->toBe(ContentLifecycleStatus::DRAFT);
        });
    });
});
