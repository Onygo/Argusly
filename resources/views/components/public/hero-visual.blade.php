@props([
    'variant' => 'landing',
    'schematic' => null,
    'showChrome' => false,
    'desktopWrapperClass' => 'mx-auto hidden max-w-4xl rounded-2xl border border-border bg-surface md:block',
    'desktopInnerClass' => 'p-5 md:p-8',
])

<div class="mx-auto flex w-full max-w-4xl justify-center px-4 sm:px-6 md:hidden">
    <div class="w-full max-w-[21rem] rounded-[2.2rem] border border-border bg-white p-2.5">
        <div class="overflow-hidden rounded-[1.8rem] border border-border bg-[#f3f1ec]">
            <div class="flex items-center justify-center border-b border-border bg-white px-5 py-3">
                <div class="h-1.5 w-16 rounded-full bg-[#d8d2c8]"></div>
            </div>

            <div class="space-y-3.5 p-4">
                <div class="rounded-2xl border border-border bg-white p-3.5">
                    <div class="h-28 rounded-xl bg-[#e2ddd4]"></div>
                    <div class="mt-3.5 h-3 w-3/5 rounded-full bg-[#d8d2c8]"></div>
                    <div class="mt-2 h-2.5 w-4/5 rounded-full bg-[#d8d2c8]"></div>
                    <div class="mt-1.5 h-2.5 w-2/3 rounded-full bg-[#d8d2c8]"></div>
                </div>

                <div class="grid grid-cols-2 gap-3.5">
                    <div class="rounded-2xl border border-border bg-white p-3.5">
                        <div class="h-12 rounded-xl bg-[#e2ddd4]"></div>
                        <div class="mt-3 h-2.5 w-4/5 rounded-full bg-[#d8d2c8]"></div>
                    </div>
                    <div class="rounded-2xl border border-border bg-white p-3.5">
                        <div class="h-12 rounded-xl bg-[#e2ddd4]"></div>
                        <div class="mt-3 h-2.5 w-3/5 rounded-full bg-[#d8d2c8]"></div>
                    </div>
                </div>

                <div class="rounded-2xl border border-border bg-white p-3.5">
                    <div class="flex items-center gap-2.5">
                        <div class="h-9 w-9 rounded-xl bg-[#e2ddd4]"></div>
                        <div class="flex-1">
                            <div class="h-2.5 w-2/5 rounded-full bg-[#d8d2c8]"></div>
                            <div class="mt-1.5 h-2.5 w-3/5 rounded-full bg-[#d8d2c8]"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="{{ $desktopWrapperClass }}">
    @if ($showChrome)
        <div class="flex items-center gap-2 border-b border-border bg-white px-4 py-3">
            <span class="h-2.5 w-2.5 rounded-full bg-surfaceMuted"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-surfaceMuted"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-publicPrimary/30"></span>
            <div class="ml-2 h-6 flex-1 rounded-md bg-[#f6f5f2]"></div>
        </div>
    @endif

    <div class="{{ $desktopInnerClass }}">
        <x-schematic-grid :variant="$variant" :schematic="$schematic" />
    </div>
</div>
