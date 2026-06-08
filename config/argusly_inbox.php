<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Public Inbox Route Path
    |--------------------------------------------------------------------------
    |
    | The connector package defaults this to "content/{slug}", which conflicts
    | with the app's own "/content/{content}" route. Use a dedicated prefix so
    | app content detail links always resolve to AppContentController.
    |
    */
    'public' => [
        'path' => env('ARGUSLY_INBOX_PUBLIC_PATH', 'inbox/content/{slug}'),
    ],
];

