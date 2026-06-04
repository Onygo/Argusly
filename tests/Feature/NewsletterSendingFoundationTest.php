<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Audience;
use App\Models\AudienceMember;
use App\Models\Brand;
use App\Models\CreditBalance;
use App\Models\EmailProvider;
use App\Models\Newsletter;
use App\Models\Role;
use App\Models\Segment;
use App\Models\User;
use App\Services\CreditService;
use App\Services\NewsletterSendingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class NewsletterSendingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_send_requires_approval_before_queueing(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $newsletter = $this->newsletter($account, $brand);
        $audience = $this->audienceWithMembers($account, $brand, ['ready@example.com']);
        $provider = $this->provider($account, $brand);

        $this->expectException(InvalidArgumentException::class);

        app(NewsletterSendingService::class)->queue($newsletter, $user, [
            'audience_id' => $audience->id,
            'email_provider_id' => $provider->id,
            'dispatch' => false,
        ]);
    }

    public function test_approved_newsletter_can_queue_and_process_fake_send_with_credit_deductions_and_events(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner');
        app(CreditService::class)->grant($account, 10, $user, 'Newsletter send test credits');
        $newsletter = $this->newsletter($account, $brand);
        $this->approve($newsletter, $user);
        $audience = $this->audienceWithMembers($account, $brand, ['first@example.com', 'second@example.com']);
        $provider = $this->provider($account, $brand);

        $send = app(NewsletterSendingService::class)->queue($newsletter, $user, [
            'audience_id' => $audience->id,
            'email_provider_id' => $provider->id,
            'dispatch' => false,
        ]);

        $this->assertSame('queued', $send->status);
        $this->assertSame(2, $send->total_recipients);
        $this->assertDatabaseCount('newsletter_send_recipients', 2);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'NewsletterSendQueued',
            'subject_type' => $send->getMorphClass(),
            'subject_id' => $send->id,
        ]);

        $processed = app(NewsletterSendingService::class)->process($send);

        $this->assertSame('sent', $processed->status);
        $this->assertSame(2, $processed->sent_count);
        $this->assertSame(0, $processed->failed_count);
        $this->assertNotNull($processed->started_at);
        $this->assertNotNull($processed->completed_at);
        $this->assertDatabaseHas('newsletter_send_recipients', ['email' => 'first@example.com', 'status' => 'sent']);
        $this->assertDatabaseHas('newsletter_send_recipients', ['email' => 'second@example.com', 'status' => 'sent']);
        $this->assertSame(8, CreditBalance::query()->where('account_id', $account->id)->value('balance'));
        $this->assertDatabaseHas('credit_transactions', [
            'account_id' => $account->id,
            'type' => 'newsletter_send',
            'amount' => -1,
            'subject_type' => $send->getMorphClass(),
            'subject_id' => $send->id,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'NewsletterSendCompleted',
            'subject_type' => $send->getMorphClass(),
            'subject_id' => $send->id,
        ]);
    }

    public function test_newsletter_send_failure_marks_recipient_and_creates_signal(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        app(CreditService::class)->grant($account, 10, $user, 'Newsletter send test credits');
        $newsletter = $this->newsletter($account, $brand);
        $this->approve($newsletter, $user);
        $audience = $this->audienceWithMembers($account, $brand, ['ok@example.com', 'fail@example.com']);
        $provider = $this->provider($account, $brand);

        $send = app(NewsletterSendingService::class)->queue($newsletter, $user, [
            'audience_id' => $audience->id,
            'email_provider_id' => $provider->id,
            'dispatch' => false,
        ]);

        $processed = app(NewsletterSendingService::class)->process($send);

        $this->assertSame('failed', $processed->status);
        $this->assertSame(1, $processed->sent_count);
        $this->assertSame(1, $processed->failed_count);
        $this->assertDatabaseHas('newsletter_send_recipients', [
            'email' => 'fail@example.com',
            'status' => 'failed',
            'error_message' => 'Fake email provider rejected the recipient.',
        ]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'NewsletterSendFailed',
            'subject_type' => $send->getMorphClass(),
            'subject_id' => $send->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source' => 'newsletter_sending',
            'type' => 'publishing_failed',
            'dedupe_key' => "newsletter-send-failed:{$send->id}",
        ]);
    }

    public function test_newsletter_send_can_use_segment_audience(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        app(CreditService::class)->grant($account, 10, $user, 'Newsletter send test credits');
        $newsletter = $this->newsletter($account, $brand);
        $this->approve($newsletter, $user);
        $audience = $this->audienceWithMembers($account, $brand, ['segment@example.com']);
        $segment = Segment::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'audience_id' => $audience->id,
            'name' => 'Active segment',
            'rules' => ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
            'status' => 'active',
        ]);
        $provider = $this->provider($account, $brand);

        $send = app(NewsletterSendingService::class)->queue($newsletter, $user, [
            'segment_id' => $segment->id,
            'email_provider_id' => $provider->id,
            'dispatch' => false,
        ]);

        $this->assertSame($segment->id, $send->segment_id);
        $this->assertSame($audience->id, $send->audience_id);
        $this->assertSame(1, $send->total_recipients);
    }

    public function test_newsletter_send_rejects_cross_tenant_provider(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'other-send-account');
        $newsletter = $this->newsletter($account, $brand);
        $this->approve($newsletter, $user);
        $audience = $this->audienceWithMembers($account, $brand, ['ready@example.com']);
        $provider = $this->provider($otherAccount, $otherBrand);

        $this->expectException(ModelNotFoundException::class);

        app(NewsletterSendingService::class)->queue($newsletter, $user, [
            'audience_id' => $audience->id,
            'email_provider_id' => $provider->id,
            'dispatch' => false,
        ]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $slug = 'newsletter-send-account'): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }

    private function newsletter(Account $account, Brand $brand): Newsletter
    {
        return Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Sendable newsletter',
            'subject' => 'Sendable subject',
            'language' => 'en',
            'status' => 'approved',
        ]);
    }

    private function approve(Newsletter $newsletter, User $user): void
    {
        Approval::query()->create([
            'account_id' => $newsletter->account_id,
            'brand_id' => $newsletter->brand_id,
            'subject_type' => $newsletter->getMorphClass(),
            'subject_id' => $newsletter->id,
            'status' => 'approved',
            'requested_by' => $user->id,
            'approved_by' => $user->id,
            'requested_at' => now(),
            'decided_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $emails
     */
    private function audienceWithMembers(Account $account, Brand $brand, array $emails): Audience
    {
        $audience = Audience::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Newsletter audience',
            'status' => 'active',
        ]);

        foreach ($emails as $email) {
            AudienceMember::query()->create([
                'account_id' => $account->id,
                'audience_id' => $audience->id,
                'email' => $email,
                'status' => 'active',
                'source' => 'test',
            ]);
        }

        return $audience;
    }

    private function provider(Account $account, Brand $brand): EmailProvider
    {
        return EmailProvider::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'smtp',
            'name' => 'Fake SMTP',
            'status' => 'active',
            'settings' => ['from_email' => 'newsletter@example.com'],
            'credentials' => ['secret' => 'fake'],
        ]);
    }
}
