<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows filtered failed jobs count and hint against total failed jobs', function () {
    $admin = makeAdminQueuesFilteringUser();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\OldFailingJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'attempts' => 1,
        ], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: old failure',
        'failed_at' => now()->subDays(3),
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\RecentFailingJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'attempts' => 1,
        ], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: recent failure',
        'failed_at' => now()->subHours(2),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.queues.index', ['range' => '24h']))
        ->assertOk()
        ->assertSee('Filtered 1 of 2 total failed jobs')
        ->assertSee('App\\Jobs\\RecentFailingJob')
        ->assertDontSee('App\\Jobs\\OldFailingJob');
});

function makeAdminQueuesFilteringUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Queues Filter Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-queues-filter-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Queues Filter User',
        'email' => 'admin-queues-filter+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
