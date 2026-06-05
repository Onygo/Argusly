<?php

use App\Support\EarlyAccess;
use App\Support\MarketingNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Early Access Mode Tests
|--------------------------------------------------------------------------
|
| These tests verify that the early access mode provides a consistent,
| focused funnel experience across the public marketing site.
|
*/

describe('EarlyAccess support class', function () {
    it('returns enabled state based on config', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);
        expect(EarlyAccess::enabled())->toBeFalse();

        config(['publishlayer.launch.soft_launch_mode' => true]);
        expect(EarlyAccess::enabled())->toBeTrue();
    });

    it('shows full marketing nav when not in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);
        expect(EarlyAccess::showFullMarketingNav())->toBeTrue();
        expect(EarlyAccess::showEarlyAccessCTA())->toBeFalse();
    });

    it('shows early access CTA when in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);
        expect(EarlyAccess::showFullMarketingNav())->toBeFalse();
        expect(EarlyAccess::showEarlyAccessCTA())->toBeTrue();
    });

    it('allows legal pages in all modes', function () {
        $legalPages = ['legal.index', 'legal.privacy', 'legal.terms', 'legal.security', 'legal.cookies', 'legal.subprocessors'];

        config(['publishlayer.launch.soft_launch_mode' => true]);

        foreach ($legalPages as $page) {
            expect(EarlyAccess::allowPublicMarketingPage($page))->toBeTrue("Legal page {$page} should be accessible in early access mode");
            expect(EarlyAccess::isLegalPage($page))->toBeTrue();
        }
    });

    it('blocks marketing pages in early access mode', function () {
        $blockedPages = ['product.capabilities', 'product.governance', 'product.intelligence', 'company.roadmap', 'blog', 'pricing'];

        config(['publishlayer.launch.soft_launch_mode' => true]);

        foreach ($blockedPages as $page) {
            expect(EarlyAccess::allowPublicMarketingPage($page))->toBeFalse("Marketing page {$page} should be blocked in early access mode");
        }
    });

    it('allows all pages in full marketing mode', function () {
        $allPages = ['product.capabilities', 'product.governance', 'product.intelligence', 'company.roadmap', 'blog', 'pricing', 'landing', 'company.about'];

        config(['publishlayer.launch.soft_launch_mode' => false]);

        foreach ($allPages as $page) {
            expect(EarlyAccess::allowPublicMarketingPage($page))->toBeTrue("Page {$page} should be accessible in full marketing mode");
        }
    });

    it('always allows essential pages regardless of mode', function () {
        $alwaysAllowed = ['landing', 'product.overview', 'company.about', 'company.contact', 'early-access', 'login'];

        foreach ([true, false] as $earlyAccessEnabled) {
            config(['publishlayer.launch.soft_launch_mode' => $earlyAccessEnabled]);

            foreach ($alwaysAllowed as $page) {
                expect(EarlyAccess::allowPublicMarketingPage($page))->toBeTrue("Page {$page} should be accessible in both modes");
            }
        }
    });

    it('returns correct visibility classifications', function () {
        expect(EarlyAccess::getPageVisibility('legal.privacy'))->toBe(EarlyAccess::VISIBILITY_LEGAL);
        expect(EarlyAccess::getPageVisibility('landing'))->toBe(EarlyAccess::VISIBILITY_ALWAYS);
        expect(EarlyAccess::getPageVisibility('product.capabilities'))->toBe(EarlyAccess::VISIBILITY_FULL_MARKETING_ONLY);
    });
});

describe('MarketingNavigation in early access mode', function () {
    beforeEach(function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);
    });

    it('returns minimal header items', function () {
        $items = MarketingNavigation::headerItems();

        // Should only have About and Contact in early access mode
        expect(count($items))->toBe(2);

        $labels = array_column($items, 'label');
        expect($labels)->toContain(__('public.footer.about'));
        expect($labels)->toContain(__('public.footer.contact'));

        // Should not contain marketing pages
        expect($labels)->not->toContain(__('public.nav.platform'));
        expect($labels)->not->toContain(__('public.nav.blog'));
    });

    it('returns early access CTA for header', function () {
        $cta = MarketingNavigation::headerPrimaryCTA();

        expect($cta['route'])->toBe('public.early-access.show');
        expect($cta['label'])->toBe(__('public.nav.early_access'));
    });

    it('returns minimal footer product items', function () {
        $items = MarketingNavigation::footerProductItems();

        expect(count($items))->toBe(1);
        expect($items[0]['route'])->toBe('public.early-access.show');
    });

    it('returns early access focused footer company items', function () {
        $items = MarketingNavigation::footerCompanyItems();

        $routes = array_column($items, 'route');
        expect($routes)->toContain('public.company.about');
        expect($routes)->toContain('public.company.contact');
        expect($routes)->toContain('login');

        // Should not contain roadmap (product updates page has been removed)
        expect($routes)->not->toContain('public.company.roadmap');
    });

    it('returns early access note for footer', function () {
        $note = MarketingNavigation::footerEarlyAccessNote();

        expect($note)->not->toBeNull();
        expect($note)->toBe(__('public.footer.early_access_note'));
    });

    it('returns early access homepage CTAs', function () {
        $primaryCta = MarketingNavigation::homepagePrimaryCTA();
        $bottomCta = MarketingNavigation::landingBottomCTA();

        expect($primaryCta['route'])->toBe('public.early-access.show');
        expect($bottomCta['primary']['route'])->toBe('public.early-access.show');
    });
});

