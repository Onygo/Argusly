<?php

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\DistributionPlanStatus;
use App\Models\CampaignContent;
use App\Models\CampaignDistributionPlan;
use App\View\Presenters\CampaignContentAssetPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

it('classifies review gated linkedin posts as distribution assets with a clear next action', function (): void {
    $asset = new CampaignContent([
        'asset_type' => CampaignContentAssetType::LINKEDIN_POST,
        'status' => 'planned',
        'approval_status' => CampaignApprovalStatus::REQUESTED,
        'metadata' => [],
    ]);

    $asset->setRelation('distributionPlans', new EloquentCollection([
        new CampaignDistributionPlan(['status' => DistributionPlanStatus::DRAFT]),
    ]));

    $presented = CampaignContentAssetPresenter::for($asset)->toArray();

    expect($presented)->toMatchArray([
        'type' => 'linkedin_post',
        'type_label' => 'LinkedIn Post',
        'category' => 'distribution',
        'purpose' => 'distribution_content',
        'purpose_label' => 'Distribution Content',
        'workflow_state' => 'review_required',
        'publication_state' => 'unpublished',
        'distribution_state' => 'distribution_pending',
        'required_action' => 'Needs review',
    ]);
});

it('separates non publishable newsletter placement from publication state', function (): void {
    $asset = new CampaignContent([
        'asset_type' => CampaignContentAssetType::NEWSLETTER_SNIPPET,
        'status' => 'planned',
        'approval_status' => CampaignApprovalStatus::APPROVED,
        'metadata' => ['generated_social_variant' => true],
    ]);

    $asset->setRelation('distributionPlans', new EloquentCollection([
        new CampaignDistributionPlan(['status' => DistributionPlanStatus::READY]),
    ]));

    $presented = CampaignContentAssetPresenter::for($asset)->toArray();

    expect($presented)->toMatchArray([
        'type' => 'newsletter_snippet',
        'workflow_state' => 'approved',
        'publication_state' => 'not_publishable',
        'distribution_state' => 'distribution_pending',
        'required_action' => 'Needs placement',
    ]);
});

