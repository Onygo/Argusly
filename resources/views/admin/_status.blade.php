@php
    $status = str((string) $value)->lower()->toString();
    $class = match ($status) {
        'active', 'ok', 'completed', 'processed', 'healthy', 'delivered' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'failed', 'error', 'unhealthy', 'attention', 'critical', 'spam' => 'bg-red-50 text-red-700 border-red-200',
        'pending', 'queued', 'running', 'placeholder', 'warning', 'processing' => 'bg-amber-50 text-amber-700 border-amber-200',
        'paused', 'disabled', 'revoked', 'archived' => 'bg-slate-100 text-slate-700 border-slate-200',
        default => 'bg-blue/10 text-blue border-blue/20',
    };
@endphp

<span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold {{ $class }}">{{ $value ?: 'n/a' }}</span>
