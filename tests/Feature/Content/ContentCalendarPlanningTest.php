<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-25 09:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeContentCalendarContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Calendar Org',
        'slug' => 'calendar-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Calendar BV',
        'billing_address_line1' => 'Planstraat 12',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Calendar Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Calendar Site',
        'site_url' => 'https://calendar.example.com',
        'base_url' => 'https://calendar.example.com',
        'allowed_domains' => ['calendar.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$user, $workspace, $site];
}

function createCalendarContent(Workspace $workspace, ClientSite $site, User $user, array $overrides = []): Content
{
    $withBrief = ($overrides['with_brief'] ?? false) === true;
    unset($overrides['with_brief']);

    $content = Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Calendar item '.Str::random(4),
        'primary_keyword' => 'calendar planning',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
        'scheduled_publish_at' => Carbon::parse('2026-03-25 10:00:00'),
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ], $overrides));

    if ($withBrief) {
        Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $site->id,
            'content_id' => (string) $content->id,
            'created_by_user_id' => (int) $user->id,
            'status' => 'draft',
            'source' => 'client_ui',
            'title' => (string) $content->title,
            'language' => 'en',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'progress' => 0,
        ]);
    }

    return $content->fresh(['brief', 'clientSite', 'publications']);
}

function calendarResponseHtml(\Illuminate\Testing\TestResponse $response): string
{
    return (string) $response->getContent();
}

function calendarTagAttribute(string $html, string $selector, string $attribute): ?string
{
    if ($html === '' || ($selector !== '' && ! str_contains($html, $selector))) {
        return null;
    }

    if (preg_match('/<[^>]*\b'.$attribute.'="([^"]+)"/', $html, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

function calendarDayCardHtml(string $html, string $dateKey): string
{
    preg_match('/<article[^>]*data-calendar-day-card[^>]*data-day-key="'.preg_quote($dateKey, '/').'"[^>]*>.*?<\/article>/s', $html, $matches);

    return $matches[0] ?? '';
}

describe('Calendar Navigation', function () {
    it('renders prev/today/next navigation buttons', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('Vandaag')
            ->assertSee('aria-label="Previous month"', false)
            ->assertSee('aria-label="Next month"', false);
    });

    it('renders month/week view toggle buttons', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $html = (string) $response->getContent();

        $response->assertOk();
        expect($html)->toContain('Maand');
        expect($html)->toContain('Week');
        expect($html)->toContain('Dag');
        expect($html)->toContain('inline-flex rounded-lg border border-border bg-surface');
    });

    it('shows current period label in toolbar', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('March 2026');
    });
});

describe('Month View Structure', function () {
    it('renders weekday labels in the calendar header', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $html = (string) $response->getContent();

        $response->assertOk();

        $weekdayBlockHtml = Str::between($html, 'data-calendar-weekdays>', 'data-calendar-day-grid');
        preg_match_all('/>\s*(Ma|Di|Wo|Do|Vr|Za|Zo)\s*</', $weekdayBlockHtml, $weekdayMatches);

        expect($weekdayBlockHtml)->not->toBe('');
        expect(array_slice($weekdayMatches[1], 0, 7))->toBe(['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo']);
    });

    it('renders month view as a 7 column grid with day cards', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user, [
            'scheduled_publish_at' => Carbon::parse('2026-03-19 10:00:00'),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'month',
            'date' => '2026-03-19',
        ]));

        $response->assertOk();

        $html = calendarResponseHtml($response);

        expect(substr_count($html, 'data-calendar-month'))->toBe(1);
        expect(substr_count($html, 'data-calendar-day-grid'))->toBe(1);
        expect(substr_count($html, 'data-calendar-day-card'))->toBe(42);
        expect(substr_count($html, 'data-calendar-weekdays'))->toBe(1);
        expect($html)->toContain('grid grid-cols-7');
    });

    it('renders day cards with visible borders', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('data-calendar-day-card', false)
            ->assertSee('border-border', false);
    });
});

describe('Day States', function () {
    it('renders today with primary background on day number', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('bg-primary text-textInverse', false);
    });

    it('renders past days with muted styling', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $html = calendarResponseHtml($response);
        $pastCardClass = calendarTagAttribute(calendarDayCardHtml($html, '2026-03-24'), 'data-day-key="2026-03-24"', 'class');

        expect($pastCardClass)->toContain('bg-surfaceSubtle/40');
    });

    it('renders future days with active surface styling', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $html = calendarResponseHtml($response);
        $futureCardClass = calendarTagAttribute(calendarDayCardHtml($html, '2026-03-26'), 'data-day-key="2026-03-26"', 'class');

        expect($futureCardClass)->toContain('bg-surface');
    });

    it('renders selected day with ring highlight', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-26',
        ]));

        $response->assertOk()
            ->assertSee('ring-2 ring-inset ring-primary/30', false);
    });
});

