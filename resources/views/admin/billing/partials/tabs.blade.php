<div class="rounded-md border border-border bg-surface p-6">
    <div class="mb-4 flex flex-wrap gap-4 border-b border-divider">
        <a href="{{ route('admin.organizations.billing', [$organization, 'tab' => 'ledger']) }}" class="pl-tab {{ $activeTab === 'ledger' ? 'pl-tab-active' : '' }}">Ledger</a>
        <a href="{{ route('admin.organizations.billing', [$organization, 'tab' => 'payments']) }}" class="pl-tab {{ $activeTab === 'payments' ? 'pl-tab-active' : '' }}">Payments</a>
        <a href="{{ route('admin.organizations.billing', [$organization, 'tab' => 'subscriptions']) }}" class="pl-tab {{ $activeTab === 'subscriptions' ? 'pl-tab-active' : '' }}">Subscriptions</a>
    </div>

    @if ($activeTab === 'ledger')
        @include('admin.billing.partials.tab-ledger')
    @elseif ($activeTab === 'payments')
        @include('admin.billing.partials.tab-payments')
    @else
        @include('admin.billing.partials.tab-subscriptions')
    @endif
</div>
