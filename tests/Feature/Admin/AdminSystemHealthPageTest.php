<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows webhook queue failed jobs as queue-filtered 24h count with all-time queue total', function () {
    $admin = makeAdminSystemHealthUser();
    config(['argusly.webhooks.queue' => 'default']);

    DB::table('jobs')->insert([
        'queue' => 'generation',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerationJob'], JSON_UNESCAPED_SLASHES),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(12)->timestamp,
        'created_at' => now()->subMinutes(12)->timestamp,
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RecentDefaultJob'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: recent default failure',
        'failed_at' => now()->subHours(2),
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\OldDefaultJob'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: old default failure',
        'failed_at' => now()->subDays(3),
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'deliveries',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RecentDeliveriesJob'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: recent deliveries failure',
        'failed_at' => now()->subHours(1),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.system-health.index'))
        ->assertOk()
        ->assertSee('Webhook Queue')
        ->assertSee('Queue summary')
        ->assertSee('default')
        ->assertSee('Failed jobs (24h): 1')
        ->assertSee('All-time failed jobs on queue: 2')
        ->assertSee('generation')
        ->assertSee('Stuck')
        ->assertSee(route('admin.queues.index', ['pending_queue' => 'generation']), false);
});

function makeAdminSystemHealthUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin System Health Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-system-health-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin System Health User',
        'email' => 'admin-system-health+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
