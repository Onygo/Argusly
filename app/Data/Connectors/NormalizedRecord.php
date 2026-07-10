<?php

namespace App\Data\Connectors;

class NormalizedRecord
{
    public const MARKETING_ACCOUNT = 'marketing_account';
    public const CAMPAIGN = 'campaign';
    public const AD_GROUP = 'ad_group';
    public const AD = 'ad';
    public const DAILY_PERFORMANCE = 'daily_performance';
    public const CRM_COMPANY = 'crm_company';
    public const CRM_CONTACT = 'crm_contact';
    public const CRM_DEAL = 'crm_deal';
    public const CRM_ACTIVITY = 'crm_activity';

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $rawReference
     */
    public function __construct(
        public readonly string $entityType,
        public readonly array $attributes,
        public readonly array $rawReference = [],
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $rawReference
     */
    public static function make(string $entityType, array $attributes, array $rawReference = []): self
    {
        return new self($entityType, $attributes, $rawReference);
    }
}
