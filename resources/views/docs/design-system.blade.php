<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Argusly UI System</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-background text-textPrimary">
<div class="pl-page space-y-6">
    <header class="space-y-2">
        <h1 class="text-3xl font-semibold">OpenAI-like UI System</h1>
        <p class="text-sm text-textSecondary">Token and component reference for public, app, and admin interfaces.</p>
    </header>

    <section class="pl-panel space-y-4">
        <h2 class="text-xl font-semibold">Tokens</h2>
        <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-3">
            <div><span class="font-medium">Page:</span> <code>bg-background</code></div>
            <div><span class="font-medium">Surface:</span> <code>bg-surface</code></div>
            <div><span class="font-medium">Subtle:</span> <code>bg-surfaceSubtle</code></div>
            <div><span class="font-medium">Muted:</span> <code>bg-surfaceMuted</code></div>
            <div><span class="font-medium">Border:</span> <code>border-border</code></div>
            <div><span class="font-medium">Focus ring:</span> <code>ring-primarySoftRing</code></div>
        </div>
    </section>

    <section class="pl-panel space-y-4">
        <h2 class="text-xl font-semibold">Core Components</h2>
        <div class="flex flex-wrap gap-2">
            <button class="pl-btn-primary" type="button">Primary</button>
            <button class="pl-btn-secondary" type="button">Secondary</button>
            <button class="pl-btn-ghost" type="button">Ghost</button>
            <button class="pl-icon-btn" type="button" aria-label="Icon">
                <span>+</span>
            </button>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
            <input class="pl-input" placeholder="Standard input">
            <div class="relative">
                <input class="pl-search" placeholder="Search input">
            </div>
            <textarea class="pl-textarea md:col-span-2" rows="3" placeholder="Textarea"></textarea>
        </div>
        <div class="flex gap-2">
            <span class="pl-badge">Neutral</span>
            <span class="status-approved">Approved</span>
            <span class="status-review">Review</span>
        </div>
    </section>
</div>
</body>
</html>
