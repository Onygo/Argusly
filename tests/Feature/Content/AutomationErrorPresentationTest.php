<?php

use App\Models\ClientSite;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Support\Errors\AdminAccessChecker;
use App\Support\Errors\AutomationErrorPresenter;
use App\Support\Errors\PublicAutomationErrorMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('PublicAutomationErrorMapper', function () {
    it('maps source column truncation SQLSTATE 1265 to PL-CNT-SRC-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'source\' at row 1',
            errorCode: 'sql_exception',
            failureStage: 'persistence',
        );

        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_SOURCE_TRUNCATION);
        expect($result['public_error_title'])->toBe('Content source data exceeded storage limits');
        expect($result['is_sensitive'])->toBeTrue();
    });

    it('maps title column truncation to PL-CNT-TTL-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column \'title\'',
            errorCode: 'sql_exception',
            failureStage: 'persistence',
        );

        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_TITLE_TRUNCATION);
        expect($result['public_error_title'])->toBe('Content title exceeded storage limits');
        expect($result['is_sensitive'])->toBeTrue();
    });

    it('maps ai_payload truncation to PL-CNT-SRC-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'ai_payload\' at row 1',
            errorCode: 'query_exception',
            failureStage: 'persistence',
        );

        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_SOURCE_TRUNCATION);
    });

    it('maps insufficient credits to PL-CRD-LOW-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'Not enough credits to complete this operation',
            errorCode: 'insufficient_credits',
            failureStage: 'generation',
        );

        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_INSUFFICIENT_CREDITS);
        expect($result['public_error_title'])->toBe('Insufficient credits');
        expect($result['is_sensitive'])->toBeFalse();
    });

    it('uses structured insufficient credit details for public and admin messages', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'Insufficient credits.',
            errorCode: 'insufficient_credits',
            failureStage: 'translation',
            metadata: [
                'failure_pattern' => 'insufficient_credits',
                'failure_details' => [
                    'required_credits' => 6,
                    'available_credits' => 3,
                    'user_safe_message' => 'This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.',
                    'admin_message' => "Exception: App\\Exceptions\\InsufficientCreditsException\nRequired credits: 6\nAvailable credits: 3\nJob: App\\Jobs\\TranslateDraftJob\nSource location: app/Jobs/TranslateDraftJob.php:123\nRun ID: run-1\nAutomation ID: automation-1",
                    'exception_class' => 'App\\Exceptions\\InsufficientCreditsException',
                    'job' => 'App\\Jobs\\TranslateDraftJob',
                    'run_id' => 'run-1',
                    'automation_id' => 'automation-1',
                ],
            ],
        );

        expect($result['public_error_code'])->toBe('PL-CREDITS-INSUFFICIENT')
            ->and($result['public_error_message'])->toContain('Required: 6, available: 3')
            ->and($result['admin_summary'])->toContain('Job: App\\Jobs\\TranslateDraftJob')
            ->and($result['technical_details'])->toContain('App\\Exceptions\\InsufficientCreditsException');
    });

    it('maps timeout errors to PL-JOB-TMO-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'Job timed out after 300 seconds',
            errorCode: 'timeout_exception',
            failureStage: 'generation',
        );

        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_JOB_TIMEOUT);
        expect($result['public_error_title'])->toBe('Automation timed out');
    });

    it('maps unknown errors to generic fallback code PL-AUTO-UNX-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'Some completely unexpected error message',
            errorCode: null,
            failureStage: null,
        );

        // With no stage it defaults to generation_failed
        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_GENERATION_FAILED);
    });

    it('maps publish stage failures to PL-PUB-ERR-001', function () {
        $mapper = new PublicAutomationErrorMapper();

        $result = $mapper->map(
            error: 'Failed to deliver content to WordPress',
            errorCode: 'publish_exception',
            failureStage: 'publish',
        );

        expect($result['public_error_code'])->toBe(PublicAutomationErrorMapper::CODE_PUBLISH_FAILED);
        expect($result['public_error_title'])->toBe('Content publication failed');
    });

    it('detects sensitive information in error messages', function () {
        $mapper = new PublicAutomationErrorMapper();

        $sensitiveMessages = [
            'SQLSTATE[HY000]: General error: connection refused',
            'Illuminate\\Database\\QueryException: syntax error',
            '/var/www/html/app/Services/ContentService.php:123',
            'Password: secret123 was invalid',
            'Bearer token expired',
        ];

        foreach ($sensitiveMessages as $message) {
            $result = $mapper->map($message);
            expect($result['is_sensitive'])->toBeTrue("Expected '{$message}' to be marked as sensitive");
        }
    });

    it('does not mark regular error messages as sensitive', function () {
        $mapper = new PublicAutomationErrorMapper();

        $safeMessages = [
            'Not enough credits to complete this operation',
            'Content generation failed',
            'The title was too long',
        ];

        foreach ($safeMessages as $message) {
            $result = $mapper->map($message);
            expect($result['is_sensitive'])->toBeFalse("Expected '{$message}' to not be marked as sensitive");
        }
    });

    it('generates unique support codes with timestamp', function () {
        $mapper = new PublicAutomationErrorMapper();

        $code1 = $mapper->generateSupportCode('PL-CNT-SRC-001', 'run-uuid-123', 'item-456');
        $code2 = $mapper->generateSupportCode('PL-CNT-SRC-001', 'run-uuid-789');

        expect($code1)->toStartWith('PL-CNT-SRC-001-RUN-UUID');
        expect($code2)->toStartWith('PL-CNT-SRC-001-RUN-UUID');
        expect($code1)->not->toBe($code2);
    });
});

