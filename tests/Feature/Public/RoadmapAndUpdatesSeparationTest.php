<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps roadmap future focused and does not show released updates', function () {
    // The roadmap page should focus on future plans, not released updates
    // Product updates have been removed from public pages but data remains for internal use

    $this->get(route('public.company.roadmap'))
        ->assertOk()
        ->assertSee(__('public.roadmap.focus_title'));
});
