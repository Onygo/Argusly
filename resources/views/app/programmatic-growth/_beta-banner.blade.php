@php($bannerClass = trim('rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 '.($class ?? '')))
<div class="{{ $bannerClass }}">
    <div class="flex gap-3">
        <i data-lucide="shield-alert" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <p>
            <strong>Programmatic Growth is in controlled beta.</strong>
            It can prepare assets, reviews, content and scheduled publication records. It does not publish live content automatically.
        </p>
    </div>
</div>
