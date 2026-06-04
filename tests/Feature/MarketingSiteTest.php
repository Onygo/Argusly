<?php

namespace Tests\Feature;

use App\Mail\ContactRequestSubmitted;
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
            ->assertSee(route('marketing.contact', ['topic' => 'sales']), false)
            ->assertSee('Join the Argusly pilot.')
            ->assertDontSee('No credit card')
            ->assertDontSee('14-day trial')
            ->assertDontSee('Cancel anytime')
            ->assertDontSee('Pricing')
            ->assertSee('buyer questions and comparisons')
            ->assertDontSee('EV charging')
            ->assertDontSee('SUV');
        $this->get(route('marketing.page', 'platform'))
            ->assertOk()
            ->assertSee('One operating layer')
            ->assertSee('From signal collection to execution.')
            ->assertSee('Monitor AI visibility')
            ->assertSee('A practical loop for modern brand visibility.');
        $this->get(route('marketing.page', 'security'))
            ->assertOk()
            ->assertSee('tenant boundaries')
            ->assertSee('Controls for teams that publish, connect and automate.')
            ->assertSee('Connector accountability')
            ->assertSee('Security that follows the work.');
        $this->get(route('marketing.page', 'about'))
            ->assertOk()
            ->assertSee('Argusly helps teams understand how AI talks about their brand.')
            ->assertSee('Built for the teams responsible for modern brand visibility.')
            ->assertSee('Content operators')
            ->assertSee('Visibility is becoming an operating discipline.');
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

    public function test_contact_page_renders_and_stores_contact_request(): void
    {
        Mail::fake();

        $this->get(route('marketing.contact'))
            ->assertOk()
            ->assertSee('Talk to Argusly.')
            ->assertSee('Send message')
            ->assertSee(route('marketing.signup'), false)
            ->assertSee(route('marketing.page', 'privacy'), false)
            ->assertSee(route('marketing.page', 'terms'), false);

        $this->get(route('marketing.contact', ['topic' => 'sales']))
            ->assertOk()
            ->assertSee('<option value="sales" selected', false);

        $this->post(route('marketing.contact.store'), [
            'name' => 'Jane Contact',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'website' => 'https://example.com',
            'topic' => 'sales',
            'message' => 'We want to understand Argusly for our marketing team.',
            'consent' => '1',
        ])->assertRedirect(route('marketing.contact'));

        $this->assertDatabaseHas('contact_requests', [
            'name' => 'Jane Contact',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'website' => 'https://example.com',
            'topic' => 'sales',
            'status' => 'new',
        ]);

        Mail::assertQueued(ContactRequestSubmitted::class, function (ContactRequestSubmitted $mail): bool {
            return $mail->hasTo('hello@argusly.com')
                && $mail->contactRequest['email'] === 'jane@example.com'
                && $mail->contactRequest['topic'] === 'sales';
        });
    }

    public function test_contact_request_marks_low_quality_leads_as_unqualified(): void
    {
        Mail::fake();

        $this->post(route('marketing.contact.store'), [
            'name' => 'RobertJoicy',
            'email' => 'zekisuquc419@gmail.com',
            'company' => 'google',
            'topic' => 'other',
            'message' => 'Hola, volia saber el seu preu.',
            'consent' => '1',
        ])->assertRedirect(route('marketing.contact'));

        $this->assertDatabaseHas('contact_requests', [
            'name' => 'RobertJoicy',
            'email' => 'zekisuquc419@gmail.com',
            'company' => 'google',
            'topic' => 'other',
            'status' => 'unqualified',
        ]);

        Mail::assertQueued(ContactRequestSubmitted::class, function (ContactRequestSubmitted $mail): bool {
            return $mail->contactRequest['lead_quality'] === 'Low'
                && $mail->contactRequest['lead_score'] < 45
                && in_array('Random-looking email handle', $mail->contactRequest['lead_signals'], true)
                && in_array('Generic large-company claim', $mail->contactRequest['lead_signals'], true);
        });
    }

    public function test_contact_request_honeypot_drops_bot_submission(): void
    {
        Mail::fake();

        $this->post(route('marketing.contact.store'), [
            'name' => 'Bot Contact',
            'email' => 'bot@example.com',
            'company' => 'Bot Inc',
            'topic' => 'sales',
            'message' => 'We want to understand Argusly for our marketing team.',
            'homepage' => 'https://spam.example',
            'consent' => '1',
        ])->assertRedirect(route('marketing.contact'));

        $this->assertDatabaseMissing('contact_requests', [
            'email' => 'bot@example.com',
        ]);

        Mail::assertNothingQueued();
    }

    public function test_contact_request_requires_privacy_and_terms_consent(): void
    {
        $this->post(route('marketing.contact.store'), [
            'name' => 'Jane Contact',
            'email' => 'jane@example.com',
            'topic' => 'sales',
            'message' => 'We want to understand Argusly.',
        ])->assertSessionHasErrors('consent');
    }

    public function test_blog_is_offline_until_content_is_ready(): void
    {
        $this->get('/blog')->assertNotFound();
        $this->get('/blog/ai-visibility-operations')->assertNotFound();
    }
}
