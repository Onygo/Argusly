<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\SocialProfiles\SocialProfileService;
use Database\Seeders\IntegrationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SocialProfileSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_always_manage_connected_social_profile(): void
    {
        [$owner, , , $profile] = $this->globalLinkedInProfile();

        $service = app(SocialProfileService::class);

        $this->assertTrue($service->canManage($owner, $profile));
        $this->assertTrue($service->canPublish($owner, $profile));
    }

    public function test_social_profile_can_be_shared_with_account_for_prepare_schedule_and_publish(): void
    {
        [$owner, $account, $brand, $profile] = $this->globalLinkedInProfile();
        $member = User::factory()->create();
        $member->accounts()->attach($account, ['status' => 'active']);
        $member->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $service = app(SocialProfileService::class);
        $permission = $service->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);

        $this->assertTrue($permission->can_publish);
        $this->assertTrue($service->canView($member, $profile, $account, $brand));
        $this->assertTrue($service->canPrepare($member, $profile, $account, $brand));
        $this->assertTrue($service->canSchedule($member, $profile, $account, $brand));
        $this->assertTrue($service->canPublish($member, $profile, $account, $brand));
        $this->assertFalse($service->canManage($member, $profile, $account, $brand));
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialProfileShared',
            'account_id' => $account->id,
            'brand_id' => null,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'social_profile.shared',
            'account_id' => $account->id,
        ]);
    }

    public function test_brand_share_can_allow_view_and_prepare_without_publish_or_schedule(): void
    {
        [$owner, $account, , $profile] = $this->globalLinkedInProfile();
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'TeamsCX', 'slug' => 'teamscx']);
        $member = User::factory()->create();
        $member->accounts()->attach($account, ['status' => 'active']);
        $member->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $service = app(SocialProfileService::class);
        $service->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => false,
            'publish' => false,
        ]);

        $this->assertTrue($service->canView($member, $profile, $account, $brand));
        $this->assertTrue($service->canPrepare($member, $profile, $account, $brand));
        $this->assertFalse($service->canSchedule($member, $profile, $account, $brand));
        $this->assertFalse($service->canPublish($member, $profile, $account, $brand));
    }

    public function test_prepare_rights_do_not_allow_publish_and_publish_requires_can_publish(): void
    {
        [$owner, $account, $brand, $profile] = $this->globalLinkedInProfile();
        $member = User::factory()->create();
        $member->accounts()->attach($account, ['status' => 'active']);
        $member->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $service = app(SocialProfileService::class);
        $service->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => false,
            'publish' => false,
        ]);

        $this->assertTrue($service->canPrepare($member, $profile, $account, $brand));
        $this->assertFalse($service->canSchedule($member, $profile, $account, $brand));
        $this->assertFalse($service->canPublish($member, $profile, $account, $brand));
    }

    public function test_social_profile_permissions_do_not_leak_to_unshared_accounts(): void
    {
        [$owner, $allowedAccount, $allowedBrand, $profile] = $this->globalLinkedInProfile();
        $blockedAccount = Account::query()->create(['name' => 'Sana Medical', 'slug' => 'sana-medical']);
        $blockedBrand = Brand::query()->create(['account_id' => $blockedAccount->id, 'name' => 'Sana Medical', 'slug' => 'sana-brand']);
        $blockedUser = User::factory()->create();
        $blockedUser->accounts()->attach($blockedAccount, ['status' => 'active']);
        $blockedUser->brands()->attach($blockedBrand, ['account_id' => $blockedAccount->id, 'status' => 'active']);

        $service = app(SocialProfileService::class);
        $service->shareWithAccount($profile, $allowedAccount, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);

        $this->assertTrue($service->canPublish($owner, $profile, $allowedAccount, $allowedBrand));
        $this->assertFalse($service->canView($blockedUser, $profile, $blockedAccount, $blockedBrand));
        $this->assertFalse($service->canPrepare($blockedUser, $profile, $blockedAccount, $blockedBrand));
        $this->assertCount(0, $service->profilesFor($blockedUser, $blockedAccount, $blockedBrand));
    }

    public function test_user_specific_overrides_are_scoped_to_account_or_brand(): void
    {
        [$owner, $account, $brand, $profile] = $this->globalLinkedInProfile();
        $user = User::factory()->create();
        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $service = app(SocialProfileService::class);
        $service->shareWithUser($profile, $user, $owner, [
            'view' => true,
            'prepare' => true,
        ], account: $account, brand: $brand);

        $this->assertTrue($service->canPrepare($user, $profile, $account, $brand));
        $this->assertFalse($service->canPublish($user, $profile, $account, $brand));

        $this->expectException(InvalidArgumentException::class);

        $service->shareWithUser($profile, $user, $owner, ['view' => true]);
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand, 3: SocialProfile, 4: IntegrationConnection}
     */
    private function globalLinkedInProfile(): array
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create(['name' => 'Ricardo']);
        $account = Account::query()->create(['name' => 'Onygo', 'slug' => 'onygo']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Argusly', 'slug' => 'argusly']);
        $owner->accounts()->attach($account, ['status' => 'active']);
        $owner->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'Ricardo LinkedIn',
            scopes: ['openid', 'profile', 'email', 'w_member_social'],
            accessToken: 'encrypted-linkedin-token',
            providerAccountId: 'linkedin-ricardo',
            providerAccountName: 'Ricardo',
        );

        $profile = app(SocialProfileService::class)->createFromIntegrationConnection(
            connection: $connection,
            owner: $owner,
            provider: 'linkedin',
            displayName: 'Ricardo LinkedIn',
            type: 'person',
            providerProfileId: 'linkedin-ricardo',
            profileUrl: 'https://www.linkedin.com/in/ricardo',
        );

        return [$owner, $account, $brand, $profile, $connection];
    }
}
