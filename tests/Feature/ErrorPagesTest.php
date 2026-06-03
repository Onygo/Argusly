<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_not_found_errors_use_the_argusly_error_page(): void
    {
        $this->get('/definitely-not-a-real-argusly-page')
            ->assertNotFound()
            ->assertSee('This page dropped out of the index.')
            ->assertSee('Lost signal')
            ->assertSee('Go to homepage');
    }

    public function test_server_errors_use_the_argusly_error_page(): void
    {
        Route::get('/_test/server-error', fn () => abort(500));

        $this->get('/_test/server-error')
            ->assertStatus(500)
            ->assertSee('Our orchestration engine missed a beat.')
            ->assertSee('Internal signal noise')
            ->assertSee('Back to safety');
    }
}
