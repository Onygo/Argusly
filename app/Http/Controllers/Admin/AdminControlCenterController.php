<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PilotSignupFollowUp;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\BrandMembership;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorLog;
use App\Models\ConnectorManifest;
use App\Models\ConnectorToken;
use App\Models\CreditBalance;
use App\Models\CreditCostCatalog;
use App\Models\CreditCostOverride;
use App\Models\CreditTransaction;
use App\Models\CreditUsageStat;
use App\Models\DomainEvent;
use App\Models\DomainEventProjectorRun;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\LlmRequest;
use App\Models\LlmSetting;
use App\Models\Membership;
use App\Models\Module;
use App\Models\OutboxMessage;
use App\Models\Plan;
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
use App\Services\LlmSettingsService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
            'signals' => class_exists(IntelligenceSignal::class)
                ? IntelligenceSignal::query()->where('account_id', $account->id)->latest()->limit(8)->get()
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
        $impersonator = $request->user();

        abort_if(! $impersonator || $impersonator->id === $user->id, 403);
        abort_if($request->session()->has('impersonator_user_id'), 403);

        app(ActivityLogger::class)->log(
            'admin.user.impersonated',
            "Admin started impersonating {$user->email}.",
            user: $impersonator,
            subject: $user,
            properties: ['target_user_id' => $user->id],
        );

        $request->session()->forget(['tenant.current_account_id', 'tenant.current_brand_id']);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_user_id', $impersonator->id);
        $request->session()->put('impersonator_user_name', $impersonator->name);
        $request->session()->put('impersonator_user_email', $impersonator->email);
        $request->session()->put('impersonated_user_id', $user->id);
        $request->session()->put('impersonation_scope', 'platform');

        return redirect()->route('dashboard')->with('status', "You are now impersonating {$user->name}.");
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_user_id');
        $impersonatedId = $request->session()->get('impersonated_user_id');
        $scope = $request->session()->get('impersonation_scope', 'platform');

        abort_unless($impersonatorId && $impersonatedId === $request->user()?->id, 403);

        $impersonator = User::query()->findOrFail($impersonatorId);
        $request->session()->forget(['tenant.current_account_id', 'tenant.current_brand_id']);
        Auth::login($impersonator);
        $request->session()->regenerate();
        $request->session()->forget([
            'impersonator_user_id',
            'impersonator_user_name',
            'impersonator_user_email',
            'impersonated_user_id',
            'impersonation_scope',
            'impersonation_account_id',
        ]);

        app(ActivityLogger::class)->log(
            'admin.user.impersonation_stopped',
            'Admin stopped impersonating a user.',
            user: $impersonator,
            properties: ['impersonator_user_id' => $impersonator->id, 'impersonated_user_id' => $impersonatedId],
        );

        $route = $scope === 'workspace' ? 'settings.team' : 'admin.users';

        return redirect()->route($route)->with('status', 'Impersonation stopped.');
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

    public function creditCosts(Request $request): View
    {
        $usageSubquery = CreditUsageStat::query()
            ->selectRaw('catalog_code, sum(credits_used) as credits_used_sum, sum(executions) as executions_sum')
            ->groupBy('catalog_code');
        $lastUsedSubquery = CreditTransaction::query()
            ->selectRaw("json_extract(metadata, '$.catalog_code') as catalog_code, max(created_at) as last_used_at")
            ->where('amount', '<', 0)
            ->whereNotNull('metadata')
            ->groupBy('catalog_code');

        return view('admin.credit-costs', [
            'accounts' => Account::query()->with('brands')->orderBy('name')->get(),
            'brands' => Brand::query()->with('account')->orderBy('name')->get(),
            'catalog' => CreditCostCatalog::query()
                ->withCount('overrides')
                ->leftJoinSub($usageSubquery, 'usage_totals', 'usage_totals.catalog_code', '=', 'credit_cost_catalog.code')
                ->leftJoinSub($lastUsedSubquery, 'last_usage', 'last_usage.catalog_code', '=', 'credit_cost_catalog.code')
                ->select('credit_cost_catalog.*')
                ->selectRaw('coalesce(usage_totals.credits_used_sum, 0) as usage_credits_sum')
                ->selectRaw('coalesce(usage_totals.executions_sum, 0) as usage_executions_sum')
                ->selectRaw('last_usage.last_used_at as last_used_at')
                ->when($request->string('q')->toString(), fn (Builder $query, string $search) => $query
                    ->where(fn (Builder $scope) => $scope
                        ->where('credit_cost_catalog.code', 'like', "%{$search}%")
                        ->orWhere('credit_cost_catalog.name', 'like', "%{$search}%")))
                ->when($request->string('category')->toString(), fn (Builder $query, string $category) => $query->where('credit_cost_catalog.category', $category))
                ->when($request->string('status')->toString(), fn (Builder $query, string $status) => $query->where('credit_cost_catalog.status', $status))
                ->orderBy('credit_cost_catalog.category')
                ->orderBy('credit_cost_catalog.code')
                ->paginate(30)
                ->withQueryString(),
            'overrides' => CreditCostOverride::query()->with(['catalog', 'account', 'brand'])->latest()->limit(20)->get(),
            'categories' => CreditCostCatalog::CATEGORIES,
            'statuses' => CreditCostCatalog::STATUSES,
            'costTypes' => CreditCostCatalog::COST_TYPES,
        ]);
    }

    public function updateCreditCost(Request $request, CreditCostCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'in:'.implode(',', CreditCostCatalog::CATEGORIES)],
            'default_cost' => ['required', 'integer', 'min:0'],
            'minimum_cost' => ['nullable', 'integer', 'min:0'],
            'maximum_cost' => ['nullable', 'integer', 'min:0'],
            'cost_type' => ['required', 'in:'.implode(',', CreditCostCatalog::COST_TYPES)],
            'status' => ['required', 'in:'.implode(',', CreditCostCatalog::STATUSES)],
        ]);

        $catalog->update($data);

        return back()->with('status', "{$catalog->code} updated.");
    }

    public function storeCreditCostOverride(Request $request, DomainEventService $events): RedirectResponse
    {
        $data = $request->validate([
            'credit_cost_catalog_id' => ['required', 'exists:credit_cost_catalog,id'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'override_cost' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $brand = isset($data['brand_id']) ? Brand::query()->find($data['brand_id']) : null;

        if ($brand && ! isset($data['account_id'])) {
            $data['account_id'] = $brand->account_id;
        }

        $override = CreditCostOverride::query()->updateOrCreate(
            [
                'account_id' => $data['account_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'credit_cost_catalog_id' => $data['credit_cost_catalog_id'],
            ],
            [
                'override_cost' => $data['override_cost'],
                'status' => $data['status'],
            ],
        );

        $events->recordForSubject('CreditOverrideCreated', $override, $request->user(), [
            'catalog_code' => $override->catalog?->code,
            'account_id' => $override->account_id,
            'brand_id' => $override->brand_id,
            'override_cost' => $override->override_cost,
        ], dispatch: false);

        return back()->with('status', 'Credit cost override saved.');
    }

    public function llmRequests(Request $request): View
    {
        return view('admin.llm-requests', [
            'accounts' => Account::query()->orderBy('name')->get(),
            'requests' => LlmRequest::query()
                ->with(['account', 'brand', 'user'])
                ->when($request->integer('account_id'), fn (Builder $query, int $accountId) => $query->where('account_id', $accountId))
                ->when($request->string('provider')->toString(), fn (Builder $query, string $provider) => $query->where('provider', $provider))
                ->when($request->string('purpose')->toString(), fn (Builder $query, string $purpose) => $query->where('purpose', $purpose))
                ->when($request->string('status')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
                ->latest('created_at')
                ->paginate(30)
                ->withQueryString(),
            'purposes' => LlmRequest::PURPOSES,
            'statuses' => LlmRequest::STATUSES,
        ]);
    }

    public function llm(): View
    {
        return view('admin.llm.index', [
            'providers' => LlmProvider::query()->withCount('models')->orderBy('name')->get(),
            'models' => LlmModel::query()->with('provider')->where('status', 'active')->orderBy('name')->get(),
            'global' => LlmSetting::query()
                ->whereNull('account_id')
                ->whereNull('brand_id')
                ->with(['defaultProvider', 'defaultModel', 'fallbackProvider', 'fallbackModel'])
                ->first(),
        ]);
    }

    public function updateGlobalLlm(Request $request, LlmSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'default_provider_id' => ['nullable', 'integer', 'exists:llm_providers,id'],
            'default_model_id' => ['nullable', 'integer', 'exists:llm_models,id'],
            'fallback_provider_id' => ['nullable', 'integer', 'exists:llm_providers,id'],
            'fallback_model_id' => ['nullable', 'integer', 'exists:llm_models,id'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $validated = $this->nullableLlmAttributes($validated);

        $this->validateLlmPair($validated['default_provider_id'] ?? null, $validated['default_model_id'] ?? null, 'default');
        $this->validateLlmPair($validated['fallback_provider_id'] ?? null, $validated['fallback_model_id'] ?? null, 'fallback');

        $settings->upsertGlobal($validated);

        return back()->with('status', 'Global LLM defaults updated.');
    }

    public function llmProviders(): View
    {
        return view('admin.llm.providers', [
            'providers' => LlmProvider::query()->withCount('models')->orderBy('name')->get(),
        ]);
    }

    public function updateLlmProvider(Request $request, LlmProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive,archived'],
        ]);

        $provider->update($validated);

        return back()->with('status', "{$provider->name} provider updated.");
    }

    public function llmModels(Request $request): View
    {
        return view('admin.llm.models', [
            'providers' => LlmProvider::query()->orderBy('name')->get(),
            'models' => LlmModel::query()
                ->with('provider')
                ->when($request->integer('provider_id'), fn (Builder $query, int $providerId) => $query->where('provider_id', $providerId))
                ->when($request->string('type')->toString(), fn (Builder $query, string $type) => $query->where('type', $type))
                ->when($request->string('status')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
                ->orderBy('provider_id')
                ->orderBy('name')
                ->paginate(40)
                ->withQueryString(),
            'types' => LlmModel::TYPES,
            'statuses' => LlmModel::STATUSES,
        ]);
    }

    public function updateLlmModel(Request $request, LlmModel $model): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive,deprecated,archived'],
        ]);

        $model->update($validated);

        return back()->with('status', "{$model->name} model updated.");
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

    public function contactRequests(): View
    {
        $requests = Schema::hasTable('contact_requests')
            ? DB::table('contact_requests')->latest()->paginate(20)
            : null;

        return view('admin.contact-requests', [
            'requests' => $requests,
            'stats' => $this->contactRequestStats(),
        ]);
    }

    public function updateContactRequest(Request $request, int $contactRequest): RedirectResponse
    {
        abort_unless(Schema::hasTable('contact_requests'), 404);

        $data = $request->validate([
            'status' => ['required', 'in:new,reviewing,contacted,unqualified,closed'],
        ]);

        $current = DB::table('contact_requests')->where('id', $contactRequest)->first();
        abort_unless($current, 404);

        DB::table('contact_requests')
            ->where('id', $contactRequest)
            ->update([
                'status' => $data['status'],
                'handled_at' => $data['status'] === 'new' ? null : now(),
                'handled_by' => $data['status'] === 'new' ? null : $request->user()?->id,
                'updated_at' => now(),
            ]);

        app(ActivityLogger::class)->log(
            'admin.contact_request.updated',
            "Admin marked contact request from {$current->email} as {$data['status']}.",
            user: $request->user(),
            properties: [
                'contact_request_id' => $contactRequest,
                'email' => $current->email,
                'company' => $current->company,
                'previous_status' => $current->status,
                'status' => $data['status'],
            ],
        );

        return back()->with('status', 'Contact request updated.');
    }

    public function updatePilotSignup(Request $request, int $signup): RedirectResponse
    {
        abort_unless(Schema::hasTable('pilot_signups'), 404);

        $data = $request->validate([
            'status' => ['required', 'in:pending,reviewing,contacted,activated,declined'],
        ]);

        $current = DB::table('pilot_signups')->where('id', $signup)->first();
        abort_unless($current, 404);

        if ($data['status'] === 'activated') {
            $result = $this->activatePilotSignup($request, $current);

            return back()->with('status', "Pilot activated: {$result['account']->name}, {$result['brand']->name}, {$result['user']->email}.");
        }

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

    public function sendPilotSignupFollowUp(Request $request, int $signup): RedirectResponse
    {
        abort_unless(Schema::hasTable('pilot_signups'), 404);

        $current = DB::table('pilot_signups')->where('id', $signup)->first();
        abort_unless($current, 404);

        Mail::to($current->email)->send(new PilotSignupFollowUp($this->pilotSignupMailPayload($current)));

        $metadata = $this->pilotSignupMetadata($current);
        $metadata['follow_up'] = [
            'sent_at' => now()->toIso8601String(),
            'sent_by' => $request->user()?->id,
        ];

        DB::table('pilot_signups')
            ->where('id', $signup)
            ->update([
                'status' => $current->status === 'activated' ? 'activated' : 'contacted',
                'reviewed_at' => $current->reviewed_at ?: now(),
                'reviewed_by' => $current->reviewed_by ?: $request->user()?->id,
                'metadata' => json_encode($metadata),
                'updated_at' => now(),
            ]);

        app(ActivityLogger::class)->log(
            'admin.pilot_signup.follow_up_sent',
            "Admin sent pilot follow-up to {$current->email}.",
            user: $request->user(),
            properties: [
                'pilot_signup_id' => $signup,
                'email' => $current->email,
                'company' => $current->company,
            ],
        );

        return back()->with('status', "Follow-up sent to {$current->email}.");
    }

    /**
     * @return array{account: Account, brand: Brand, user: User}
     */
    private function activatePilotSignup(Request $request, object $signup): array
    {
        return DB::transaction(function () use ($request, $signup): array {
            $metadata = $this->pilotSignupMetadata($signup);
            $activation = $metadata['activation'] ?? [];
            $account = Account::query()->find($activation['account_id'] ?? null)
                ?: Account::query()->where('slug', $this->uniqueSlugBase($signup->company))->first();

            if (! $account) {
                $account = Account::query()->create([
                    'name' => $signup->company,
                    'slug' => $this->uniqueAccountSlug($signup->company),
                    'status' => 'active',
                ]);
            }

            $brand = Brand::query()->find($activation['brand_id'] ?? null)
                ?: Brand::query()
                    ->where('account_id', $account->id)
                    ->where('slug', $this->uniqueSlugBase($signup->company))
                    ->first();

            if (! $brand) {
                $brand = Brand::query()->create([
                    'account_id' => $account->id,
                    'name' => $signup->company,
                    'slug' => $this->uniqueBrandSlug($account, $signup->company),
                    'domain' => $this->domainFromWebsite($signup->website),
                    'website_url' => $signup->website,
                    'status' => 'active',
                ]);
            }

            $user = User::query()->firstOrCreate(
                ['email' => $signup->email],
                [
                    'name' => $signup->name,
                    'password' => Hash::make(Str::password(32)),
                    'email_verified_at' => now(),
                ],
            );

            $account->users()->syncWithoutDetaching([
                $user->id => ['status' => 'active', 'joined_at' => now()],
            ]);
            $brand->users()->syncWithoutDetaching([
                $user->id => ['account_id' => $account->id, 'status' => 'active', 'joined_at' => now()],
            ]);

            if ($ownerRole = Role::query()->where('name', 'owner')->first()) {
                UserRole::query()->updateOrCreate(
                    ['user_id' => $user->id, 'account_id' => $account->id, 'brand_id' => null],
                    ['role_id' => $ownerRole->id],
                );
                UserRole::query()->updateOrCreate(
                    ['user_id' => $user->id, 'account_id' => $account->id, 'brand_id' => $brand->id],
                    ['role_id' => $ownerRole->id],
                );
            }

            $this->ensurePilotSubscription($account);

            if (! ($activation['credits_granted_at'] ?? null)) {
                app(CreditService::class)->grant($account, 1000, $request->user(), 'Pilot activation credits', [
                    'pilot_signup_id' => $signup->id,
                ]);
            }

            $metadata['activation'] = [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'credits_granted_at' => $activation['credits_granted_at'] ?? now()->toIso8601String(),
                'activated_at' => now()->toIso8601String(),
                'activated_by' => $request->user()?->id,
            ];

            DB::table('pilot_signups')
                ->where('id', $signup->id)
                ->update([
                    'status' => 'activated',
                    'reviewed_at' => now(),
                    'reviewed_by' => $request->user()?->id,
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                ]);

            app(ActivityLogger::class)->log(
                'admin.pilot_signup.activated',
                "Admin activated pilot request from {$signup->email}.",
                account: $account,
                brand: $brand,
                user: $request->user(),
                subject: $account,
                properties: [
                    'pilot_signup_id' => $signup->id,
                    'email' => $signup->email,
                    'company' => $signup->company,
                    'account_id' => $account->id,
                    'brand_id' => $brand->id,
                    'user_id' => $user->id,
                ],
            );

            return ['account' => $account, 'brand' => $brand, 'user' => $user];
        });
    }

    private function ensurePilotSubscription(Account $account): void
    {
        if ($account->activeSubscription()->exists()) {
            return;
        }

        if (Plan::query()->where('key', 'starter_monthly')->exists()) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly', [
                'source' => 'pilot_activation',
            ]);

            return;
        }

        $subscription = $account->subscriptions()->create([
            'status' => 'active',
            'billing_interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 0,
            'metadata' => ['source' => 'pilot_activation'],
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        if ($module = Module::query()->where('key', 'core')->first()) {
            $subscription->modules()->updateOrCreate(
                ['module_id' => $module->id],
                [
                    'account_id' => $account->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                    'metadata' => ['source' => 'pilot_activation'],
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pilotSignupMetadata(object $signup): array
    {
        return json_decode((string) $signup->metadata, true) ?: [];
    }

    /**
     * @return array{name: string, email: string, company: string, website: string|null, role: string|null, goal: string|null}
     */
    private function pilotSignupMailPayload(object $signup): array
    {
        return [
            'name' => $signup->name,
            'email' => $signup->email,
            'company' => $signup->company,
            'website' => $signup->website,
            'role' => $signup->role,
            'goal' => $signup->goal,
        ];
    }

    private function uniqueSlugBase(string $value): string
    {
        return Str::slug($value) ?: 'pilot';
    }

    private function uniqueAccountSlug(string $company): string
    {
        $base = $this->uniqueSlugBase($company);
        $slug = $base;
        $suffix = 2;

        while (Account::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function uniqueBrandSlug(Account $account, string $company): string
    {
        $base = $this->uniqueSlugBase($company);
        $slug = $base;
        $suffix = 2;

        while (Brand::query()->where('account_id', $account->id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function domainFromWebsite(?string $website): ?string
    {
        if (! $website) {
            return null;
        }

        $url = str_starts_with($website, 'http://') || str_starts_with($website, 'https://')
            ? $website
            : "https://{$website}";
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    private function validateLlmPair(mixed $providerId, mixed $modelId, string $label): void
    {
        if ($providerId === null || $providerId === '' || $modelId === null || $modelId === '') {
            return;
        }

        $model = LlmModel::query()->find((int) $modelId);

        abort_if(! $model || $model->provider_id !== (int) $providerId, 422, "The {$label} model must belong to the selected provider.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function nullableLlmAttributes(array $attributes): array
    {
        foreach (['default_provider_id', 'default_model_id', 'fallback_provider_id', 'fallback_model_id', 'temperature', 'max_tokens'] as $key) {
            if (($attributes[$key] ?? null) === '') {
                $attributes[$key] = null;
            }
        }

        return $attributes;
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
     * @return Collection<int, object>
     */
    private function pilotSignupRows(array $statuses): Collection
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

    /**
     * @return array<string, int>
     */
    private function contactRequestStats(): array
    {
        if (! Schema::hasTable('contact_requests')) {
            return ['new' => 0, 'reviewing' => 0, 'contacted' => 0, 'unqualified' => 0, 'closed' => 0, 'total' => 0];
        }

        $counts = DB::table('contact_requests')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'new' => (int) ($counts['new'] ?? 0),
            'reviewing' => (int) ($counts['reviewing'] ?? 0),
            'contacted' => (int) ($counts['contacted'] ?? 0),
            'unqualified' => (int) ($counts['unqualified'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    private function failedJobsCount(): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
    }
}
