<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AgentTask;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\GeneratedAsset;
use App\Models\Newsletter;
use App\Models\PublishingAction;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ApprovalService
{
    public const SUBJECTS = [
        GeneratedAsset::class,
        SocialPost::class,
        PublishingAction::class,
        Recommendation::class,
        AgentTask::class,
        Briefing::class,
        Newsletter::class,
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly DomainEventService $events,
    ) {}

    public function request(Model $subject, User $user, ?string $notes = null): Approval
    {
        [$account, $brand] = $this->tenantFor($subject);
        $this->assertSupported($subject);
        $this->assertCanRequest($user, $account, $brand);

        $approval = Approval::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => 'pending',
            ],
            [
                'brand_id' => $brand?->id,
                'requested_by' => $user->id,
                'notes' => $notes,
                'requested_at' => now(),
            ],
        );

        $this->events->recordForSubject('ApprovalRequested', $approval, $user, $this->payload($approval));

        return $approval->refresh();
    }

    public function approve(Approval $approval, User $user, ?string $notes = null): Approval
    {
        $this->assertCanDecide($approval, $user);

        $approval->forceFill([
            'status' => 'approved',
            'approved_by' => $user->id,
            'rejected_by' => null,
            'notes' => $notes ?? $approval->notes,
            'decided_at' => now(),
        ])->save();

        $this->applySubjectStatus($approval->refresh(), $user, 'approved');
        $this->events->recordForSubject('ApprovalApproved', $approval, $user, $this->payload($approval));

        return $approval->refresh();
    }

    public function reject(Approval $approval, User $user, ?string $notes = null): Approval
    {
        $this->assertCanDecide($approval, $user);

        $approval->forceFill([
            'status' => 'rejected',
            'approved_by' => null,
            'rejected_by' => $user->id,
            'notes' => $notes ?? $approval->notes,
            'decided_at' => now(),
        ])->save();

        $this->applySubjectStatus($approval->refresh(), $user, 'rejected');
        $this->events->recordForSubject('ApprovalRejected', $approval, $user, $this->payload($approval));

        return $approval->refresh();
    }

    public function cancel(Approval $approval, User $user, ?string $notes = null): Approval
    {
        [$account, $brand] = [$approval->account, $approval->brand];
        $this->assertCanRequest($user, $account, $brand);

        $approval->forceFill([
            'status' => 'cancelled',
            'notes' => $notes ?? $approval->notes,
            'decided_at' => now(),
        ])->save();

        $this->events->recordForSubject('ApprovalCancelled', $approval->refresh(), $user, $this->payload($approval));

        return $approval->refresh();
    }

    public function hasApproved(Model $subject): bool
    {
        [$account] = $this->tenantFor($subject);

        return Approval::query()
            ->where('account_id', $account->id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('status', 'approved')
            ->exists();
    }

    public function assertApprovedForPublish(Model $subject, User $user): void
    {
        [$account, $brand] = $this->tenantFor($subject);

        if (! $user->roleAssignments()->where('account_id', $account->id)->exists()) {
            return;
        }

        if ($this->permissions->userCan($user, 'bypass_approval', ['account_id' => $account->id, 'brand_id' => $brand?->id])) {
            return;
        }

        if (! $this->hasApproved($subject)) {
            throw new InvalidArgumentException('Publishing requires approval before this action can be queued.');
        }
    }

    private function applySubjectStatus(Approval $approval, User $user, string $decision): void
    {
        $subject = $approval->subject;

        if (! $subject) {
            return;
        }

        if ($decision === 'approved') {
            match (true) {
                $subject instanceof GeneratedAsset => $subject->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save(),
                $subject instanceof SocialPost => $subject->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save(),
                $subject instanceof PublishingAction => $subject->forceFill(['status' => 'queued'])->save(),
                $subject instanceof Recommendation => $subject->accept($user),
                $subject instanceof AgentTask => $subject->forceFill(['status' => 'approved'])->save(),
                $subject instanceof Briefing => $subject->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save(),
                $subject instanceof Newsletter => $subject->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save(),
                default => null,
            };

            return;
        }

        match (true) {
            $subject instanceof GeneratedAsset => $subject->forceFill(['status' => 'rejected'])->save(),
            $subject instanceof SocialPost => $subject->forceFill(['status' => 'cancelled'])->save(),
            $subject instanceof PublishingAction => $subject->forceFill(['status' => 'cancelled'])->save(),
            $subject instanceof Recommendation => $subject->dismiss(),
            $subject instanceof AgentTask => $subject->forceFill(['status' => 'cancelled'])->save(),
            $subject instanceof Briefing => $subject->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null])->save(),
            $subject instanceof Newsletter => $subject->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null])->save(),
            default => null,
        };
    }

    private function assertSupported(Model $subject): void
    {
        if (! collect(self::SUBJECTS)->contains(fn (string $class) => $subject instanceof $class)) {
            throw new InvalidArgumentException('Subject does not support approvals.');
        }
    }

    private function assertCanRequest(User $user, Account $account, ?Brand $brand): void
    {
        if (! $this->permissions->userHasRole($user, ['editor', 'publisher', 'manager', 'admin', 'owner'], ['account_id' => $account->id, 'brand_id' => $brand?->id])) {
            throw new InvalidArgumentException('User cannot request approval for this tenant.');
        }
    }

    private function assertCanDecide(Approval $approval, User $user): void
    {
        if (! $this->permissions->userHasRole($user, ['manager', 'admin', 'owner'], ['account_id' => $approval->account_id, 'brand_id' => $approval->brand_id])) {
            throw new InvalidArgumentException('User cannot approve or reject this request.');
        }
    }

    /**
     * @return array{Account, Brand|null}
     */
    private function tenantFor(Model $subject): array
    {
        $account = $subject->getRelationValue('account') ?: Account::query()->find($subject->getAttribute('account_id'));
        $brand = $subject->getAttribute('brand_id') ? ($subject->getRelationValue('brand') ?: Brand::query()->find($subject->getAttribute('brand_id'))) : null;

        if (! $account) {
            throw new InvalidArgumentException('Approval subjects must be tenant scoped.');
        }

        return [$account, $brand];
    }

    private function payload(Approval $approval): array
    {
        return [
            'approval_id' => $approval->id,
            'subject_type' => $approval->subject_type,
            'subject_id' => $approval->subject_id,
            'status' => $approval->status,
        ];
    }
}