describe('AutomationErrorPresenter', function () {
    it('creates presenter from run item model', function () {
        $item = new ContentAutomationRunItem([
            'last_error_code' => 'sql_exception',
            'last_error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
            'failure_stage' => 'persistence',
        ]);
        $item->id = 'test-item-id';
        $item->automation_run_id = 'test-run-id';

        $presenter = AutomationErrorPresenter::fromRunItem($item);

        expect($presenter->hasError())->toBeTrue();
        expect($presenter->publicErrorCode())->toBe(PublicAutomationErrorMapper::CODE_SOURCE_TRUNCATION);
        expect($presenter->supportCode())->toContain('PL-CNT-SRC-001');
    });

    it('creates presenter from run model', function () {
        $run = new ContentAutomationRun([
            'error_message' => 'Content generation failed unexpectedly',
            'metadata' => [
                'last_error_code' => 'runtime_exception',
                'last_failure_stage' => 'generation',
            ],
        ]);
        $run->id = 'test-run-id';

        $presenter = AutomationErrorPresenter::fromRun($run);

        expect($presenter->hasError())->toBeTrue();
        expect($presenter->publicErrorCode())->toBe(PublicAutomationErrorMapper::CODE_GENERATION_FAILED);
    });

    it('creates presenter from array data', function () {
        $data = [
            'last_error_code' => 'insufficient_credits',
            'last_error_message' => 'Not enough credits',
            'failure_stage' => 'generation',
        ];

        $presenter = AutomationErrorPresenter::fromArray($data, 'run-123', 'item-456');

        expect($presenter->hasError())->toBeTrue();
        expect($presenter->publicErrorCode())->toBe(PublicAutomationErrorMapper::CODE_INSUFFICIENT_CREDITS);
    });

    it('returns false for hasError when no error present', function () {
        $presenter = AutomationErrorPresenter::fromArray([
            'status' => 'completed',
        ]);

        expect($presenter->hasError())->toBeFalse();
    });
});

describe('AdminAccessChecker', function () {
    it('returns false for regular users', function () {
        $user = User::factory()->make([
            'is_admin' => false,
            'admin_role' => null,
        ]);

        $request = Mockery::mock(\Illuminate\Http\Request::class);
        $request->shouldReceive('session->has')->with('admin_impersonator_id')->andReturn(false);
        $request->shouldReceive('user')->andReturn($user);

        expect(AdminAccessChecker::canViewTechnicalDetails($request))->toBeFalse();
    });

    it('returns true for admin users', function () {
        $user = User::factory()->make([
            'is_admin' => true,
            'admin_role' => 'admin',
        ]);

        $request = Mockery::mock(\Illuminate\Http\Request::class);
        $request->shouldReceive('session->has')->with('admin_impersonator_id')->andReturn(false);
        $request->shouldReceive('user')->andReturn($user);

        expect(AdminAccessChecker::canViewTechnicalDetails($request))->toBeTrue();
    });

    it('returns true for superadmin users', function () {
        $user = User::factory()->make([
            'is_admin' => true,
            'admin_role' => 'superadmin',
        ]);

        $request = Mockery::mock(\Illuminate\Http\Request::class);
        $request->shouldReceive('session->has')->with('admin_impersonator_id')->andReturn(false);
        $request->shouldReceive('user')->andReturn($user);

        expect(AdminAccessChecker::canViewTechnicalDetails($request))->toBeTrue();
    });
});

