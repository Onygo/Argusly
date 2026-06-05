{{--
    Automation error alert partial.

    @param \App\Support\Errors\AutomationErrorPresenter $error - The error presenter instance
    @param bool $canViewTechnicalDetails - Whether the current user can see admin details
--}}

@props([
    'error',
    'canViewTechnicalDetails' => false,
])

@if ($error->hasError())
    <div class="rounded-lg border border-rose-200 bg-rose-50 p-4" x-data="{ showDetails: false }">
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rose-100">
                <i data-lucide="alert-circle" class="h-4 w-4 text-rose-600" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-rose-800">{{ $error->publicErrorTitle() }}</h3>
                        <p class="mt-1 text-sm text-rose-700">{{ $error->publicErrorMessage() }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="inline-flex items-center rounded border border-rose-200 bg-white px-2 py-1 font-mono text-xs text-rose-700">
                            {{ $error->publicErrorCode() }}
                        </span>
                    </div>
                </div>

                <p class="mt-3 text-xs text-rose-600">{{ $error->supportHint() }}</p>

                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-rose-200 pt-3">
                    @if ($error->failedAt())
                        <span class="text-xs text-rose-600">
                            Failed: {{ $error->failedAt() }}
                        </span>
                    @endif

                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded border border-rose-200 bg-white px-2 py-1 text-xs text-rose-700 hover:bg-rose-50"
                        x-data="{ copied: false }"
                        x-on:click="
                            navigator.clipboard.writeText('{{ $error->supportCode() }}');
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        "
                    >
                        <i data-lucide="copy" class="h-3 w-3" x-show="!copied" aria-hidden="true"></i>
                        <i data-lucide="check" class="h-3 w-3" x-show="copied" x-cloak aria-hidden="true"></i>
                        <span x-text="copied ? 'Copied!' : 'Copy support code'"></span>
                    </button>

                    @if ($canViewTechnicalDetails)
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 text-xs text-rose-600 hover:text-rose-800"
                            x-on:click="showDetails = !showDetails"
                        >
                            <i data-lucide="chevron-down" class="h-3 w-3 transition-transform" x-bind:class="{ 'rotate-180': showDetails }" aria-hidden="true"></i>
                            <span x-text="showDetails ? 'Hide technical details' : 'Show technical details'"></span>
                        </button>
                    @endif
                </div>

                @if ($canViewTechnicalDetails)
                    <div
                        x-show="showDetails"
                        x-collapse
                        x-cloak
                        class="mt-3 rounded border border-rose-200 bg-white p-3"
                    >
                        <p class="mb-2 text-xs font-medium text-rose-800">Technical Details (Admin only)</p>

                        @if ($error->adminSummary())
                            <div class="mb-2 rounded bg-rose-50 p-2">
                                <p class="font-mono text-xs text-rose-700">{{ $error->adminSummary() }}</p>
                            </div>
                        @endif

                        @if ($error->technicalDetails())
                            <div class="rounded bg-gray-900 p-2">
                                <pre class="overflow-x-auto whitespace-pre-wrap font-mono text-xs text-gray-100">{{ $error->technicalDetails() }}</pre>
                            </div>
                        @endif

                        @if ($error->isSensitive())
                            <p class="mt-2 text-xs italic text-rose-500">
                                This error message may contain sensitive database or system information.
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