describe('Empty Day Behavior', function () {
    it('keeps empty days visually quiet without large empty state blocks', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertDontSee('Nothing planned yet.')
            ->assertDontSee('Use this day to start a brief or line up a publish slot.')
            ->assertDontSee('View day');
    });

    it('renders past days without create action in month view', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $html = calendarResponseHtml($response);
        $pastCardHtml = calendarDayCardHtml($html, '2026-03-24');

        $response->assertOk()
            ->assertSee('data-calendar-day-card', false);

        expect($pastCardHtml)->not->toContain('data-calendar-day-add');
    });

    it('shows create affordance on hover for future empty days', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $html = calendarResponseHtml($response);
        $futureCardHtml = calendarDayCardHtml($html, '2026-03-27');

        $response->assertOk()
            ->assertSee('opacity-0', false)
            ->assertSee('group-hover:opacity-100', false);

        expect($futureCardHtml)
            ->toContain('data-calendar-day-add')
            ->toContain('plan content');
    });
});

describe('Content Items', function () {
    it('renders content items inside the correct day card', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user, [
            'title' => 'March nineteenth item',
            'publish_status' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-03-19 10:00:00'),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'month',
            'date' => '2026-03-19',
        ]));

        $response->assertOk();

        $html = calendarResponseHtml($response);
        $targetCardHtml = calendarDayCardHtml($html, '2026-03-19');

        expect($targetCardHtml)->not->toBe('');
        expect($targetCardHtml)->toContain('March nineteenth item');
        expect(calendarDayCardHtml($html, '2026-03-18'))->not->toContain('March nineteenth item');
    });

    it('renders published items from publication delivery timestamps when no scheduled date remains', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        $content = createCalendarContent($workspace, $site, $user, [
            'title' => 'Publication dated item',
            'status' => 'published',
            'delivery_status' => 'delivered',
            'publish_status' => 'published',
            'scheduled_publish_at' => null,
        ]);

        ContentPublication::query()->create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '12345',
            'remote_type' => 'post',
            'remote_url' => 'https://calendar.example.com/publication-dated-item',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'remote_status' => ContentPublication::REMOTE_PUBLISHED,
            'last_delivered_at' => Carbon::parse('2026-03-22 14:30:00'),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'month',
            'date' => '2026-03-25',
        ]));

        $response->assertOk();

        $html = calendarResponseHtml($response);
        $targetCardHtml = calendarDayCardHtml($html, '2026-03-22');

        expect($targetCardHtml)->toContain('Publication dated item');
        expect(calendarDayCardHtml($html, '2026-03-25'))->not->toContain('Publication dated item');
    });

    it('uses the earliest successful publication timestamp and avoids duplicate day entries', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        $content = createCalendarContent($workspace, $site, $user, [
            'title' => 'Multi publication item',
            'status' => 'published',
            'delivery_status' => 'delivered',
            'publish_status' => 'published',
            'scheduled_publish_at' => null,
        ]);

        ContentPublication::query()->create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '20001',
            'remote_type' => 'post',
            'remote_url' => 'https://calendar.example.com/multi-publication-item',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'remote_status' => ContentPublication::REMOTE_PUBLISHED,
            'last_delivered_at' => Carbon::parse('2026-03-23 08:00:00'),
        ]);

        ContentPublication::query()->create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '20002',
            'remote_type' => 'post',
            'remote_url' => 'https://calendar.example.com/multi-publication-item-2',
            'delivery_status' => 'partial_success',
            'remote_status' => ContentPublication::REMOTE_PUBLISHED,
            'last_delivered_at' => Carbon::parse('2026-03-25 17:45:00'),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'day',
            'date' => '2026-03-23',
        ]));

        $response->assertOk()
            ->assertSee('Multi publication item');

        expect(substr_count(calendarResponseHtml($response), 'Multi publication item'))->toBe(1);

        $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'day',
            'date' => '2026-03-25',
        ]))
            ->assertOk()
            ->assertDontSee('Multi publication item');
    });

    it('shows at most two items in a day cell and summarizes the rest', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user, ['title' => 'First stacked item']);
        createCalendarContent($workspace, $site, $user, ['title' => 'Second stacked item']);
        createCalendarContent($workspace, $site, $user, ['title' => 'Third stacked item']);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk();

        $dayCardHtml = calendarDayCardHtml(calendarResponseHtml($response), '2026-03-25');

        expect($dayCardHtml)->toContain('First stacked item');
        expect($dayCardHtml)->toContain('Second stacked item');
        expect($dayCardHtml)->toContain('+1 meer');
        expect(substr_count($dayCardHtml, 'group/chip'))->toBe(2);
    });

    it('renders status dots with correct colors', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user, [
            'title' => 'Scheduled item',
            'publish_status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('bg-sky-500', false);
    });
});

describe('Week View', function () {
    it('renders week view with 7 day columns', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'week',
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('data-calendar-week', false)
            ->assertSee('lg:grid-cols-7', false);
    });

    it('renders week view with taller day cards than month view', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'week',
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('min-h-[24rem]', false);
    });

    it('shows date range for week view', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'mode' => 'week',
            'date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('23 Mar')
            ->assertSee('29 Mar 2026');
    });
});

