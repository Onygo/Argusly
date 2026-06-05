<?php

namespace App\Enums;

enum AgenticMarketingApprovalMode: string
{
    case Manual = 'manual';
    case ApprovalRequired = 'approval_required';
    case PolicyEngine = 'policy_engine';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
