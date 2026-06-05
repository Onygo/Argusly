<x-app.layout title="Admin Modules" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Modules & Subscriptions</h1>
    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_380px]">
        <div class="space-y-4">
            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Plans</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-[0.12em] text-muted">
                                <th class="py-2 pr-3">Plan</th>
                                <th class="px-3 py-2">Price</th>
                                <th class="px-3 py-2">Modules</th>
                                <th class="px-3 py-2">Subscriptions</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="py-2 pl-3">Update</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($plans as $plan)
                                <tr>
                                    <td class="py-3 pr-3">
                                        <div class="font-semibold text-ink">{{ $plan->name }}</div>
                                        <div class="text-xs text-muted">{{ $plan->key }}</div>
                                    </td>
                                    <td class="px-3 py-3">{{ $plan->currency }} {{ number_format($plan->amount / 100, 2) }} / {{ $plan->billing_interval }}</td>
                                    <td class="px-3 py-3 text-muted">{{ $plan->modules->pluck('name')->join(', ') ?: 'No modules' }}</td>
                                    <td class="px-3 py-3">{{ $plan->subscriptions_count }}</td>
                                    <td class="px-3 py-3">{{ $plan->is_active ? 'Active' : 'Inactive' }}</td>
                                    <td class="py-3 pl-3">
                                        <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="grid min-w-[420px] gap-2 md:grid-cols-[1fr_92px_110px_84px_auto]">
                                            @csrf
                                            @method('PUT')
                                            <input name="name" value="{{ $plan->name }}" required class="rounded-md border border-line px-3 py-2 text-sm">
                                            <input name="amount" value="{{ $plan->amount }}" required type="number" min="0" class="rounded-md border border-line px-3 py-2 text-sm">
                                            <select name="billing_interval" class="rounded-md border border-line px-3 py-2 text-sm">
                                                @foreach (['monthly', 'yearly', 'one_time'] as $interval)
                                                    <option value="{{ $interval }}" @selected($plan->billing_interval === $interval)>{{ $interval }}</option>
                                                @endforeach
                                            </select>
                                            <input name="currency" value="{{ $plan->currency }}" required maxlength="3" class="rounded-md border border-line px-3 py-2 text-sm uppercase">
                                            <label class="inline-flex items-center gap-2 rounded-md border border-line px-3 py-2 text-sm">
                                                <input type="checkbox" name="is_active" value="1" @checked($plan->is_active)>
                                                Active
                                            </label>
                                            <input type="hidden" name="description" value="{{ $plan->description }}">
                                            <button class="rounded-md bg-blue px-3 py-2 text-sm font-semibold text-white md:col-span-5">Save plan</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-6 text-sm text-muted">No plans available.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Modules</h2>
                @include('admin._table', ['rows' => $modules, 'columns' => ['name', 'key', 'is_active', 'subscription_modules_count']])
            </section>
            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Account Subscriptions</h2>
                @include('admin._table', ['rows' => $accounts, 'columns' => ['name', 'activeSubscription.status', 'activeSubscription.plan.name', 'created_at']])
            </section>
        </div>
        <form method="POST" action="{{ route('admin.modules.enable') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Enable Or Disable Module</h2>
            <div class="mt-4 space-y-3">
                <select name="account_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select>
                <select name="module_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">@foreach ($modules as $module)<option value="{{ $module->id }}">{{ $module->name }}</option>@endforeach</select>
                <select name="status" class="w-full rounded-md border border-line px-3 py-2 text-sm"><option value="active">Active</option><option value="disabled">Disabled</option><option value="paused">Paused</option></select>
                <button class="w-full rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Update module</button>
                <a href="{{ route('admin.billing') }}" class="block text-center text-sm font-semibold text-blue">Open billing operations</a>
            </div>
        </form>
    </div>
</x-app.layout>
