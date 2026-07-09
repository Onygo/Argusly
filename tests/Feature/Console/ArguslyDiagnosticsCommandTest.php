<?php

it('shows content image storage diagnostics', function (): void {
    $this->artisan('argusly:diagnostics')
        ->expectsOutputToContain('images.disk')
        ->expectsOutputToContain('images.path')
        ->expectsOutputToContain('images.storage_dir')
        ->expectsOutputToContain('images.public_link')
        ->assertExitCode(0);
});