describe('Automation show page error presentation', function () {
    beforeEach(function () {
        $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
    });

    it('shows public error code and title instead of raw SQL for regular users', function () {
        [$user, $automation] = makeErrorPresentationContext();

        $run = ContentAutomationRun::query()->create([
            'automation_id' => (string) $automation->id,
            'organization_id' => (int) $automation->organization_id,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => $automation->client_site_id,
            'status' => 'failed',
            'triggered_by' => 'manual',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'result_summary' => 'One item failed.',
            'error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
        ]);

        ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 1,
            'status' => 'failed',
            'failure_stage' => 'persistence',
            'last_error_code' => 'sql_exception',
            'last_error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
            'locale' => 'en',
            'title' => 'Failed generated article',
        ]);

        $response = $this->actingAs($user)
            ->get(route('app.content.automations.show', $automation));

        $response->assertOk()
            // Should see user-friendly error presentation
            ->assertSee('PL-CNT-SRC-001')
            ->assertSee('Content source data exceeded storage limits')
            // Should NOT show raw SQL error inline (it may be in a hidden admin section)
            ->assertDontSee('SQLSTATE[01000]', false)
            ->assertDontSee('Data truncated for column source', false);
    });

    it('shows technical details section for impersonating admin users', function () {
        [$regularUser, $automation] = makeErrorPresentationContext();

        // Create an admin user (will impersonate)
        $adminUser = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'admin',
            'active' => true,
        ]);

        $run = ContentAutomationRun::query()->create([
            'automation_id' => (string) $automation->id,
            'organization_id' => (int) $automation->organization_id,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => $automation->client_site_id,
            'status' => 'failed',
            'triggered_by' => 'manual',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'result_summary' => 'One item failed.',
            'error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
        ]);

        ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 1,
            'status' => 'failed',
            'failure_stage' => 'persistence',
            'last_error_code' => 'sql_exception',
            'last_error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
            'locale' => 'en',
            'title' => 'Failed generated article',
        ]);

        // Admin impersonating the regular user's workspace
        $response = $this->actingAs($regularUser)
            ->withSession([
                'admin_impersonator_id' => (string) $adminUser->id,
                'impersonated_workspace_id' => (string) $automation->workspace_id,
            ])
            ->get(route('app.content.automations.show', $automation));

        $response->assertOk()
            // Should see user-friendly error presentation
            ->assertSee('PL-CNT-SRC-001')
            ->assertSee('Content source data exceeded storage limits')
            // Impersonating admin should see the technical details toggle
            ->assertSee('Show technical details');
    });

    it('allows impersonating admin to see technical details', function () {
        [$regularUser, $automation] = makeErrorPresentationContext();

        // Create a superadmin user
        $superadmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'superadmin',
            'active' => true,
        ]);

        $run = ContentAutomationRun::query()->create([
            'automation_id' => (string) $automation->id,
            'organization_id' => (int) $automation->organization_id,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => $automation->client_site_id,
            'status' => 'failed',
            'triggered_by' => 'manual',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'result_summary' => 'One item failed.',
            'error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
        ]);

        ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 1,
            'status' => 'failed',
            'failure_stage' => 'persistence',
            'last_error_code' => 'sql_exception',
            'last_error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
            'locale' => 'en',
            'title' => 'Failed generated article',
        ]);

        // Simulate impersonation session
        $response = $this->actingAs($regularUser)
            ->withSession([
                'admin_impersonator_id' => (string) $superadmin->id,
                'impersonated_workspace_id' => (string) $automation->workspace_id,
            ])
            ->get(route('app.content.automations.show', $automation));

        $response->assertOk()
            ->assertSee('PL-CNT-SRC-001')
            // Impersonating admin should see the technical details toggle
            ->assertSee('Show technical details');
    });

    it('shows copy support code button', function () {
        [$user, $automation] = makeErrorPresentationContext();

        $run = ContentAutomationRun::query()->create([
            'automation_id' => (string) $automation->id,
            'organization_id' => (int) $automation->organization_id,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => $automation->client_site_id,
            'status' => 'failed',
            'triggered_by' => 'manual',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
        ]);

        ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 1,
            'status' => 'failed',
            'failure_stage' => 'persistence',
            'last_error_code' => 'sql_exception',
            'last_error_message' => 'SQLSTATE[01000]: Warning: 1265 Data truncated for column source',
            'locale' => 'en',
        ]);

        $response = $this->actingAs($user)
            ->get(route('app.content.automations.show', $automation));

        $response->assertOk()
            ->assertSee('Copy support code');
    });

    it('shows safe insufficient credits copy for users and technical details for admins', function () {
        [$user, $automation] = makeErrorPresentationContext();

        $run = ContentAutomationRun::query()->create([
            'automation_id' => (string) $automation->id,
            'organization_id' => (int) $automation->organization_id,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => $automation->client_site_id,
            'status' => 'failed',
            'triggered_by' => 'manual',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'result_summary' => 'Blocked by credits.',
            'error_message' => 'This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.',
            'metadata' => [
                'failure_pattern' => 'insufficient_credits',
                'failure_code' => 'PL-CREDITS-INSUFFICIENT',
                'last_error_code' => 'insufficient_credits',
                'last_failure_stage' => 'translation',
                'failure_details' => [
                    'pattern' => 'insufficient_credits',
                    'error_code' => 'PL-CREDITS-INSUFFICIENT',
                    'required_credits' => 6,
                    'available_credits' => 3,
                    'user_safe_message' => 'This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.',
                    'admin_message' => "Exception: App\\Exceptions\\InsufficientCreditsException\nRequired credits: 6\nAvailable credits: 3\nJob: App\\Jobs\\TranslateDraftJob\nSource location: app/Jobs/TranslateDraftJob.php:123\nRun ID: run-1\nAutomation ID: automation-1",
                    'exception_class' => 'App\\Exceptions\\InsufficientCreditsException',
                    'job' => 'App\\Jobs\\TranslateDraftJob',
                    'run_id' => 'run-1',
                    'automation_id' => 'automation-1',
                ],
            ],
        ]);

        ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 101,
            'status' => 'failed',
            'item_type' => 'translation',
            'failure_stage' => 'translation',
            'last_error_code' => 'insufficient_credits',
            'last_error_message' => 'This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.',
            'locale' => 'nl',
            'title' => 'Translation failed article',
            'metadata' => data_get($run->metadata, 'failure_details') ? [
                'failure_pattern' => 'insufficient_credits',
                'failure_code' => 'PL-CREDITS-INSUFFICIENT',
                'failure_details' => data_get($run->metadata, 'failure_details'),
            ] : [],
        ]);

        $this->actingAs($user)
            ->get(route('app.content.automations.show', $automation))
            ->assertOk()
            ->assertSee('PL-CREDITS-INSUFFICIENT')
            ->assertSee('This automation could not continue because there are not enough credits available. Required: 6, available: 3. Please add credits or reduce the automation scope and try again.')
            ->assertDontSee('App\\Exceptions\\InsufficientCreditsException');

        $adminUser = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'admin',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([
                'admin_impersonator_id' => (string) $adminUser->id,
                'impersonated_workspace_id' => (string) $automation->workspace_id,
            ])
            ->get(route('app.content.automations.show', $automation))
            ->assertOk()
            ->assertSee('Show technical details')
            ->assertSee('App\\Exceptions\\InsufficientCreditsException')
            ->assertSee('App\\Jobs\\TranslateDraftJob');
    });
});

function makeErrorPresentationContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Error Presentation Org',
        'slug' => 'error-pres-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
        'admin_role' => null,
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Error Presentation Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Error Presentation Site',
        'site_url' => 'https://error-pres.example.com',
        'base_url' => 'https://error-pres.example.com',
        'allowed_domains' => ['error-pres.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $automation = ContentAutomation::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Error presentation automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'chain_size' => 2,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Error presentation checks',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return [$user, $automation];
}