describe('Selected Day Panel', function () {
    it('renders selected day details for the requested date only', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        createCalendarContent($workspace, $site, $user, [
            'title' => 'Selected day item',
            'scheduled_publish_at' => Carbon::parse('2026-03-25 11:00:00'),
        ]);

        createCalendarContent($workspace, $site, $user, [
            'title' => 'Another day item',
            'scheduled_publish_at' => Carbon::parse('2026-03-26 11:00:00'),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
        ]));

        $drawerHtml = Str::after((string) $response->getContent(), 'id="calendar-day-details-title"');

        $response->assertOk()
            ->assertSee('Wednesday, 25 March 2026')
            ->assertSee('Selected day item');

        expect($drawerHtml)
            ->toContain('Selected day item')
            ->not->toContain('Another day item');
    });

    it('renders past day panel without create CTA', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-24',
        ]));

        $html = (string) $response->getContent();
        $panelHtml = Str::after($html, 'id="calendar-day-details-title"');

        $response->assertOk()
            ->assertSee('Verleden', false)
            ->assertSee('Geen content gepland');

        expect($panelHtml)->not->toContain('>Nieuw<');
    });

    it('renders future day panel with create CTA', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-26',
        ]));

        $response->assertOk()
            ->assertSee('Plan content')
            ->assertSee('>Nieuw<', false);
    });

    it('closes the selected day panel by clearing selected_date', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
        ]));

        $closeUrl = route('app.content.calendar', [
            'mode' => 'month',
            'date' => '2026-03-25',
        ]);

        $response->assertOk()
            ->assertSee('aria-label="Close"', false)
            ->assertSee(e($closeUrl), false);

        $this->actingAs($user)
            ->get($closeUrl)
            ->assertOk()
            ->assertDontSee('id="calendar-day-details-title"', false);
    });
});

describe('Content Actions', function () {
    it('does not render publish now for published calendar items', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        $content = createCalendarContent($workspace, $site, $user, [
            'title' => 'Published calendar item',
            'status' => 'published',
            'delivery_status' => 'delivered',
            'publish_status' => 'published',
            'published_url' => 'https://calendar.example.com/published-calendar-item',
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('Published calendar item')
            ->assertSee('Published')
            ->assertSee('View')
            ->assertDontSee('Publish now');

        $response->assertSee(e('https://calendar.example.com/published-calendar-item'), false);
        $response->assertDontSee(e(route('app.content.publish-now', $content)), false);
    });

    it('renders publish now for scheduled calendar items', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        $content = createCalendarContent($workspace, $site, $user, [
            'title' => 'Scheduled calendar item',
            'status' => 'draft',
            'delivery_status' => 'pending',
            'publish_status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('Scheduled calendar item')
            ->assertSee('Scheduled')
            ->assertSee('Publish now')
            ->assertSee('Reschedule');

        $response->assertSee(e(route('app.content.publish-now', $content)), false);
    });

    it('renders draft calendar items with continue and schedule actions', function () {
        [$user, $workspace, $site] = makeContentCalendarContext();

        $content = createCalendarContent($workspace, $site, $user, [
            'title' => 'Draft calendar item',
            'status' => 'brief',
            'publish_status' => 'draft',
            'with_brief' => true,
        ]);

        $brief = Brief::query()->where('content_id', (string) $content->id)->firstOrFail();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('Draft calendar item')
            ->assertSee('Draft')
            ->assertSee('Continue')
            ->assertSee('Schedule')
            ->assertDontSee('Publish now');

        $response->assertSee(e(route('app.content.workspace.show', $brief)), false);
    });
});

describe('URL State', function () {
    it('includes the selected date and site context in create link', function () {
        [$user, , $site] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
            'site' => (string) $site->id,
        ]));

        $createUrl = route('app.content.index', [
            'create' => 1,
            'scheduled_publish_at' => '2026-03-25T09:00',
            'site_id' => (string) $site->id,
        ]);

        $response->assertOk()
            ->assertSee(e($createUrl), false);
    });

    it('does not propagate selected_date into navigation links', function () {
        [$user] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.calendar', [
            'date' => '2026-03-25',
            'selected_date' => '2026-03-25',
        ]));

        $response->assertOk()
            ->assertSee('href="'.route('app.content.index').'"', false)
            ->assertDontSee('href="'.route('app.content.index').'?selected_date=2026-03-25"', false);
    });
});

describe('Content Create Prefill', function () {
    it('prefills the content create form from calendar query parameters', function () {
        [$user, , $site] = makeContentCalendarContext();

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'create' => 1,
            'site_id' => (string) $site->id,
            'scheduled_publish_at' => '2026-03-25T09:00',
        ]));

        $response->assertOk()
            ->assertSee('Prefilled from the content calendar.')
            ->assertSee('value="2026-03-25T09:00"', false)
            ->assertSee('option value="'.$site->id.'" selected', false);
    });
});
