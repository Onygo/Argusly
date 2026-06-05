<x-app.settings.layout title="LLM settings" description="Choose provider defaults for this account and current brand. API keys are managed through environment variables only.">
    <div class="space-y-4">
        @if (session('status'))
            <p class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</p>
        @endif

        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Available providers</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($providers as $provider)
                    <div class="rounded-md border border-line p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-ink">{{ $provider->name }}</p>
                                <p class="font-mono text-xs text-muted">{{ $provider->provider }}</p>
                            </div>
                            @include('admin._status', ['value' => $provider->status])
                        </div>
                        <p class="mt-3 text-sm text-muted">{{ $provider->models_count }} active {{ str('model')->plural($provider->models_count) }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        @php
            $forms = [
                ['scope' => 'account', 'title' => 'Account default', 'setting' => $accountSetting, 'enabled' => true],
                ['scope' => 'brand', 'title' => 'Brand default', 'setting' => $brandSetting, 'enabled' => filled($brand)],
            ];
        @endphp

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ($forms as $form)
                <form method="POST" action="{{ route('settings.llm.update') }}" class="rounded-md border border-line bg-white p-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="scope" value="{{ $form['scope'] }}">
                    <div>
                        <h2 class="text-lg font-bold text-ink">{{ $form['title'] }}</h2>
                        <p class="text-sm text-muted">
                            @if ($form['scope'] === 'brand')
                                {{ $brand ? 'Applies to '.$brand->name.'.' : 'Select a brand to configure brand-specific defaults.' }}
                            @else
                                Applies to {{ $account->name }} unless a brand override exists.
                            @endif
                        </p>
                    </div>
                    <fieldset @disabled(! $form['enabled']) class="mt-4 space-y-3">
                        <label class="block text-sm font-semibold text-ink">Default provider
                            <select name="default_provider_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                                <option value="">Inherit</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected($form['setting']?->default_provider_id === $provider->id)>{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-ink">Default model
                            <select name="default_model_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                                <option value="">First active model for provider</option>
                                @foreach ($providers as $provider)
                                    <optgroup label="{{ $provider->name }}">
                                        @foreach ($models->where('provider_id', $provider->id) as $model)
                                            <option value="{{ $model->id }}" @selected($form['setting']?->default_model_id === $model->id)>{{ $model->name }} · {{ $model->model }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-ink">Fallback provider
                            <select name="fallback_provider_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                                <option value="">None</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected($form['setting']?->fallback_provider_id === $provider->id)>{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-ink">Fallback model
                            <select name="fallback_model_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                                <option value="">None</option>
                                @foreach ($providers as $provider)
                                    <optgroup label="{{ $provider->name }}">
                                        @foreach ($models->where('provider_id', $provider->id) as $model)
                                            <option value="{{ $model->id }}" @selected($form['setting']?->fallback_model_id === $model->id)>{{ $model->name }} · {{ $model->model }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </label>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="block text-sm font-semibold text-ink">Temperature
                                <input name="temperature" type="number" step="0.01" min="0" max="2" value="{{ $form['setting']?->temperature }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">Max tokens
                                <input name="max_tokens" type="number" min="1" value="{{ $form['setting']?->max_tokens }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                        </div>
                        @php($policy = $form['setting']?->settings ?? [])
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="block text-sm font-semibold text-ink">Allowed providers
                                <input name="allowed_providers" value="{{ implode(', ', $policy['allowed_providers'] ?? []) }}" placeholder="openai, anthropic" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">Denied providers
                                <input name="denied_providers" value="{{ implode(', ', $policy['denied_providers'] ?? []) }}" placeholder="openrouter" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">Allowed models
                                <input name="allowed_models" value="{{ implode(', ', $policy['allowed_models'] ?? []) }}" placeholder="gpt-4.1-mini" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">Denied models
                                <input name="denied_models" value="{{ implode(', ', $policy['denied_models'] ?? []) }}" placeholder="deprecated-model" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">Workspace monthly credit budget
                                <input name="monthly_credit_budget" type="number" min="0" value="{{ $policy['monthly_credit_budget'] ?? '' }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">Brand monthly credit budget
                                <input name="brand_monthly_credit_budget" type="number" min="0" value="{{ $policy['brand_monthly_credit_budget'] ?? '' }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                            <label class="block text-sm font-semibold text-ink">User monthly credit budget
                                <input name="user_monthly_credit_budget" type="number" min="0" value="{{ $policy['user_monthly_credit_budget'] ?? '' }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            </label>
                        </div>
                        <button class="rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Save {{ str($form['scope'])->headline() }}</button>
                    </fieldset>
                </form>
            @endforeach
        </div>
    </div>
</x-app.settings.layout>
