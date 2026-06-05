@extends('layouts.app', ['title' => 'Team Member Personas'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <nav class="mb-2 text-sm text-textSecondary">
                <span>Brand</span>
                <span class="mx-1">/</span>
                <span class="text-textPrimary">Team Member Personas</span>
            </nav>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Team Personas</h1>
            <p class="mt-1 text-textSecondary">Generate suggested authors with AI, then refine perspective, expertise and writing-role toggles manually.</p>
        </div>
        <a href="{{ route('app.workspace-intelligence.index') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
            Workspace Intelligence
        </a>
    </div>

    @include('app.brand.partials.tabs')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="space-y-6">
        @include('app.brand.partials.ai-entry', [
            'section' => 'team_personas',
            'manualTarget' => 'manual',
            'latestBrandContext' => $latestBrandContext,
            'title' => 'Generate team personas with AI',
            'description' => 'Create suggested authors such as Founder, CTO, Marketing Lead or Product Specialist, then decide which ones can be used as writing personas.',
        ])

        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="mb-5 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-textPrimary">Persona library</h2>
                    <p class="mt-1 text-sm text-textSecondary">Use these authors to give generated content a clear point of view instead of a generic company voice.</p>
                </div>
                <span class="text-xs text-textSecondary">Organization: {{ $organization?->name ?? 'n/a' }}</span>
            </div>

            @php($canManage = auth()->user()?->can('manage-organization'))

            <div class="grid gap-4 2xl:grid-cols-2">
                @forelse ($teamMembers as $member)
                    <div class="rounded-lg border border-border bg-background p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-base font-semibold text-textPrimary">{{ $member->name }}</h3>
                                    @if ($member->is_active)
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700">Active</span>
                                    @else
                                        <span class="rounded-full bg-surfaceSubtle px-2 py-1 text-xs font-medium text-textSecondary">Inactive</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-textSecondary">{{ $member->title ?: $member->role ?: 'No role defined yet.' }}</p>
                            </div>
                            @if ($canManage)
                                <form method="POST" action="{{ route('app.brand.team-members.toggle', $member) }}">
                                    @csrf
                                    <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                        {{ $member->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if ($canManage)
                            <form method="POST" action="{{ route('app.brand.team-members.update', $member) }}" class="mt-4 space-y-4">
                                @csrf
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Name</label>
                                        <input name="name" value="{{ $member->name }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Role / title</label>
                                        <input name="title" value="{{ $member->title ?: $member->role }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    </div>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Email</label>
                                        <input name="email" value="{{ $member->email }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Public profile URL</label>
                                        <input name="public_profile_url" value="{{ $member->public_profile_url }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    </div>
                                </div>

                                <div>
                                    <label class="text-xs text-textSecondary">Bio source text</label>
                                    <textarea name="bio_source_text" rows="3" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $member->bio_source_text }}</textarea>
                                </div>

                                <div class="grid gap-4 xl:grid-cols-3">
                                    <div>
                                        <label class="text-xs text-textSecondary">Expertise</label>
                                        <textarea id="team-expertise-{{ $member->id }}" name="expertise" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $member->expertise }}</textarea>
                                        <x-app.ai-field-actions target="#team-expertise-{{ $member->id }}" context="Team persona expertise" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Writing perspective</label>
                                        <textarea id="team-perspective-{{ $member->id }}" name="writing_perspective" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $member->writing_perspective }}</textarea>
                                        <x-app.ai-field-actions target="#team-perspective-{{ $member->id }}" context="Team persona writing perspective" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Tone variation / traits</label>
                                        <textarea id="team-traits-{{ $member->id }}" name="personality_traits" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $member->personality_traits }}</textarea>
                                        <x-app.ai-field-actions target="#team-traits-{{ $member->id }}" context="Team persona tone variation" />
                                    </div>
                                </div>

                                <div class="grid gap-3 lg:grid-cols-2">
                                    <label class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm text-textPrimary">
                                        <input type="hidden" name="use_as_writing_persona" value="0">
                                        <input type="checkbox" name="use_as_writing_persona" value="1" @checked((bool) data_get($member->profile_data, 'use_as_writing_persona', false))>
                                        Use as writing persona
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm text-textPrimary">
                                        <input type="hidden" name="link_to_real_team_member_later" value="0">
                                        <input type="checkbox" name="link_to_real_team_member_later" value="1" @checked((bool) data_get($member->profile_data, 'link_to_real_team_member_later', false))>
                                        Link to real team member later
                                    </label>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <button class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Save persona</button>
                                    <a href="{{ route('app.brand.wizard', ['section' => 'team_personas', 'mode' => 'regenerate']) }}" class="text-xs text-primary hover:underline">Regenerate with AI</a>
                                </div>
                            </form>
                        @else
                            <div class="mt-4 text-sm text-textSecondary">{{ $member->writing_perspective ?: 'No writing perspective defined.' }}</div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-border bg-background px-4 py-6 text-sm text-textSecondary">
                        No team personas yet. Generate suggestions with AI or add the first one manually below.
                    </div>
                @endforelse
            </div>
        </div>

        @can('manage-organization')
            <div id="manual" class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-lg font-semibold text-textPrimary">Add team persona manually</h2>
                <p class="mt-1 text-sm text-textSecondary">Create a fictional or real author perspective that can later be linked to an actual team member profile.</p>

                <form method="POST" action="{{ route('app.brand.team-members.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="text-xs text-textSecondary">Name</label>
                            <input name="name" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Role / title</label>
                            <input name="title" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="text-xs text-textSecondary">Email</label>
                            <input name="email" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Public profile URL</label>
                            <input name="public_profile_url" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="text-xs text-textSecondary">Bio source text</label>
                        <textarea name="bio_source_text" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-3">
                        <div>
                            <label class="text-xs text-textSecondary">Expertise</label>
                            <textarea id="new-team-expertise" name="expertise" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-team-expertise" context="Team persona expertise" />
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Writing perspective</label>
                            <textarea id="new-team-perspective" name="writing_perspective" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-team-perspective" context="Team persona writing perspective" />
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Tone variation / traits</label>
                            <textarea id="new-team-traits" name="personality_traits" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-team-traits" context="Team persona tone variation" />
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <label class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm text-textPrimary">
                            <input type="hidden" name="use_as_writing_persona" value="0">
                            <input type="checkbox" name="use_as_writing_persona" value="1" checked>
                            Use as writing persona
                        </label>
                        <label class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm text-textPrimary">
                            <input type="hidden" name="link_to_real_team_member_later" value="0">
                            <input type="checkbox" name="link_to_real_team_member_later" value="1" checked>
                            Link to real team member later
                        </label>
                    </div>

                    <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Add team persona</button>
                </form>
            </div>
        @endcan
    </div>
@endsection
