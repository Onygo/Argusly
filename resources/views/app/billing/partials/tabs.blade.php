<div class="rounded-md border border-border bg-surface p-6">
    <div class="mb-4 flex flex-wrap gap-4 border-b border-divider">
        <a href="{{ route('app.billing.index', ['tab' => 'ledger']) }}" class="pl-tab {{ $activeTab === 'ledger' ? 'pl-tab-active' : '' }}">Ledger</a>
        <a href="{{ route('app.billing.index', ['tab' => 'payments']) }}" class="pl-tab {{ $activeTab === 'payments' ? 'pl-tab-active' : '' }}">Payments</a>
        <a href="{{ route('app.billing.index', ['tab' => 'subscriptions']) }}" class="pl-tab {{ $activeTab === 'subscriptions' ? 'pl-tab-active' : '' }}">Subscriptions</a>
    </div>

    @if ($activeTab === 'ledger')
        @include('app.billing.partials.tab-ledger')
    @elseif ($activeTab === 'payments')
        @include('app.billing.partials.tab-payments')
    @else
        @include('app.billing.partials.tab-subscriptions')
    @endif
</div>
