@extends('layouts.app', ['title' => 'Generate Multiple Articles', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Generate multiple articles</h1>
            <p class="mt-1 text-textSecondary">Create up to 10 related articles from one main keyword as a coherent content cluster.</p>
        </div>
        <a href="{{ route('app.content.index') }}" class="rounded border border-border px-3 py-2 text-sm">Back to content</a>
    </div>

    @if ($errors->has('batch'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('batch') }}</div>
    @endif

    <form method="POST" action="{{ route('app.content.batches.store') }}" class="space-y-4 rounded-lg border border-border bg-surface p-4">
        @csrf
        <div class="grid gap-3 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Workspace</label>
                <select name="workspace_id" class="pl-select bg-background" required>
                    @foreach ($workspaces as $ws)
                        <option value="{{ $ws->id }}" @selected(old('workspace_id', $workspace->id) === (string) $ws->id)>{{ $ws->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site</label>
                <select name="client_site_id" class="pl-select bg-background">
                    <option value="">Select site</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected(old('client_site_id') === (string) $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Main keyword</label>
                <input type="text" name="main_keyword" value="{{ old('main_keyword') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Language</label>
                <input type="text" name="language" value="{{ old('language', 'nl') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="10">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Tone</label>
                <input type="text" name="tone" value="{{ old('tone') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Length</label>
                <select name="preferred_length" class="pl-select bg-background">
                    @foreach (['short' => 'Short', 'medium' => 'Medium', 'long' => 'Long', 'pillar' => 'Pillar'] as $key => $label)
                        <option value="{{ $key }}" @selected(old('preferred_length', 'medium') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Audience</label>
                <input type="text" name="audience" value="{{ old('audience', $generationDefaults['audience_persona'] ?? '') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Brand voice</label>
                <select name="brand_voice_id" class="pl-select bg-background">
                    <option value="">Auto default</option>
                    @foreach ($brandVoices as $voice)
                        <option value="{{ $voice->id }}" @selected(old('brand_voice_id', $generationDefaults['brand_voice_id'] ?? '') === (string) $voice->id)>{{ $voice->name }}{{ $voice->is_default ? ' (default)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Buyer persona</label>
                <select name="buyer_persona_id" class="pl-select bg-background">
                    <option value="">Auto audience</option>
                    @foreach ($buyerPersonas as $persona)
                        <option value="{{ $persona->id }}" @selected(old('buyer_persona_id', (string) ($generationDefaults['buyer_persona_id'] ?? '')) === (string) $persona->id)>{{ $persona->name }}{{ data_get($persona->profile_data, 'role') ? ' - ' . data_get($persona->profile_data, 'role') : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Team member</label>
                <select name="team_member_id" class="pl-select bg-background">
                    <option value="">Company perspective</option>
                    @foreach ($teamMembers as $member)
                        <option value="{{ $member->id }}" @selected(old('team_member_id', (string) ($generationDefaults['team_member_id'] ?? '')) === (string) $member->id)>{{ $member->name }}{{ $member->role ? ' - ' . $member->role : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Output type</label>
                <input type="text" name="output_type" value="{{ old('output_type', 'kb_article') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Optional notes</label>
            <textarea name="notes" rows="2" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('notes') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Subkeywords (max 10)</label>
            <textarea name="subkeywords_text" rows="8" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" placeholder="one line per item: subkeyword|angle|intent" required>{{ old('subkeywords_text') }}</textarea>
            <p class="mt-2 text-xs text-textSecondary">Format per line: <code>subkeyword|angle|intent</code>. Angle and intent are optional.</p>
            <div class="mt-2">
                <button type="button" id="suggest-subkeywords-btn" class="rounded border border-border px-3 py-2 text-xs">Suggest subkeywords (AI)</button>
            </div>
            <div id="suggest-subkeywords-status" class="mt-2 text-xs text-textSecondary"></div>
            <div id="suggest-subkeywords-list" class="mt-2 hidden rounded border border-border bg-background p-3 text-xs text-textSecondary"></div>
            <p class="mt-1 text-xs text-textSecondary">Examples:</p>
            <ul class="mt-1 space-y-1 text-xs text-textSecondary">
                @foreach ($subkeywordExamples as $example)
                    <li><code>{{ $example }}</code></li>
                @endforeach
            </ul>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded border border-border bg-background p-3">
            <p class="text-xs text-textSecondary">Credit estimate is calculated at batch creation and shown on the batch detail page.</p>
            <button class="rounded border border-border px-3 py-2 text-sm">Create batch</button>
        </div>
    </form>

    <script>
        (() => {
            const btn = document.getElementById('suggest-subkeywords-btn');
            const status = document.getElementById('suggest-subkeywords-status');
            const list = document.getElementById('suggest-subkeywords-list');
            const token = document.querySelector('input[name="_token"]');
            const mainKeyword = document.querySelector('input[name="main_keyword"]');
            const language = document.querySelector('input[name="language"]');
            const textarea = document.querySelector('textarea[name="subkeywords_text"]');

            if (!btn || !status || !list || !token || !mainKeyword || !textarea) {
                return;
            }

            btn.addEventListener('click', async () => {
                const keyword = mainKeyword.value.trim();
                if (!keyword) {
                    status.textContent = 'Enter a main keyword first.';
                    return;
                }

                btn.disabled = true;
                status.textContent = 'Generating suggestions...';
                list.classList.add('hidden');
                list.innerHTML = '';

                try {
                    const response = await fetch(@json(route('app.content.batches.suggest')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': token.value,
                        },
                        body: JSON.stringify({
                            main_keyword: keyword,
                            language: language ? language.value : 'nl',
                            subkeywords_text: textarea.value,
                        }),
                    });

                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Suggestion request failed.');
                    }

                    const lines = Array.isArray(data.lines) ? data.lines : [];
                    if (lines.length === 0) {
                        status.textContent = 'No suggestions returned.';
                        return;
                    }

                    const current = textarea.value.trim();
                    const merged = [current, ...lines].filter(Boolean).join('\n');
                    const unique = Array.from(new Set(merged.split('\n').map(v => v.trim()).filter(Boolean))).slice(0, 10);
                    textarea.value = unique.join('\n');

                    const items = Array.isArray(data.items) ? data.items : [];
                    const html = items.map((item, i) => {
                        const sub = item.subkeyword || '';
                        const angle = item.angle || '-';
                        const intent = item.intent || '-';
                        const diff = item.differentiator || '-';
                        return `<div class="mb-2"><strong>${i + 1}. ${sub}</strong><br><span>angle: ${angle} · intent: ${intent}</span><br><span>differentiator: ${diff}</span></div>`;
                    }).join('');
                    list.innerHTML = html;
                    list.classList.remove('hidden');

                    status.textContent = 'Suggestions added to subkeywords list (max 10, unique).';
                } catch (error) {
                    status.textContent = error && error.message ? error.message : 'Suggestion request failed.';
                } finally {
                    btn.disabled = false;
                }
            });
        })();
    </script>
@endsection
