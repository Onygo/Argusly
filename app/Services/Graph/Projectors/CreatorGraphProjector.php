<?php

namespace App\Services\Graph\Projectors;

use App\Models\Contact;
use App\Models\GraphNode;
use App\Models\Organization;
use App\Models\SocialProfile;
use App\Services\Graph\GraphProjectionService;

class CreatorGraphProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(SocialProfile $profile): GraphNode
    {
        return $this->graph->node($profile, 'creator', $profile->display_name, [
            'provider' => $profile->provider,
            'profile_url' => $profile->profile_url,
            'type' => $profile->type,
            'status' => $profile->status,
        ]);
    }

    public function projectContact(Contact $contact): GraphNode
    {
        return $this->graph->node($contact, 'contact', $contact->display_name, [
            'email' => $contact->email,
            'website' => $contact->website,
            'linkedin_url' => $contact->linkedin_url,
        ]);
    }

    public function projectOrganization(Organization $organization): GraphNode
    {
        return $this->graph->node($organization, 'organization', $organization->name, [
            'website' => $organization->website,
            'industry' => $organization->industry,
        ]);
    }
}