describe('MarketingNavigation in full marketing mode', function () {
    beforeEach(function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);
    });

    it('returns full header items', function () {
        $items = MarketingNavigation::headerItems();

        $routes = array_column($items, 'route');
        expect($routes)->toContain('public.product.overview');
        expect($routes)->toContain('public.product.platform');
        expect($routes)->toContain('public.blog.index');
    });

    it('returns contact CTA for header', function () {
        $cta = MarketingNavigation::headerPrimaryCTA();

        expect($cta['route'])->toBe('public.company.contact');
        expect($cta['label'])->toBe(__('public.nav.contact'));
    });

    it('returns full footer product items', function () {
        $items = MarketingNavigation::footerProductItems();

        $routes = array_column($items, 'route');
        expect($routes)->toContain('public.product.overview');
        expect($routes)->toContain('public.product.platform');
        expect($routes)->toContain('public.blog.index');
    });

    it('returns null for footer early access note', function () {
        $note = MarketingNavigation::footerEarlyAccessNote();

        expect($note)->toBeNull();
    });

    it('returns pricing homepage CTAs', function () {
        $bottomCta = MarketingNavigation::landingBottomCTA();

        expect($bottomCta['primary']['route'])->toBe('pricing');
    });
});

describe('header navigation visibility', function () {
    it('shows full navigation in full marketing mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee(__('public.nav.overview'));
        $response->assertSee(__('public.nav.platform'));
        $response->assertSee(__('public.nav.blog'));
    });

    it('shows minimal navigation in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee(__('public.footer.about'));
        $response->assertSee(__('public.footer.contact'));

        // These should not appear in the header navigation
        $response->assertDontSee('href="' . route('public.product.platform') . '"', false);
        $response->assertDontSee('href="' . route('public.blog.index') . '"', false);
    });

    it('shows sign in link in both modes', function () {
        foreach ([true, false] as $earlyAccessEnabled) {
            config(['publishlayer.launch.soft_launch_mode' => $earlyAccessEnabled]);

            $response = $this->get(route('landing'));

            $response->assertOk();
            $response->assertSee(__('public.nav.sign_in'));
            $response->assertSee('href="' . route('login') . '"', false);
        }
    });

    it('shows early access CTA in early access mode header', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee(__('public.nav.early_access'));
        $response->assertSee('href="' . route('public.early-access.show', ['intent' => 'early_access']) . '"', false);
    });
});

describe('footer navigation visibility', function () {
    it('shows full product navigation in footer in full marketing mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee(__('public.nav.overview'));
        $response->assertSee(__('public.nav.platform'));
    });

    it('shows minimal product navigation in footer in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get(route('landing'));

        $response->assertOk();

        // Footer should show early access link in product section
        $response->assertSee(__('public.nav.early_access'));

        // Footer should show the early access note
        $response->assertSee(__('public.footer.early_access_note'));
    });

    it('shows legal links in footer in both modes', function () {
        foreach ([true, false] as $earlyAccessEnabled) {
            config(['publishlayer.launch.soft_launch_mode' => $earlyAccessEnabled]);

            $response = $this->get(route('landing'));

            $response->assertOk();
            $response->assertSee(__('public.footer.legal_hub'));
            $response->assertSee(__('public.footer.privacy'));
            $response->assertSee(__('public.footer.terms'));
            $response->assertSee(__('public.footer.security'));
            $response->assertSee(__('public.footer.cookies'));
            $response->assertSee(__('public.footer.subprocessors'));
        }
    });
});

describe('homepage CTA behavior', function () {
    it('shows early access CTA on homepage in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        // The soft-launch page is shown with early access CTAs
        $response->assertSee(__('public.early_access.request_early_access'));
    });

    it('shows pricing CTA on homepage in full marketing mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get(route('landing'));

        $response->assertOk();
        // Full landing page should have the pricing CTA
        $response->assertSee(__('public.landing.cta_view'));
    });
});

