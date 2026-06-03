<?php

namespace Tests\Feature;

use App\Mail\PilotSignupRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_pages_render(): void
    {
        $this->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('See how AI talks about your brand.')
            ->assertSee('Monitor AI visibility, discover opportunities and orchestrate growth from a single intelligence platform.')
            ->assertSee(route('marketing.signup'), false)
            ->assertSee('Join the Argusly pilot.')
            ->assertDontSee('No credit card')
            ->assertDontSee('14-day trial')
            ->assertDontSee('Cancel anytime')
            ->assertDontSee('Pricing')
            ->assertSee('buyer questions and comparisons')
            ->assertDontSee('EV charging')
            ->assertDontSee('SUV');
        $this->get(route('marketing.page', 'platform'))->assertOk()->assertSee('One operating layer');
        $this->get(route('marketing.page', 'security'))->assertOk()->assertSee('tenant boundaries');
        $this->get('/pricing')->assertNotFound();
        $this->get(route('marketing.page', 'privacy'))
            ->assertOk()
            ->assertSee('How Argusly handles personal, workspace and integration data.')
            ->assertSee('AI and automation')
            ->assertSee('Integration and connector data')
            ->assertDontSee('PublishLayer');
        $this->get(route('marketing.page', 'terms'))
            ->assertOk()
            ->assertSee('Terms for Argusly pilot access and platform use.')
            ->assertSee('AI output and recommendations')
            ->assertSee('These website terms do not create any paid access plan')
            ->assertDontSee('PublishLayer');
    }

    public function test_signup_page_renders_and_stores_pilot_request(): void
    {
        Mail::fake();

        $this->get(route('marketing.signup'))
            ->assertOk()
            ->assertSee('Request an Argusly pilot.')
            ->assertSee('Request pilot subscription')
            ->assertSee(route('marketing.page', 'privacy'), false)
            ->assertSee(route('marketing.page', 'terms'), false);

        $this->post(route('marketing.signup.store'), [
            'name' => 'Jane Pilot',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'website' => 'https://example.com',
            'role' => 'CMO',
            'goal' => 'Monitor brand visibility in AI answers.',
            'consent' => '1',
        ])->assertRedirect(route('marketing.signup'));

        $this->assertDatabaseHas('pilot_signups', [
            'name' => 'Jane Pilot',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'website' => 'https://example.com',
            'role' => 'CMO',
            'status' => 'pending',
        ]);

        Mail::assertQueued(PilotSignupRequested::class, function (PilotSignupRequested $mail): bool {
            return $mail->hasTo('hello@argusly.com')
                && $mail->signup['email'] === 'jane@example.com'
                && $mail->signup['company'] === 'Example Inc';
        });
    }

    public function test_signup_requires_privacy_and_terms_consent(): void
    {
        $this->post(route('marketing.signup.store'), [
            'name' => 'Jane Pilot',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
        ])->assertSessionHasErrors('consent');
    }

    public function test_blog_is_offline_until_content_is_ready(): void
    {
        $this->get('/blog')->assertNotFound();
        $this->get('/blog/ai-visibility-operations')->assertNotFound();
    }
}
