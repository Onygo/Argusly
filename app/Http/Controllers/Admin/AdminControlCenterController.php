<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\BrandMembership;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorLog;
use App\Models\ConnectorManifest;
use App\Models\ConnectorToken;
use App\Models\ContentAsset;
use App\Models\CreditBalance;
use App\Models\CreditTransaction;
use App\Models\DomainEvent;
use App\Models\DomainEventProjectorRun;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Module;
use App\Models\OutboxMessage;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\SourceSync;
use App\Models\Subscription;
use App\Models\SubscriptionModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ActivityLogger;
use App\Services\CreditService;
use App\Services\DomainEventService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminControlCenterController extends Controller
{
    public function overview(): View
    {
        $metrics = [
            'total_accounts' => Account::query()->count(),
            'active_accounts' => Account::query()->where('status', 'active')->count(),
            'pending_pilot_signups' => $this->pilotSignupCount(),
            'active_brands' => Brand::query()->where('status', 'active')->count(),
            'active_users' => User::query()->whereHas('memberships', fn (Builder $query) => $query->where('status', 'active'))->count(),
            'enabled_modules' => SubscriptionModule::query()->where('status', 'active')->distinct('module_id')->count('module_id'),
            'connector_health' => ConnectorInstallation::query()->where('status', 'active')->count().'/'.ConnectorInstallation::query()->count(),
            'failed_publishing_actions' => PublishingAction::query()->whereIn('status', ['failed', 'error'])->count(),
            'failed_outbox_messages' => OutboxMessage::query()->whereIn('status', ['failed', 'error'])->count(),
            'failed_domain_event_projections' => DomainEventProjectorRun::query()->whereIn('status', ['failed', 'error'])->count(),
            'low_credit_accounts' => CreditBalance::query()->where('balance', '<=', 100)->count(),
        ];

        return view('admin.overview', [
            'metrics' => $metrics,
            'recentActivity' => ActivityLog::query()->with(['account', 'brand', 'user'])->latest()->limit(10)->get(),
            'pendingPilotSignups' => $this->pilotSignupRows(['pending', 'reviewing'])->take(5),
        ]);
    }

    public function accounts(Request $request): View
    {
        return view('admin.accounts', [
            'accounts' => Account::query()
                ->withCount(['brands', 'users', 'subscriptionModules'])
                ->with('creditBalance')
                ->when($request->string('q')->toString(), fn (Builder $query, string $search) => $query
                    ->where(fn (Builder $scope) => $scope
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")))
                ->when($request->string('status')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:accounts,slug'],
            'status' => ['required', 'in:active,pending,paused,archived'],
            'default_locale' => ['nullable', 'string', 'max:10'],
            'default_content_language' => ['nullable', 'string', 'max:10'],
        ]);

        $account = Account::query()->create([
            ...$data,
            'slug' => ($data['slug'] ?? null) ?: Str::slug($data['name']),
        ]);

        app(ActivityLogger::class)->log('admin.account.created', "Admin created account {$account->name}.", $account, user: $request->user(), subject: $account);

        return redirect()->route('admin.accounts.show', $account)->with('status', 'Account created.');
    }

    public function showAccount(Account $account): View
    {
        $account->load([
            'brands',
            'memberships.user',
            'subscriptionModules.module',
            'creditBalance',
            'creditTransactions.user',
            'integrationConnections.integration',
            'publishingChannels.brand',
        ]);

        return view('admin.account-show', [
            'account' => $account,
            'activity' => ActivityLog::query()->where('account_id', $account->id)->with(['user', 'brand'])->latest()->limit(12)->get(),
            'events' => DomainEvent::query()->where('account_id', $account->id)->with(['brand', 'actor'])->latest('occurred_at')->limit(12)->get(),
            'recommendations' => Recommendation::query()->where('account_id', $account->id)->latest()->limit(8)->get(),
            'signals' => class_exists(\App\Models\IntelligenceSignal::class)
                ? \App\Models\IntelligenceSignal::query()->where('account_id', $account->id)->latest()->limit(8)->get()
                : collect(),
            'users' => User::query()->orderBy('name')->get(),
            'roles' => Role::query()->orderByDesc('priority')->get(),
            'modules' => Module::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,pending,paused,archived'],
            'default_locale' => ['nullable', 'string', 'max:10'],
            'default_content_language' => ['nullable', 'string', 'max:10'],
        ]);

        $account->update($data);
        app(ActivityLogger::class)->log('admin.account.updated', "Admin updated account {$account->name}.", $account, user: $request->user(), subject: $account);

        return back()->with('status', 'Account updated.');
    }

    public function brands(Request $request): View
    {
        return view('admin.brands', [
            'accounts' => Account::query()->orderBy('name')->get(),
            'brands' => Brand::query()
                ->with(['account', 'profile'])
                ->withCount(['publishingChannels', 'products', 'services', 'narratives'])
                ->when($request->integer('account_id'), fn (Builder $query, int $accountId) => $query->where('account_id', $accountId))
                ->when($request->string('q')->toString(), fn (Builder $query, string $search) => $query
                    ->where(fn (Builder $scope) => $scope
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function storeBrand(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,pending,paused,archived'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $brand = Brand::query()->create([
            ...$data,
            'slug' => ($data['slug'] ?? null) ?: Str::slug($data['name']),
        ]);

        app(ActivityLogger::class)->log('admin.brand.created', "Admin created brand {$brand->name}.", $brand->account, $brand, $request->user(), $brand);

        return redirect()->route('admin.brands')->with('status', 'Brand created.');
    }

    public function updateBrand(Request $request, Brand $brand): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,pending,paused,archived'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $brand->update($data);
        app(ActivityLogger::class)->log('admin.brand.updated', "Admin updated brand {$brand->name}.", $brand->account, $brand, $request->user(), $brand);

        return back()->with('status', 'Brand updated.');
    }

    public function users(Request $request): View
    {
        return view('admin.users', [
            'users' => User::query()
                ->with(['memberships.account', 'brandMemberships.brand', 'roleAssignments.role'])
                ->when($request->string('q')->toString(), fn (Builder $query, string $search) => $query
                    ->where(fn (Builder $scope) => $scope
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'accounts' => Account::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'roles' => Role::query()->orderByDesc('priority')->get(),
        ]);
    }

    public function assignAccountUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'status' => ['required', 'in:active,pending,paused,archived'],
        ]);

        Membership::query()->updateOrCreate(
            ['user_id' => $data['user_id'], 'account_id' => $data['account_id']],
            ['status' => $data['status'], 'joined_at' => now()],
        );

        UserRole::query()->updateOrCreate(
            ['user_id' => $data['user_id'], 'account_id' => $data['account_id'], 'brand_id' => null],
            ['role_id' => $data['role_id']],
        );

        $account = Account::query()->findOrFail($data['account_id']);
        app(ActivityLogger::class)->log('admin.membership.assigned', 'Admin assigned an account membership.', $account, user: $request->user(), properties: $data);

        return back()->with('status', 'Account membership assigned.');
    }

    public function assignBrandUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'brand_id' => ['required', 'exists:brands,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'status' => ['required', 'in:active,pending,paused,archived'],
        ]);
        $brand = Brand::query()->findOrFail($data['brand_id']);

        BrandMembership::query()->updateOrCreate(
            ['user_id' => $data['user_id'], 'brand_id' => $brand->id],
            ['account_id' => $brand->account_id, 'status' => $data['status'], 'joined_at' => now()],
        );

        UserRole::query()->updateOrCreate(
            ['user_id' => $data['user_id'], 'account_id' => $brand->account_id, 'brand_id' => $brand->id],
            ['role_id' => $data['role_id']],
        );

        app(ActivityLogger::class)->log('admin.brand_membership.assigned', 'Admin assigned a brand membership.', $brand->account, $brand, $request->user(), properties: $data);

        return back()->with('status', 'Brand membership assigned.');
    }

    public function removeMembership(Request $request, Membership $membership): RedirectResponse
    {
        $account = $membership->account;
        UserRole::query()->where('user_id', $membership->user_id)->where('account_id', $membership->account_id)->whereNull('brand_id')->delete();
        $membership->delete();
        app(ActivityLogger::class)->log('admin.membership.removed', 'Admin removed an account membership.', $account, user: $request->user());

        return back()->with('status', 'Membership removed.');
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()?->id === $user->id, 403);

        $request->session()->put('impersonator_user_id', $request->user()?->id);
        $request->session()->put('impersonated_user_id', $user->id);

        app(ActivityLogger::class)->log(
            'admin.user.impersonated',
            "Admin started impersonating {$user->email}.",
            user: $request->user(),
            subject: $user,
            properties: ['target_user_id' => $user->id],
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', "You are now impersonating {$user->name}.");
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_user_id');
        $request->session()->forget('impersonated_user_id');

        abort_unless($impersonatorId, 403);

        $impersonator = User::query()->findOrFail($impersonatorId);
        Auth::login($impersonator);
        $request->session()->regenerate();

        app(ActivityLogger::class)->log(
            'admin.user.impersonation_stopped',
            'Admin stopped impersonating a user.',
            user: $impersonator,
            properties: ['impersonator_user_id' => $impersonator->id],
        );

        return redirect()->route('admin.users')->with('status', 'Impersonation stopped.');
    }

    public function modules(): View
    {
        return view('admin.modules', [
            'modules' => Module::query()->withCount('subscriptionModules')->orderBy('name')->get(),
            'accounts' => Account::query()->with('subscriptionModules.module', 'activeSubscription.plan')->orderBy('name')->paginate(20),
        ]);
    }

    public function enableModule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'module_id' => ['required', 'exists:modules,id'],
            'status' => ['required', 'in:active,disabled,paused'],
        ]);

        $subscription = Subscription::query()->firstOrCreate(
            ['account_id' => $data['account_id'], 'status' => 'active'],
            ['billing_interval' => 'monthly', 'currency' => 'EUR', 'amount' => 0, 'provider' => 'manual'],
        );

        SubscriptionModule::query()->updateOrCreate(
            ['subscription_id' => $subscription->id, 'module_id' => $data['module_id']],
            ['account_id' => $data['account_id'], 'status' => $data['status'], 'starts_at' => now()],
        );

        $account = Account::query()->findOrFail($data['account_id']);
        app(ActivityLogger::class)->log('admin.module.updated', 'Admin updated an account module.', $account, user: $request->user(), properties: $data);

        return back()->with('status', 'Module access updated.');
    }

    public function credits(): View
    {
        return view('admin.credits', [
            'accounts' => Account::query()->with('creditBalance')->orderBy('name')->paginate(20),
            'transactions' => CreditTransaction::query()->with(['account', 'user'])->latest()->limit(30)->get(),
        ]);
    }

    public function adjustCredits(Request $request, CreditService $credits, DomainEventService $events): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'amount' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $account = Account::query()->findOrFail($data['account_id']);
        $transaction = $data['amount'] > 0
            ? $credits->grant($account, $data['amount'], $request->user(), $data['reason'], ['manual' => true, 'admin_user_id' => $request->user()?->id])
            : $credits->grant($account, $data['amount'], $request->user(), $data['reason'], ['manual' => true, 'admin_user_id' => $request->user()?->id]);

        app(ActivityLogger::class)->log('admin.credits.adjusted', 'Admin manually adjusted credits.', $account, user: $request->user(), subject: $transaction, properties: [
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'reason' => $data['reason'],
        ]);

        $events->record('CreditBalanceAdjusted', $account, null, $transaction, $request->user(), [
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'reason' => $data['reason'],
            'admin_user_id' => $request->user()?->id,
        ], dispatch: false);

        return back()->with('status', 'Credits adjusted.');
    }

    public function integrations(): View
    {
        return view('admin.integrations', [
            'integrations' => Integration::query()->withCount('connections')->orderBy('name')->get(),
            'connections' => IntegrationConnection::query()->with(['integration', 'account', 'brand', 'owner'])->latest()->paginate(20),
        ]);
    }

    public function connectors(): View
    {
        return view('admin.connectors', [
            'manifests' => ConnectorManifest::query()->withCount(['installations', 'capabilities'])->orderBy('name')->get(),
            'installations' => ConnectorInstallation::query()->with(['account', 'brand', 'manifest', 'version'])->latest()->paginate(20),
            'tokens' => ConnectorToken::query()->with(['account', 'brand', 'installation'])->latest()->limit(20)->get(),
        ]);
    }

    public function revokeConnectorToken(Request $request, ConnectorToken $token): RedirectResponse
    {
        $token->forceFill(['revoked_at' => now()])->save();
        app(ActivityLogger::class)->log('admin.connector_token.revoked', 'Admin revoked a connector token.', $token->account, $token->brand, $request->user(), $token);

        return back()->with('status', 'Connector token revoked.');
    }

    public function channels(): View
    {
        return view('admin.channels', [
            'channels' => PublishingChannel::query()->with(['account', 'brand', 'connectorInstallation'])->latest()->paginate(20),
        ]);
    }

    public function publishing(): View
    {
        return view('admin.publishing', [
            'actions' => PublishingAction::query()->with(['account', 'brand', 'contentAsset', 'publishingChannel.connectorInstallation'])->latest()->paginate(20),
        ]);
    }

    public function developer(Request $request, string $tool = 'system-health'): View
    {
        $tools = $this->developerTools($request);

        abort_unless(array_key_exists($tool, $tools), 404);

        return view('admin.developer', [
            'tool' => $tool,
            'config' => $tools[$tool],
            'tools' => $tools,
        ]);
    }

    public function pilotSignups(): View
    {
        $signups = Schema::hasTable('pilot_signups')
            ? DB::table('pilot_signups')->latest()->paginate(20)
            : null;

        return view('admin.pilot-signups', [
            'signups' => $signups,
            'stats' => $this->pilotSignupStats(),
        ]);
    }

    public function updatePilotSignup(Request $request, int $signup): RedirectResponse
    {
        abort_unless(Schema::hasTable('pilot_signups'), 404);

        $data = $request->validate([
            'status' => ['required', 'in:pending,reviewing,contacted,activated,declined'],
        ]);

        $current = DB::table('pilot_signups')->where('id', $signup)->first();
        abort_unless($current, 404);

        DB::table('pilot_signups')
            ->where('id', $signup)
            ->update([
                'status' => $data['status'],
                'reviewed_at' => $data['status'] === 'pending' ? null : now(),
                'reviewed_by' => $data['status'] === 'pending' ? null : $request->user()?->id,
                'updated_at' => now(),
            ]);

        app(ActivityLogger::class)->log(
            'admin.pilot_signup.updated',
            "Admin marked pilot request from {$current->email} as {$data['status']}.",
            user: $request->user(),
            properties: [
                'pilot_signup_id' => $signup,
                'email' => $current->email,
                'company' => $current->company,
                'previous_status' => $current->status,
                'status' => $data['status'],
            ],
        );

        return back()->with('status', 'Pilot request updated.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function developerTools(Request $request): array
    {
        return [
            'domain-events' => [
                'title' => 'Domain Events',
                'rows' => DomainEvent::query()->with(['account', 'brand', 'actor'])->latest('occurred_at')->paginate(20)->withQueryString(),
                'columns' => ['event_type', 'account.name', 'brand.name', 'actor.name', 'occurred_at', 'processed_at'],
            ],
            'outbox-messages' => [
                'title' => 'Outbox Messages',
                'rows' => OutboxMessage::query()->with(['account', 'brand'])->latest()->paginate(20)->withQueryString(),
                'columns' => ['type', 'status', 'account.name', 'brand.name', 'attempts', 'available_at', 'error'],
            ],
            'activity-logs' => [
                'title' => 'Activity Logs',
                'rows' => ActivityLog::query()->with(['account', 'brand', 'user'])->latest()->paginate(20)->withQueryString(),
                'columns' => ['event', 'description', 'account.name', 'brand.name', 'user.name', 'created_at'],
            ],
            'connector-logs' => [
                'title' => 'Connector Logs',
                'rows' => ConnectorLog::query()->with(['account', 'brand', 'installation'])->latest('occurred_at')->paginate(20)->withQueryString(),
                'columns' => ['event', 'level', 'status', 'account.name', 'brand.name', 'occurred_at', 'message'],
            ],
            'source-syncs' => [
                'title' => 'Source Syncs',
                'rows' => SourceSync::query()->with('source')->latest()->paginate(20)->withQueryString(),
                'columns' => ['source.name', 'status', 'started_at', 'completed_at', 'error'],
            ],
            'graph-nodes' => [
                'title' => 'Graph Nodes',
                'rows' => GraphNode::query()
                    ->with(['account', 'brand'])
                    ->when($request->integer('account_id'), fn (Builder $query, int $id) => $query->where('account_id', $id))
                    ->when($request->integer('brand_id'), fn (Builder $query, int $id) => $query->where('brand_id', $id))
                    ->when($request->string('node_type')->toString(), fn (Builder $query, string $type) => $query->where('node_type', $type))
                    ->latest()
                    ->paginate(20)
                    ->withQueryString(),
                'columns' => ['label', 'node_type', 'account.name', 'brand.name', 'source_type', 'source_id', 'metadata'],
                'filters' => ['account_id', 'brand_id', 'node_type'],
            ],
            'graph-edges' => [
                'title' => 'Graph Edges',
                'rows' => GraphEdge::query()
                    ->with(['account', 'brand', 'sourceNode', 'targetNode'])
                    ->when($request->integer('account_id'), fn (Builder $query, int $id) => $query->where('account_id', $id))
                    ->when($request->integer('brand_id'), fn (Builder $query, int $id) => $query->where('brand_id', $id))
                    ->when($request->string('relationship_type')->toString(), fn (Builder $query, string $type) => $query->where('relationship_type', $type))
                    ->latest()
                    ->paginate(20)
                    ->withQueryString(),
                'columns' => ['relationship_type', 'sourceNode.label', 'targetNode.label', 'account.name', 'brand.name', 'strength', 'confidence', 'metadata'],
                'filters' => ['account_id', 'brand_id', 'relationship_type'],
            ],
            'system-health' => [
                'title' => 'System Health',
                'rows' => collect([
                    (object) ['check' => 'Graph rebuild command', 'status' => 'placeholder', 'detail' => 'Ready for command wiring.'],
                    (object) ['check' => 'Graph verify command', 'status' => 'placeholder', 'detail' => 'Ready for command wiring.'],
                    (object) ['check' => 'Queue failures', 'status' => $this->failedJobsCount() > 0 ? 'attention' : 'ok', 'detail' => $this->failedJobsCount().' failed jobs'],
                ]),
                'columns' => ['check', 'status', 'detail'],
            ],
        ];
    }

    private function pilotSignupCount(): int
    {
        return Schema::hasTable('pilot_signups')
            ? DB::table('pilot_signups')->where('status', 'pending')->count()
            : 0;
    }

    /**
     * @param  array<int, string>  $statuses
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function pilotSignupRows(array $statuses): \Illuminate\Support\Collection
    {
        return Schema::hasTable('pilot_signups')
            ? DB::table('pilot_signups')->whereIn('status', $statuses)->latest()->get()
            : collect();
    }

    /**
     * @return array<string, int>
     */
    private function pilotSignupStats(): array
    {
        if (! Schema::hasTable('pilot_signups')) {
            return ['pending' => 0, 'reviewing' => 0, 'contacted' => 0, 'activated' => 0, 'declined' => 0, 'total' => 0];
        }

        $counts = DB::table('pilot_signups')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'reviewing' => (int) ($counts['reviewing'] ?? 0),
            'contacted' => (int) ($counts['contacted'] ?? 0),
            'activated' => (int) ($counts['activated'] ?? 0),
            'declined' => (int) ($counts['declined'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    private function failedJobsCount(): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
    }
}
