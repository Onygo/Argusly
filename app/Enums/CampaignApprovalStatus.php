<?php

namespace App\Enums;

enum CampaignApprovalStatus: string
{
    case NOT_REQUIRED = 'not_required';
    case REQUESTED = 'requested';
    case CHANGES_REQUESTED = 'changes_requested';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
