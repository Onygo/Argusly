<?php

namespace App\Services\Integrations\LinkedIn;

use App\Data\Integrations\LinkedIn\LinkedInOrganization;
use App\Models\IntegrationConnection;
use App\Models\SocialProfile;

class LinkedInOrganizationService
{
    public function __construct(private readonly LinkedInProvider $provider) {}

    public function organizationUrn(string $organizationId): string
    {
        return "urn:li:organization:{$organizationId}";
    }

    /**
     * @return array<int, LinkedInOrganization>
     */
    public function placeholderOrganizations(IntegrationConnection $connection): array
    {
        return collect($connection->metadata['organizations'] ?? [])
            ->map(fn (array $organization): LinkedInOrganization => new LinkedInOrganization(
                id: (string) $organization['id'],
                name: (string) ($organization['name'] ?? 'LinkedIn organization'),
                vanityName: $organization['vanity_name'] ?? null,
                profileUrl: $organization['profile_url'] ?? null,
                avatarUrl: $organization['avatar_url'] ?? null,
                roles: $organization['roles'] ?? [],
                capabilities: $organization['capabilities'] ?? [],
                rawPayload: $organization,
            ))
            ->values()
            ->all();
    }

    public function canPublishOrganization(IntegrationConnection $connection, SocialProfile $profile): bool
    {
        if ($profile->provider !== $this->provider->key() || ! in_array($profile->type, ['organization', 'page'], true)) {
            return false;
        }

        if (! in_array('w_organization_social', $connection->scopes ?? [], true)) {
            return false;
        }

        return (bool) ($profile->metadata['capabilities']['publish'] ?? false)
            || in_array('ADMINISTRATOR', $profile->metadata['roles'] ?? [], true)
            || in_array('CONTENT_ADMIN', $profile->metadata['roles'] ?? [], true);
    }
}