describe('route visibility in early access mode', function () {
    beforeEach(function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);
    });

    it('redirects blocked marketing pages to early access page', function () {
        $blockedRoutes = [
            route('public.product.capabilities'),
            route('public.product.governance'),
            route('public.product.intelligence'),
            route('public.company.roadmap'),
            route('public.blog.index'),
            route('pricing'),
        ];

        foreach ($blockedRoutes as $route) {
            $response = $this->get($route);
            $response->assertRedirect(route('public.early-access.show'));
        }
    });

    it('allows legal pages in early access mode', function () {
        $legalRoutes = [
            route('public.legal.index'),
            route('public.legal.privacy'),
            route('public.legal.terms'),
            route('public.legal.security'),
            route('public.legal.cookies'),
            route('public.legal.subprocessors'),
        ];

        foreach ($legalRoutes as $route) {
            $response = $this->get($route);
            $response->assertOk();
        }
    });

    it('allows essential pages in early access mode', function () {
        $essentialRoutes = [
            route('landing'),
            route('public.early-access.show'),
            route('login'),
            route('public.product.overview'),
            route('public.company.about'),
            route('public.company.contact'),
        ];

        foreach ($essentialRoutes as $route) {
            $response = $this->get($route);
            $response->assertOk();
        }
    });
});

describe('route visibility in full marketing mode', function () {
    beforeEach(function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);
    });

    it('allows all marketing pages', function () {
        $okRoutes = [
            route('landing'),
            route('public.product.overview'),
            route('public.product.platform'),
            route('public.company.about'),
            route('public.company.contact'),
            route('public.company.roadmap'),
            route('public.blog.index'),
            route('public.legal.index'),
            route('public.legal.privacy'),
            route('public.legal.terms'),
            route('public.legal.security'),
            route('public.legal.cookies'),
            route('public.legal.subprocessors'),
        ];

        foreach ($okRoutes as $route) {
            $response = $this->get($route);
            $response->assertOk();
        }

        $this->get(route('public.product.capabilities'))->assertRedirect(route('public.product.platform') . '#capabilities');
        $this->get(route('public.product.governance'))->assertRedirect(route('public.product.platform') . '#governance');
        $this->get(route('public.product.intelligence'))->assertRedirect(route('public.product.platform') . '#intelligence');
    });
});

describe('sign in accessibility', function () {
    it('keeps sign in accessible in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get('/login');

        $response->assertOk();
    });

    it('keeps sign in accessible in full marketing mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get('/login');

        $response->assertOk();
    });
});

describe('early access page', function () {
    it('is accessible in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get(route('public.early-access.show'));

        $response->assertOk();
    });

    it('is accessible in full marketing mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get(route('public.early-access.show'));

        $response->assertOk();
    });
});

describe('early access locale switching', function () {
    beforeEach(function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);
    });

    it('shows English early access content by default', function () {
        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee(__('public.early_access.soft_launch_badge', [], 'en'));
        $response->assertSee(__('public.early_access.request_early_access', [], 'en'));
    });

    it('shows Dutch early access content when locale is nl', function () {
        $response = $this->get(route('localized.nl.landing'));

        $response->assertOk();
        $response->assertSee(__('public.early_access.soft_launch_badge', [], 'nl'));
        $response->assertSee(__('public.early_access.request_early_access', [], 'nl'));
    });

    it('shows English early access form content by default', function () {
        $response = $this->get(route('public.early-access.show'));

        $response->assertOk();
        $response->assertSee(__('public.early_access.badge', [], 'en'));
        $response->assertSee(__('public.early_access.choose_request_type', [], 'en'));
        $response->assertSee(__('public.early_access.field_full_name', [], 'en'));
    });

    it('shows Dutch early access form content when locale is nl', function () {
        $response = $this->get(route('localized.nl.public.early-access.show'));

        $response->assertOk();
        $response->assertSee(__('public.early_access.badge', [], 'nl'));
        $response->assertSee(__('public.early_access.choose_request_type', [], 'nl'));
        $response->assertSee(__('public.early_access.field_full_name', [], 'nl'));
    });

    it('shows English login content by default', function () {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee(__('public.auth.login_subtitle', [], 'en'));
        $response->assertSee(__('public.auth.email', [], 'en'));
    });

    it('shows Dutch login content when locale is nl', function () {
        // First visit a page with locale middleware to set session
        $this->get(route('localized.nl.landing'));

        // Then login page should use the session locale
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee(__('public.auth.login_subtitle', [], 'nl'));
        $response->assertSee(__('public.auth.email', [], 'nl'));
    });

    it('persists locale across early access pages', function () {
        // Set Dutch locale on homepage
        $this->get(route('localized.nl.landing'));

        // Early access page should also be in Dutch
        $response = $this->get(route('localized.nl.public.early-access.show'));

        $response->assertOk();
        $response->assertSee(__('public.early_access.badge', [], 'nl'));
    });
});
