@php
    $statusValue = strtolower(trim((string) ($status ?? 'unknown')));
    $label = $label ?? \Illuminate\Support\Str::headline($statusValue);
    $classes = match ($statusValue) {
        'active', 'connected', 'succeeded', 'healthy', 'info' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'draft', 'pending', 'running', 'warning', 'degraded' => 'border-amber-200 bg-amber-50 text-amber-800',
        'inactive', 'skipped', 'cancelled', 'disabled' => 'border-slate-200 bg-slate-50 text-slate-700',
        'expired', 'expired_token', 'needs_reconnect', 'rate_limited', 'revoked', 'error', 'failed', 'critical' => 'border-rose-200 bg-rose-50 text-rose-800',
        default => 'border-border bg-surfaceSubtle text-textSecondary',
    };
@endphp

<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $classes }}">
    {{ $label }}
</span>
