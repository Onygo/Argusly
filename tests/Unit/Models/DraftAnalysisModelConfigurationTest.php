<?php

use App\Models\Draft;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

it('defines latest draft analysis and analysis history relations', function () {
    $draft = new Draft();

    expect($draft->analysis())->toBeInstanceOf(HasOne::class)
        ->and($draft->analyses())->toBeInstanceOf(HasMany::class);
});
