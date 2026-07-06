<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the overview and platform product pages through dedicated product partials', function () {
    config(['argusly.launch.soft_launch_mode' => false]);

    $overview = $this->get('/nl/product/overzicht');
    $overview->assertOk();
    $overview->assertSee('Productoverzicht', false);
    $overview->assertSee('data-page="product-overview"', false);
    $overview->assertSee('Wat je krijgt', false);
    $overview->assertDontSee('Het Argusly platform', false);

    $platform = $this->get('/nl/product/platform');
    $platform->assertOk();
    $platform->assertSee('De operating layer voor governed contentteams', false);
    $platform->assertSee('id="capabilities"', false);
    $platform->assertSee('id="governance"', false);
    $platform->assertSee('id="intelligence"', false);
    $platform->assertDontSee('Wat je krijgt', false);
});
