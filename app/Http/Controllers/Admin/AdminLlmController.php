<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\LlmRequest;
use App\Models\LlmRoutingRule;
use App\Models\LlmSettingsAuditLog;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest as LlmProviderRequest;
use App\Services\Llm\LlmModelCatalog;
use App\Services\Llm\LlmManager;
use App\Services\Llm\LlmRoutingService;
use App\Services\Llm\LlmSettingsAuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLlmController extends Controller
{
    public function monitor(Request $request): View
    {
        Gate::authorize('view_llm_monitor');

        $filters = [
            'from' => (string) $request->query('from', now()->subDays(6)->toDateString()),
            'to' => (string) $request->query('to', now()->toDateString()),
            'workspace_id' => (string) $request->query('workspace_id', ''),
            'site_id' => (string) $request->query('site_id', ''),
            'feature' => (string) $request->query('feature', ''),
            'provider' => (string) $request->query('provider', ''),
            'model' => (string) $request->query('model', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $baseQuery = LlmRequest::query()
            ->with(['workspace:id,name,display_name', 'site:id,name', 'user:id,name'])
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when($filters['workspace_id'] !== '', fn (Builder $q) => $q->where('workspace_id', $filters['workspace_id']))
            ->when($filters['site_id'] !== '', fn (Builder $q) => $q->where('site_id', $filters['site_id']))
            ->when($filters['feature'] !== '', fn (Builder $q) => $q->where('feature', $filters['feature']))
            ->when($filters['provider'] !== '', fn (Builder $q) => $q->where('provider', $filters['provider']))
            ->when($filters['model'] !== '', fn (Builder $q) => $q->where('model', $filters['model']))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']));

        $stats = [
            'total_requests' => (clone $baseQuery)->count(),
            'input_tokens' => (int) (clone $baseQuery)->sum('input_tokens'),
            'output_tokens' => (int) (clone $baseQuery)->sum('output_tokens'),
            'total_tokens' => (int) (clone $baseQuery)->sum('total_tokens'),
            'credits_consumed' => (float) (clone $baseQuery)->sum('credits_consumed'),
            'input_cost_eur' => (float) (clone $baseQuery)->sum('input_cost_eur'),
            'output_cost_eur' => (float) (clone $baseQuery)->sum('output_cost_eur'),
            'total_cost_eur' => (float) (clone $baseQuery)->sum('total_cost_eur'),
            'avg_latency_ms' => (int) round((float) ((clone $baseQuery)->avg('latency_ms') ?? 0)),
        ];

        $errorsCount = (int) (clone $baseQuery)->where('status', 'error')->count();
        $stats['error_rate_pct'] = $stats['total_requests'] > 0
            ? round(($errorsCount / $stats['total_requests']) * 100, 2)
            : 0.0;

        $topErrors = (clone $baseQuery)
            ->where('status', 'error')
            ->selectRaw('COALESCE(error_type, "Unknown") as error_type, COUNT(*) as total')
            ->groupBy('error_type')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $rows = (clone $baseQuery)
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        $workspaces = Workspace::query()->orderBy('display_name')->orderBy('name')->get(['id', 'name', 'display_name']);

        $sites = ClientSite::query()
            ->orderBy('name')
            ->get(['id', 'name', 'workspace_id']);

        $features = LlmRequest::query()->select('feature')->distinct()->orderBy('feature')->pluck('feature')->all();
        $providers = LlmRequest::query()->select('provider')->distinct()->orderBy('provider')->pluck('provider')->all();
        $models = LlmRequest::query()->select('model')->whereNotNull('model')->distinct()->orderBy('model')->pluck('model')->all();

        return view('admin.llm.monitor', [
            'rows' => $rows,
            'stats' => $stats,
            'topErrors' => $topErrors,
            'filters' => $filters,
            'workspaces' => $workspaces,
            'sites' => $sites,
            'features' => $features,
            'providers' => $providers,
            'models' => $models,
        ]);
    }

    public function monitorShow(LlmRequest $llmRequest): View
    {
        Gate::authorize('view_llm_monitor');

        $llmRequest->load(['workspace:id,name,display_name', 'site:id,name', 'user:id,name']);

        return view('admin.llm.show', [
            'entry' => $llmRequest,
            'canViewDebug' => Gate::allows('manage_llm_settings'),
        ]);
    }

    public function settings(Request $request, LlmRoutingService $routing, LlmModelCatalog $modelCatalog): View
    {
        Gate::authorize('manage_llm_settings');

        $selectedWorkspaceId = (string) $request->query('workspace_id', '');

        $globalSettings = $routing->getGlobalSettings();
        $features = $routing->features();
        $providers = array_keys((array) config('llm.providers', []));
        $providerOptions = $this->providerOptions();

        $globalRules = LlmRoutingRule::query()
            ->where('scope_type', LlmRoutingService::SCOPE_GLOBAL)
            ->whereNull('scope_id')
            ->get()
            ->keyBy('feature');

        $workspaceRules = collect();
        if ($selectedWorkspaceId !== '') {
            $workspaceRules = LlmRoutingRule::query()
                ->where('scope_type', LlmRoutingService::SCOPE_WORKSPACE)
                ->where('scope_id', $selectedWorkspaceId)
                ->get()
                ->keyBy('feature');
        }

        $workspaces = Workspace::query()->orderBy('display_name')->orderBy('name')->get(['id', 'name', 'display_name']);

        $auditLogs = LlmSettingsAuditLog::query()
            ->with('actor:id,name')
            ->latest('created_at')
            ->limit(100)
            ->get();

        return view('admin.llm.settings', [
            'globalSettings' => $globalSettings,
            'features' => $features,
            'providers' => $providers,
            'providerOptions' => $providerOptions,
            'modelOptions' => $modelCatalog->options(),
            'allModelOptions' => $modelCatalog->allModelIds(),
            'globalRules' => $globalRules,
            'workspaceRules' => $workspaceRules,
            'workspaces' => $workspaces,
            'selectedWorkspaceId' => $selectedWorkspaceId,
            'auditLogs' => $auditLogs,
            'capabilities' => (array) config('llm.capabilities', []),
            'openAiBillingStatus' => $this->openAiBillingStatus(),
        ]);
    }

    public function updateGlobalSettings(
        Request $request,
        LlmRoutingService $routing,
        LlmSettingsAuditService $audit
    ): RedirectResponse {
        Gate::authorize('manage_llm_settings');

        $providers = array_keys((array) config('llm.providers', []));

        $data = $request->validate([
            'default_text_provider' => ['required', 'in:' . implode(',', $providers)],
            'default_image_provider' => ['required', 'in:' . implode(',', $providers)],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:900'],
            'retry_max' => ['required', 'integer', 'min:0', 'max:10'],
            'retry_backoff_ms' => ['required', 'integer', 'min:50', 'max:10000'],
            'default_text_model_map' => ['nullable', 'array'],
            'default_image_model_map' => ['nullable', 'array'],
        ]);

        $before = $routing->getGlobalSettings();

        foreach (['default_text_model_map', 'default_image_model_map'] as $mapKey) {
            $map = (array) ($data[$mapKey] ?? []);
            foreach ($providers as $provider) {
                $map[$provider] = trim((string) ($map[$provider] ?? ''));
                $this->ensureModelAllowed($provider, $map[$provider], $mapKey . '.' . $provider);
            }
            $data[$mapKey] = $map;
        }

        if (! $routing->supportsModality((string) $data['default_image_provider'], 'image')) {
            return back()->withErrors([
                'default_image_provider' => 'Selected default image provider does not support image generation.',
            ]);
        }

        $saved = $routing->saveGlobalSettings($data);

        $audit->log(
            actorUserId: (int) $request->user()->id,
            scopeType: LlmRoutingService::SCOPE_GLOBAL,
            scopeId: null,
            action: 'updated',
            before: $before,
            after: $saved->toArray(),
        );

        return back()->with('status', 'LLM global settings updated.');
    }

    public function upsertRule(
        Request $request,
        LlmRoutingService $routing,
        LlmSettingsAuditService $audit
    ): RedirectResponse {
        Gate::authorize('manage_llm_settings');

        $providers = array_keys((array) config('llm.providers', []));
        $features = array_keys($routing->features());

        $data = $request->validate([
            'scope_type' => ['required', 'in:global,workspace,site'],
            'scope_id' => ['nullable', 'string', 'max:64'],
            'feature' => ['required', 'in:' . implode(',', $features)],
            'modality' => ['required', 'in:text,image'],
            'inherit_global' => ['nullable', 'boolean'],
            'provider' => ['nullable', 'in:' . implode(',', $providers)],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^$|^[A-Za-z0-9._:-]+$/'],
            'fallback_enabled' => ['nullable', 'boolean'],
            'fallback_provider' => ['nullable', 'in:' . implode(',', $providers)],
            'fallback_model' => ['nullable', 'string', 'max:120', 'regex:/^$|^[A-Za-z0-9._:-]+$/'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        if ($data['scope_type'] === LlmRoutingService::SCOPE_GLOBAL) {
            $data['scope_id'] = null;
        }

        if (($data['scope_type'] ?? '') === LlmRoutingService::SCOPE_WORKSPACE && blank($data['scope_id'])) {
            return back()->withErrors(['scope_id' => 'Workspace scope requires a workspace.']);
        }

        foreach (['provider', 'fallback_provider'] as $providerField) {
            $provider = trim((string) ($data[$providerField] ?? ''));
            if ($provider !== '' && ! $routing->supportsModality($provider, (string) $data['modality'])) {
                return back()->withErrors([
                    $providerField => 'Selected provider does not support this modality.',
                ]);
            }
        }

        $data['inherit_global'] = (bool) ($data['inherit_global'] ?? false);
        $data['fallback_enabled'] = (bool) ($data['fallback_enabled'] ?? false);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);

        if ($data['inherit_global']) {
            $data['provider'] = null;
            $data['model'] = null;
        }

        $this->ensureModelAllowed((string) ($data['provider'] ?? ''), (string) ($data['model'] ?? ''), 'model');

        if (! $data['fallback_enabled']) {
            $data['fallback_provider'] = null;
            $data['fallback_model'] = null;
        }

        $this->ensureModelAllowed((string) ($data['fallback_provider'] ?? ''), (string) ($data['fallback_model'] ?? ''), 'fallback_model');

        $existing = LlmRoutingRule::query()
            ->where('scope_type', $data['scope_type'])
            ->where('scope_id', $data['scope_id'])
            ->where('feature', $data['feature'])
            ->first();

        $before = $existing?->toArray();

        $rule = LlmRoutingRule::query()->updateOrCreate(
            [
                'scope_type' => $data['scope_type'],
                'scope_id' => $data['scope_id'],
                'feature' => $data['feature'],
            ],
            [
                'id' => $existing?->id ?: (string) Str::uuid(),
                'modality' => $data['modality'],
                'inherit_global' => $data['inherit_global'],
                'provider' => $data['provider'] ?: null,
                'model' => $data['model'] ?: null,
                'fallback_enabled' => $data['fallback_enabled'],
                'fallback_provider' => $data['fallback_provider'] ?: null,
                'fallback_model' => $data['fallback_model'] ?: null,
                'is_enabled' => $data['is_enabled'],
            ],
        );

        $audit->log(
            actorUserId: (int) $request->user()->id,
            scopeType: (string) $rule->scope_type,
            scopeId: $rule->scope_id,
            action: $before ? 'updated' : 'created',
            before: $before,
            after: $rule->toArray(),
        );

        return back()->with('status', 'LLM routing rule saved.');
    }

    public function deleteRule(
        Request $request,
        LlmRoutingRule $rule,
        LlmSettingsAuditService $audit
    ): RedirectResponse {
        Gate::authorize('manage_llm_settings');

        $before = $rule->toArray();
        $rule->delete();

        $audit->log(
            actorUserId: (int) $request->user()->id,
            scopeType: (string) $before['scope_type'],
            scopeId: $before['scope_id'] ?? null,
            action: 'deleted',
            before: $before,
            after: null,
        );

        return back()->with('status', 'LLM routing rule deleted.');
    }

    public function testConnection(Request $request, LlmManager $llmManager): RedirectResponse
    {
        Gate::authorize('manage_llm_settings');

        $providers = array_keys((array) config('llm.providers', []));

        $data = $request->validate([
            'provider' => ['required', 'in:' . implode(',', $providers)],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^$|^[A-Za-z0-9._:-]+$/'],
            'modality' => ['required', 'in:text,image'],
        ]);

        $this->ensureModelAllowed((string) $data['provider'], (string) ($data['model'] ?? ''), 'model');

        try {
            if ($data['modality'] === 'image') {
                if ($data['provider'] === 'openai') {
                    $apiKey = trim((string) config('llm.providers.openai.api_key', ''));
                    $baseUrl = rtrim((string) config('llm.providers.openai.base_url', 'https://api.openai.com'), '/');
                    if ($apiKey === '') {
                        throw new \RuntimeException('OpenAI API key is not configured.');
                    }

                    $response = Http::withToken($apiKey)
                        ->acceptJson()
                        ->timeout(20)
                        ->get($baseUrl . '/v1/models');
                } elseif ($data['provider'] === 'gemini') {
                    $apiKey = trim((string) config('llm.providers.gemini.api_key', ''));
                    $baseUrl = rtrim((string) config('llm.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
                    if ($apiKey === '') {
                        throw new \RuntimeException('Gemini API key is not configured.');
                    }

                    $response = Http::acceptJson()
                        ->timeout(20)
                        ->get($baseUrl . '/models', ['key' => $apiKey]);
                } else {
                    throw new \RuntimeException('Selected provider does not support image connectivity testing.');
                }

                if (! $response->successful()) {
                    throw new \RuntimeException('Image provider test failed with HTTP ' . $response->status() . '.');
                }
            } else {
                $llmManager->generateText(new LlmProviderRequest(
                    messages: [new LlmMessage('user', 'Reply with ok')],
                    model: trim((string) ($data['model'] ?? '')) ?: null,
                    maxTokens: 16,
                    metadata: [
                        'provider' => (string) $data['provider'],
                        'feature' => 'intelligence_analysis',
                        'modality' => 'text',
                        'trigger' => 'admin_test_connection',
                        'userId' => (int) $request->user()->id,
                    ],
                ));
            }

            return back()->with('status', 'Provider test succeeded.');
        } catch (\Throwable $exception) {
            return back()->withErrors([
                'llm_test' => 'Provider test failed: ' . $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,string>
     */
    private function providerOptions(): array
    {
        $providers = array_keys((array) config('llm.providers', []));

        return collect($providers)->mapWithKeys(fn (string $provider): array => [
            $provider => $this->providerLabel($provider),
        ])->all();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'anthropic' => 'Claude',
            'gemini' => 'Gemini',
            'mistral' => 'Mistral',
            default => Str::headline($provider),
        };
    }

    /**
     * @return array{
     *     tone:string,
     *     label:string,
     *     message:string,
     *     auto_recharge_enabled:bool,
     *     api_key_configured:bool,
     *     project:string,
     *     image_provider:string,
     *     image_model:string,
     *     billing_url:string,
     *     last_issue:?array{created_at:string,message:string,code:string},
     *     last_success:?array{created_at:string,feature:string,modality:string}
     * }
     */
    private function openAiBillingStatus(): array
    {
        $apiKeyConfigured = trim((string) config('llm.providers.openai.api_key', '')) !== '';
        $autoRechargeEnabled = (bool) config('llm.providers.openai.auto_recharge_enabled', false);
        $billingUrl = (string) config('llm.providers.openai.billing_url', 'https://platform.openai.com/settings/organization/billing/overview');

        $lastBillingIssue = LlmRequest::query()
            ->where('provider', 'openai')
            ->where('status', 'error')
            ->latest('created_at')
            ->limit(50)
            ->get(['created_at', 'error_message', 'error_code'])
            ->first(fn (LlmRequest $entry): bool => $this->isOpenAiBillingIssue(
                (string) $entry->error_message,
                (string) $entry->error_code
            ));

        $lastSuccess = null;
        if ($lastBillingIssue) {
            $lastSuccess = LlmRequest::query()
                ->where('provider', 'openai')
                ->where('status', 'success')
                ->where('created_at', '>', $lastBillingIssue->created_at)
                ->latest('created_at')
                ->first(['created_at', 'feature', 'modality']);
        }

        $tone = 'success';
        $label = 'Ready';
        $message = 'OpenAI API key is configured and no recent billing block was detected in Argusly request logs.';

        if (! $apiKeyConfigured) {
            $tone = 'danger';
            $label = 'Not configured';
            $message = 'OPENAI_API_KEY is missing, so OpenAI requests cannot run.';
        } elseif ($lastBillingIssue && ! $lastSuccess) {
            $tone = $autoRechargeEnabled ? 'warning' : 'danger';
            $label = $autoRechargeEnabled ? 'Auto recharge enabled, waiting for recovery' : 'Billing blocked';
            $message = $autoRechargeEnabled
                ? 'Auto recharge is marked as enabled, but the latest OpenAI billing issue has not been followed by a successful OpenAI request yet.'
                : 'OpenAI recently blocked a request because billing credits or spend access were unavailable.';
        } elseif ($lastBillingIssue && $lastSuccess) {
            $tone = 'success';
            $label = 'Recovered';
            $message = 'A successful OpenAI request was logged after the latest billing issue.';
        }

        return [
            'tone' => $tone,
            'label' => $label,
            'message' => $message,
            'auto_recharge_enabled' => $autoRechargeEnabled,
            'api_key_configured' => $apiKeyConfigured,
            'project' => (string) config('llm.providers.openai.project', ''),
            'image_provider' => (string) config('argusly.ai.images.provider', 'openai'),
            'image_model' => (string) config('argusly.ai.images.openai.model', config('llm.providers.openai.default_model', '')),
            'billing_url' => $billingUrl,
            'last_issue' => $lastBillingIssue ? [
                'created_at' => optional($lastBillingIssue->created_at)->format('Y-m-d H:i:s') ?? '',
                'message' => Str::limit((string) $lastBillingIssue->error_message, 220),
                'code' => (string) ($lastBillingIssue->error_code ?: ''),
            ] : null,
            'last_success' => $lastSuccess ? [
                'created_at' => optional($lastSuccess->created_at)->format('Y-m-d H:i:s') ?? '',
                'feature' => (string) $lastSuccess->feature,
                'modality' => (string) $lastSuccess->modality,
            ] : null,
        ];
    }

    private function isOpenAiBillingIssue(string $message, string $code): bool
    {
        $text = Str::lower($message . ' ' . $code);

        return str_contains($text, 'billing hard limit')
            || str_contains($text, 'credit balance')
            || str_contains($text, 'insufficient_quota')
            || str_contains($text, 'quota exceeded')
            || str_contains($text, 'exceeded your current quota');
    }

    private function ensureModelAllowed(string $provider, string $model, string $field): void
    {
        $provider = trim($provider);
        $model = trim($model);

        if ($provider === '' || $model === '') {
            return;
        }

        $allowed = (array) config('llm.providers.' . $provider . '.allowed_models', []);
        if ($allowed === []) {
            return;
        }

        if (! in_array($model, $allowed, true)) {
            throw ValidationException::withMessages([
                $field => 'Selected model is not allowed for provider ' . $this->providerLabel($provider) . '.',
            ]);
        }
    }
}
