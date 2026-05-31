<?php

namespace App\Data\Integrations\LinkedIn;

readonly class LinkedInOrganization
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<string, bool>  $capabilities
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $vanityName = null,
        public ?string $profileUrl = null,
        public ?string $avatarUrl = null,
        public array $roles = [],
        public array $capabilities = [],
        public array $rawPayload = [],
    ) {}

    public function urn(): string
    {
        return "urn:li:organization:{$this->id}";
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'linkedin_account_type' => 'organization',
            'organization_id' => $this->id,
            'organization_urn' => $this->urn(),
            'vanity_name' => $this->vanityName,
            'roles' => $this->roles,
            'capabilities' => $this->capabilities,
            'raw_organization' => $this->rawPayload,
        ];
    }
}
