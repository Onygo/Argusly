<?php

namespace App\View\Presenters;

use App\Enums\CampaignApprovalStatus;
use App\Models\CampaignContent;
use App\Models\ContentPublication;
use App\Support\ContentAssets\ContentAssetTaxonomy;
use Illuminate\Support\Collection;

class CampaignContentAssetPresenter
{
    /**
     * @var array<string,mixed>
     */
    private array $definition;

    public function __construct(
        private readonly CampaignContent $asset
    ) {
        $this->asset->loadMissing(['content', 'distributionPlans']);
        $this->definition = ContentAssetTaxonomy::definition($this->type());
    }

    public static function for(CampaignContent $asset): self
    {
        return new self($asset);
    }

    public function type(): string
    {
        return (string) ($this->asset->asset_type?->value ?? $this->asset->asset_type ?? 'article');
    }

    public function typeLabel(): string
    {
        return (string) $this->definition['label'];
    }

    public function typeBadge(): string
    {
        return (string) $this->definition['badge'];
    }

    public function typeDescription(): string
    {
        return (string) $this->definition['description'];
    }

    public function typeIcon(): string
    {
        return (string) $this->definition['icon'];
    }

    public function category(): string
    {
        return (string) $this->definition['category'];
    }

    public function purpose(): string
    {
        return (string) $this->definition['purpose'];
    }

    public function purposeLabel(): string
    {
        return ContentAssetTaxonomy::purposeLabel($this->purpose());
    }

    public function group(): string
    {
        return (string) $this->definition['group'];
    }

    public function typeBadgeClasses(): string
    {
        return ContentAssetTaxonomy::typeBadgeClasses((string) $this->definition['color']);
    }

    public function workflowState(): string
    {
        $approval = (string) ($this->asset->approval_status?->value ?? $this->asset->approval_status);
        $status = (string) $this->asset->status;

        if (in_array($status, ['archived', 'canceled', 'cancelled'], true)) {
            return 'archived';
        }

        if ($approval === CampaignApprovalStatus::REJECTED->value) {
            return 'rejected';
        }

        if (in_array($approval, [CampaignApprovalStatus::REQUESTED->value, CampaignApprovalStatus::CHANGES_REQUESTED->value], true)) {
            return 'review_required';
        }

        if ($approval === CampaignApprovalStatus::APPROVED->value) {
            return 'approved';
        }

        if ($this->hasGeneratedOutput()) {
            return 'generated';
        }

        if (in_array($status, ['ready', 'scheduled', 'approved'], true)) {
            return 'ready';
        }

        return 'draft';
    }

    public function workflowStateLabel(): string
    {
        return ContentAssetTaxonomy::workflowStateLabel($this->workflowState());
    }

    public function publicationState(): string
    {
        if (! (bool) ($this->definition['publishable'] ?? true)) {
            return 'not_publishable';
        }

        $content = $this->asset->content;
        if ($content && in_array((string) $content->status, ['published', 'live'], true)) {
            return 'published';
        }

        if ($content && $content->relationLoaded('publications')) {
            $hasPublishedPublication = $content->publications
                ->contains(fn (ContentPublication $publication): bool => (string) $publication->delivery_status === ContentPublication::STATUS_DELIVERED);

            if ($hasPublishedPublication) {
                return 'published';
            }
        }

        if (
            $content
            && (
                $content->scheduled_publish_at
                || (string) ($content->publish_status ?? '') === 'scheduled'
            )
        ) {
            return 'scheduled';
        }

        if ($this->hasGeneratedOutput()) {
            return 'ready_to_publish';
        }

        return 'unpublished';
    }

    public function publicationStateLabel(): string
    {
        return ContentAssetTaxonomy::publicationStateLabel($this->publicationState());
    }

    public function distributionState(): string
    {
        $plans = $this->distributionPlans();

        if ($plans->isEmpty()) {
            return 'not_distributed';
        }

        $statuses = $plans
            ->map(fn ($plan): string => (string) ($plan->status?->value ?? $plan->status))
            ->filter()
            ->values();

        if ($statuses->contains('failed')) {
            return 'failed';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['ready', 'scheduled', 'queued', 'draft'], true))) {
            return 'distribution_pending';
        }

        if ($statuses->contains('distributed')) {
            return 'distributed';
        }

        return 'not_distributed';
    }

    public function distributionStateLabel(): string
    {
        return ContentAssetTaxonomy::distributionStateLabel($this->distributionState());
    }

    public function requiredAction(): string
    {
        return match (true) {
            $this->workflowState() === 'rejected' => 'Revise asset',
            $this->workflowState() === 'review_required' => 'Needs review',
            $this->distributionState() === 'failed' => 'Fix distribution',
            ! $this->hasGeneratedOutput() && $this->workflowState() === 'draft' => 'Generate draft',
            $this->publicationState() === 'ready_to_publish' => 'Schedule publication',
            $this->distributionState() === 'distribution_pending' && $this->publicationState() === 'not_publishable' => 'Needs placement',
            $this->distributionState() === 'distribution_pending' => 'Schedule distribution',
            default => 'No action required',
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'type_label' => $this->typeLabel(),
            'type_badge' => $this->typeBadge(),
            'type_description' => $this->typeDescription(),
            'type_icon' => $this->typeIcon(),
            'type_badge_classes' => $this->typeBadgeClasses(),
            'category' => $this->category(),
            'purpose' => $this->purpose(),
            'purpose_label' => $this->purposeLabel(),
            'group' => $this->group(),
            'workflow_state' => $this->workflowState(),
            'workflow_state_label' => $this->workflowStateLabel(),
            'workflow_state_classes' => ContentAssetTaxonomy::stateBadgeClasses($this->workflowState()),
            'publication_state' => $this->publicationState(),
            'publication_state_label' => $this->publicationStateLabel(),
            'publication_state_classes' => ContentAssetTaxonomy::stateBadgeClasses($this->publicationState()),
            'distribution_state' => $this->distributionState(),
            'distribution_state_label' => $this->distributionStateLabel(),
            'distribution_state_classes' => ContentAssetTaxonomy::stateBadgeClasses($this->distributionState()),
            'required_action' => $this->requiredAction(),
        ];
    }

    private function hasGeneratedOutput(): bool
    {
        $metadata = (array) $this->asset->metadata;

        return (bool) $this->asset->content_id
            || ! empty($metadata['generated_social_variant'])
            || ! empty($metadata['generated_answer_block_ids']);
    }

    private function distributionPlans(): Collection
    {
        return $this->asset->relationLoaded('distributionPlans')
            ? $this->asset->distributionPlans
            : $this->asset->distributionPlans()->get();
    }
}
