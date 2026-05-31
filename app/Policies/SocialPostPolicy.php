<?php

namespace App\Policies;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\SocialProfiles\SocialProfileService;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class SocialPostPolicy
{
    public function viewAny(User $user): Response
    {
        return Gate::forUser($user)->allows('view_content')
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, SocialPost $socialPost): Response
    {
        return $this->matchesCurrentBrand($user, $socialPost)
            && app(SocialProfileService::class)->canView($user, $socialPost->socialProfile, $socialPost->account, $socialPost->brand)
                ? Response::allow()
                : Response::deny();
    }

    public function create(User $user): Response
    {
        return Gate::forUser($user)->allows('create_content')
            ? Response::allow()
            : Response::deny();
    }

    public function approve(User $user, SocialPost $socialPost): Response
    {
        return $this->matchesCurrentBrand($user, $socialPost)
            && app(SocialProfileService::class)->canPrepare($user, $socialPost->socialProfile, $socialPost->account, $socialPost->brand)
                ? Response::allow()
                : Response::deny();
    }

    public function schedule(User $user, SocialPost $socialPost): Response
    {
        return $this->matchesCurrentBrand($user, $socialPost)
            && app(SocialProfileService::class)->canSchedule($user, $socialPost->socialProfile, $socialPost->account, $socialPost->brand)
                ? Response::allow()
                : Response::deny();
    }

    public function publish(User $user, SocialPost $socialPost): Response
    {
        return $this->matchesCurrentBrand($user, $socialPost)
            && app(SocialProfileService::class)->canPublish($user, $socialPost->socialProfile, $socialPost->account, $socialPost->brand)
                ? Response::allow()
                : Response::deny();
    }

    private function matchesCurrentBrand(User $user, SocialPost $socialPost): bool
    {
        return app(CurrentAccountContract::class)->id($user) === $socialPost->account_id
            && app(CurrentBrandContract::class)->id($user) === $socialPost->brand_id;
    }
}
