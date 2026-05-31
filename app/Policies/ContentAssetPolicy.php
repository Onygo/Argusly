<?php

namespace App\Policies;

use App\Contracts\CurrentBrandContract;
use App\Models\ContentAsset;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ContentAssetPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->allowed($user, 'view_content')
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, ContentAsset $contentAsset): Response
    {
        return $this->matchesCurrentBrand($user, $contentAsset)
            && $this->allowed($user, 'view_content', $contentAsset)
                ? Response::allow()
                : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allowed($user, 'create_content')
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, ContentAsset $contentAsset): Response
    {
        return $this->matchesCurrentBrand($user, $contentAsset)
            && $this->allowed($user, 'edit_content', $contentAsset)
                ? Response::allow()
                : Response::deny();
    }

    public function approve(User $user, ContentAsset $contentAsset): Response
    {
        return $this->publish($user, $contentAsset);
    }

    public function publish(User $user, ContentAsset $contentAsset): Response
    {
        return $this->matchesCurrentBrand($user, $contentAsset)
            && $this->allowed($user, 'publish_content', $contentAsset)
                ? Response::allow()
                : Response::deny();
    }

    private function allowed(User $user, string $permission, ?ContentAsset $contentAsset = null): bool
    {
        $context = $contentAsset
            ? ['account_id' => $contentAsset->account_id, 'brand_id' => $contentAsset->brand_id]
            : [];

        return Gate::forUser($user)->allows($permission, $context);
    }

    private function matchesCurrentBrand(User $user, ContentAsset $contentAsset): bool
    {
        return app(CurrentBrandContract::class)->id($user) === $contentAsset->brand_id;
    }
}
