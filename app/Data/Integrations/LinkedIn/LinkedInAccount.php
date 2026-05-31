<?php

namespace App\Data\Integrations\LinkedIn;

use App\Models\IntegrationConnection;

readonly class LinkedInAccount
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email = null,
        public ?string $profileUrl = null,
        public ?string $avatarUrl = null,
        public string $type = 'personal_profile',
        public ?string $organizationId = null,
        public array $rawProfile = [],
    ) {}

    public static function fromConnection(IntegrationConnection $connection): self
    {
        return new self(
            id: (string) $connection->provider_account_id,
            name: (string) ($connection->provider_account_name ?? $connection->name),
            email: $connection->metadata['email'] ?? null,
            profileUrl: $connection->metadata['profile_url'] ?? null,
            avatarUrl: $connection->metadata['avatar_url'] ?? null,
            type: $connection->metadata['linkedin_account_type'] ?? 'personal_profile',
            organizationId: $connection->metadata['organization_id'] ?? null,
            rawProfile: $connection->metadata['raw_profile'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'email' => $this->email,
            'profile_url' => $this->profileUrl,
            'avatar_url' => $this->avatarUrl,
            'linkedin_account_type' => $this->type,
            'organization_id' => $this->organizationId,
            'provider_member_id' => $this->id,
            'raw_profile' => $this->rawProfile,
        ];
    }
}
