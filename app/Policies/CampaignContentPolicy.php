<?php

namespace App\Policies;

use App\Models\CampaignContent;
use App\Models\User;

class CampaignContentPolicy
{
    public function view(User $user, CampaignContent $campaignContent): bool
    {
        return app(CampaignPolicy::class)->view($user, $campaignContent->campaign);
    }

    public function update(User $user, CampaignContent $campaignContent): bool
    {
        return app(CampaignPolicy::class)->update($user, $campaignContent->campaign);
    }

    public function approve(User $user, CampaignContent $campaignContent): bool
    {
        return app(CampaignPolicy::class)->approve($user, $campaignContent->campaign);
    }

    public function delete(User $user, CampaignContent $campaignContent): bool
    {
        return $this->update($user, $campaignContent);
    }
}
