<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists pending jobs with queue details and context', function () {
    $admin = makeAdminPendingQueueUser();

    DB::table('jobs')->insert([
        'queue' => 'generation',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\GenerateStoryJob',
            'data' => ['organization_id' => 99, 'site_id' => 'site_alpha'],
        ], JSON_UNESCAPED_SLASHES),
        'attempts' => 2,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(20)->timestamp,
        'created_at' => now()->subMinutes(20)->timestamp,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.queues.index'))
        ->assertOk()
        ->assertSee('GenerateStoryJob')
        ->assertSee('Org 99')
        ->assertSee('Site site_alpha')
        ->assertSee('minutes ago');
});

it('renders admin chrome in dutch when requested', function () {
    $admin = makeAdminPendingQueueUser();

    $this->actingAs($admin)
        ->get(route('admin.queues.index', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('lang="nl"', false)
        ->assertSee('Wachtrijen')
        ->assertSee('Systeemstatus');
});

it('shows pending job details with decoded metadata and redacted payload', function () {
    $admin = makeAdminPendingQueueUser();

    $jobId = DB::table('jobs')->insertGetId([
        'queue' => 'generation',
        'payload' => json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'App\\Jobs\\GenerateQueuedArticleJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'timeout' => 120,
            'data' => [
                'organization_id' => 123,
                'site_id' => 'site_detail',
                'api_key' => 'sk_secret_detail',
            ],
        ], JSON_UNESCAPED_SLASHES),
        'attempts' => 1,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->subMinutes(4)->timestamp,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.queues.pending.show', $jobId))
        ->assertOk()
        ->assertSee('Pending job detail')
        ->assertSee('GenerateQueuedArticleJob')
        ->assertSee('Job metadata')
        ->assertSee('Org 123')
        ->assertSee('Site site_detail')
        ->assertSee('[REDACTED]')
        ->assertDontSee('sk_secret_detail');
});

it('shows a helpful pending job missing page instead of a bare 404', function () {
    $admin = makeAdminPendingQueueUser();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\NearbyPendingJob'], JSON_UNESCAPED_SLASHES),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->subMinute()->timestamp,
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'generation',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RecentlyFailedJob'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: job failed after pickup',
        'failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.queues.pending.show', 203130))
        ->assertOk()
        ->assertSee('Pending job not found')
        ->assertSee('Job #203130 is no longer present')
        ->assertSee('Recent failed jobs')
        ->assertSee('RecentlyFailedJob');
});

it('filters pending jobs by queue, job class, age, org site, and search text', function () {
    $admin = makeAdminPendingQueueUser();

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\OldDigestJob',
                'data' => ['organization_id' => 5],
            ], JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subDays(2)->timestamp,
            'created_at' => now()->subDays(2)->timestamp,
        ],
        [
            'queue' => 'generation',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\FreshGenerationJob',
                'data' => ['site_id' => 'site_match', 'custom' => 'needle-text'],
            ], JSON_UNESCAPED_SLASHES),
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->timestamp,
            'created_at' => now()->subMinutes(15)->timestamp,
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.queues.index', [
            'pending_queue' => 'generation',
            'pending_job_class' => 'FreshGenerationJob',
            'pending_age_range' => '1h',
            'pending_org_site' => 'site_match',
            'pending_search' => 'needle-text',
        ]))
        ->assertOk()
        ->assertSee('FreshGenerationJob')
        ->assertDontSee('OldDigestJob');
});

it('deletes a pending job and shows success only when state changes', function () {
    $admin = makeAdminPendingQueueUser();

    $jobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DeletePendingJob'], JSON_UNESCAPED_SLASHES),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->subMinutes(5)->timestamp,
    ]);

    $response = $this->actingAs($admin)
        ->delete(route('admin.queues.pending.destroy', $jobId));

    $response->assertRedirect(route('admin.queues.index'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('Pending job deleted.')
        ->assertDontSee('DeletePendingJob');

    expect(DB::table('jobs')->where('id', $jobId)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'queue.pending.deleted')->where('subject_id', (string) $jobId)->exists())->toBeTrue();
});

function makeAdminPendingQueueUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Pending Queue Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-pending-queue-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Pending Queue User',
        'email' => 'admin-pending-queue+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
