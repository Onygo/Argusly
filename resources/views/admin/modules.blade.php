<x-app.layout title="Admin Modules" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Modules & Subscriptions</h1>
    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_380px]">
        <div class="space-y-4">
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
                <p class="text-xs text-muted">Plan update and payment-provider changes are placeholders in this phase.</p>
            </div>
        </form>
    </div>
</x-app.layout>
