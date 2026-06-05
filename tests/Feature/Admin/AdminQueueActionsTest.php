<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('can requeue a pending job to another queue and write an audit log', function () {
    $admin = makeAdminQueueActionUser();

    $jobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\SendDigestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        ], JSON_UNESCAPED_SLASHES),
        'attempts' => 3,
        'reserved_at' => now()->subMinutes(2)->timestamp,
        'available_at' => now()->subMinutes(2)->timestamp,
        'created_at' => now()->subMinutes(30)->timestamp,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.queues.pending.requeue', $jobId), ['queue' => 'deliveries'])
        ->assertRedirect(route('admin.queues.index'));

    $job = DB::table('jobs')->where('id', $jobId)->first();

    expect($job)->not->toBeNull()
        ->and($job->queue)->toBe('deliveries')
        ->and((int) $job->attempts)->toBe(0)
        ->and($job->reserved_at)->toBeNull();

    expect(AuditLog::query()->where('action', 'queue.pending.requeued')->where('subject_id', (string) $jobId)->exists())->toBeTrue();
});

it('can retry a failed job back into the database queue and write an audit log', function () {
    $admin = makeAdminQueueActionUser();

    $failedJobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'generation',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\GenerateDraftJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'attempts' => 1,
        ], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: Failure',
        'failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.queues.retry', $failedJobId))
        ->assertRedirect(route('admin.queues.index'));

    expect(DB::table('failed_jobs')->where('id', $failedJobId)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'generation')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'queue.failed.retried')->where('subject_id', (string) $failedJobId)->exists())->toBeTrue();
});

it('can bulk delete selected failed jobs', function () {
    $admin = makeAdminQueueActionUser();

    $firstId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\One'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: one',
        'failed_at' => now(),
    ]);

    $secondId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Two'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: two',
        'failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.queues.destroy-bulk'), ['job_ids' => [(string) $firstId]])
        ->assertRedirect(route('admin.queues.index'));

    expect(DB::table('failed_jobs')->where('id', $firstId)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->where('id', $secondId)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'queue.failed.deleted_bulk')->exists())->toBeTrue();
});

it('deleting an existing failed job removes it from failed_jobs and the refreshed queue page', function () {
    $admin = makeAdminQueueActionUser();

    $failedJobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DeleteMe'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: remove me',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->delete(route('admin.queues.destroy', $failedJobId));

    $response->assertRedirect(route('admin.queues.index'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('Failed job deleted.')
        ->assertDontSee('App\\Jobs\\DeleteMe');

    expect(DB::table('failed_jobs')->where('id', $failedJobId)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'queue.failed.deleted')->where('subject_id', (string) $failedJobId)->exists())->toBeTrue();
});

it('deleting a non-existing failed job returns a graceful error without a success message', function () {
    $admin = makeAdminQueueActionUser();

    $response = $this->from(route('admin.queues.index'))
        ->actingAs($admin)
        ->delete(route('admin.queues.destroy', 999999));

    $response->assertRedirect(route('admin.queues.index'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('Failed job not found.')
        ->assertDontSee('Failed job deleted.');
});

it('forbids unauthorized users from deleting failed jobs', function () {
    [$organization, $client] = makeNonAdminQueueActionUser();

    $failedJobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\ForbiddenDelete'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: forbidden',
        'failed_at' => now(),
    ]);

    $this->actingAs($client)
        ->delete(route('admin.queues.destroy', $failedJobId))
        ->assertStatus(403);

    expect(DB::table('failed_jobs')->where('id', $failedJobId)->exists())->toBeTrue();
});

it('logs delete failures and does not show success when deletion throws', function () {
    $admin = makeAdminQueueActionUser();

    $failedJobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\StuckDelete'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: still there',
        'failed_at' => now(),
    ]);

    Log::spy();

    app()->instance('queue.failer', new class
    {
        public function forget(string $id): bool
        {
            throw new RuntimeException('delete exploded');
        }
    });

    $response = $this->from(route('admin.queues.index'))
        ->actingAs($admin)
        ->delete(route('admin.queues.destroy', $failedJobId));

    $response->assertRedirect(route('admin.queues.index'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('Delete failed: delete exploded')
        ->assertDontSee('Failed job deleted.');

    expect(DB::table('failed_jobs')->where('id', $failedJobId)->exists())->toBeTrue();

    Log::shouldHaveReceived('error')->once();
});

it('can flush a queue and retry all failed jobs for a queue', function () {
    $admin = makeAdminQueueActionUser();

    DB::table('jobs')->insert([
        [
            'queue' => 'tracking',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\TrackA'], JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(20)->timestamp,
        ],
        [
            'queue' => 'tracking',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\TrackB'], JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ],
    ]);

    DB::table('failed_jobs')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'tracking',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\TrackFailA'], JSON_UNESCAPED_SLASHES),
            'exception' => 'RuntimeException: A',
            'failed_at' => now(),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'tracking',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\TrackFailB'], JSON_UNESCAPED_SLASHES),
            'exception' => 'RuntimeException: B',
            'failed_at' => now(),
        ],
    ]);

    $this->actingAs($admin)
        ->post(route('admin.queues.pending.flush'), ['queue' => 'tracking'])
        ->assertRedirect(route('admin.queues.index'));

    expect(DB::table('jobs')->where('queue', 'tracking')->count())->toBe(0);

    $this->actingAs($admin)
        ->post(route('admin.queues.retry-all'), ['queue' => 'tracking'])
        ->assertRedirect(route('admin.queues.index'));

    expect(DB::table('failed_jobs')->where('queue', 'tracking')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'tracking')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'queue.pending.flushed')->where('subject_id', 'tracking')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'queue.failed.retried_bulk')->where('subject_id', 'tracking')->exists())->toBeTrue();
});

it('shows a useful error when retry all fails before state transition completes', function () {
    $admin = makeAdminQueueActionUser();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RetryAllBoom'], JSON_UNESCAPED_SLASHES),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    app()->instance('queue.failer', new class
    {
        public function forget(string $id): bool
        {
            throw new RuntimeException('bulk retry exploded');
        }
    });

    $response = $this->from(route('admin.queues.index'))
        ->actingAs($admin)
        ->post(route('admin.queues.retry-all'));

    $response->assertRedirect(route('admin.queues.index'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('Bulk retry failed: bulk retry exploded');
});

function makeAdminQueueActionUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Queue Action Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-queue-action-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Queue Action User',
        'email' => 'admin-queue-actions+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}

function makeNonAdminQueueActionUser(): array
{
    $organization = Organization::query()->create([
        'name' => 'Client Queue Org ' . Str::lower(Str::random(4)),
        'slug' => 'client-queue-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $user = User::query()->create([
        'name' => 'Client Queue User',
        'email' => 'client-queue+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
        'admin_role' => null,
    ]);

    return [$organization, $user];
}
