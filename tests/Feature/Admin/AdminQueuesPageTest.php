<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows queue overview, pending jobs, and failed job details on admin queue pages', function () {
    $admin = makeAdminQueuesUser();

    DB::table('jobs')->insert([
        'queue' => 'generation',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\GenerateArticleJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'attempts' => 1,
        ], JSON_UNESCAPED_SLASHES),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(11)->timestamp,
        'created_at' => now()->subMinutes(11)->timestamp,
    ]);

    $payload = [
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\DemoFailingJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'attempts' => 2,
        'data' => [
            'organization_id' => 42,
            'site_id' => 'site_123',
            'api_key' => 'sk_test_secret_key_123',
        ],
    ];

    $id = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        'exception' => "RuntimeException: Boom\nStack trace line 1",
        'failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.queues.index'))
        ->assertOk()
        ->assertSee('Queue overview')
        ->assertSee('Pending jobs')
        ->assertSee('Failed jobs')
        ->assertSee('App\\Jobs\\GenerateArticleJob')
        ->assertSee('App\\Jobs\\DemoFailingJob')
        ->assertSee('Org 42')
        ->assertSee('Site site_123')
        ->assertSee('Stuck');

    $this->actingAs($admin)
        ->get(route('admin.queues.show', (string) $id))
        ->assertOk()
        ->assertSee('Failed job detail')
        ->assertSee('RuntimeException: Boom')
        ->assertSee('[REDACTED]')
        ->assertDontSee('sk_test_secret_key_123');
});

function makeAdminQueuesUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Queues Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-queues-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Queues User',
        'email' => 'admin-queues+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
