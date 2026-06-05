<?php

use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Branding render', function () {
    it('renders configured public footer branding and brand meta tags', function () {
        $response = $this->get(route('landing'));

        $ownershipLine = sprintf('%s is a product by %s', Brand::product(), Brand::parent());

        $response->assertOk()
            ->assertSee('name="author" content="'.e(Brand::parent()).'"', false)
            ->assertSee('name="application-name" content="'.e(Brand::product()).'"', false);

        if (config('brand.show_parent_branding', true)) {
            $response->assertSee($ownershipLine);
        } else {
            $response->assertDontSee($ownershipLine);
        }
    });

    it('renders secondary parent branding on auth pages', function () {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee(Brand::product())
            ->assertSee(Brand::full());
    });

    it('renders ownership branding on privacy and terms pages', function () {
        $ownershipLine = __('public.legal.ownership_line', [
            'product' => Brand::product(),
            'parent' => Brand::parent(),
        ]);

        $this->get(route('public.legal.privacy'))
            ->assertOk()
            ->assertSee($ownershipLine);

        $this->get(route('public.legal.terms'))
            ->assertOk()
            ->assertSee($ownershipLine);
    });
});
