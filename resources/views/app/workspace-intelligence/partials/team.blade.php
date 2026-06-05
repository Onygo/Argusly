<div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr),minmax(320px,0.8fr)]">
    <div class="space-y-4">
        <x-workspace-intelligence.card
            title="Team profiles"
            description="Use approved expert profiles for author voice, workflow context, and subject-matter credibility."
            icon="briefcase"
        >
            <div class="space-y-3">
                @forelse (($hub['team']['cards'] ?? []) as $member)
                    <details class="group rounded-lg border border-border bg-background p-4">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-textPrimary">{{ $member['name'] }}</h3>
                                    <x-workspace-intelligence.status-badge :label="$member['status']['label']" :tone="$member['status']['tone']" :icon="$member['status']['icon']" />
                                </div>
                                <p class="mt-1 text-sm text-textSecondary">{{ $member['role'] }}</p>
                                @if (($member['summary'] ?? '') !== '')
                                    <p class="mt-3 max-w-3xl text-sm leading-6 text-textSecondary">{{ $member['summary'] }}</p>
                                @endif
                            </div>
                            <i data-lucide="chevron-down" class="mt-1 h-4 w-4 shrink-0 text-textMuted transition group-open:rotate-180"></i>
                        </summary>

                        <div class="mt-4 border-t border-border pt-4">
                            <x-workspace-intelligence.list :groups="$member['sections'] ?? []" empty="No team profile details captured yet." />
                            @if (($member['bio'] ?? '') !== '')
                                <div class="mt-4 rounded-lg border border-border bg-surface px-4 py-3">
                                    <h4 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Bio</h4>
                                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $member['bio'] }}</p>
                                </div>
                            @endif
                        </div>
                    </details>
                @empty
                    <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                        No team profiles yet. Add a team member or run enrichment from a public bio.
                    </div>
                @endforelse
            </div>
        </x-workspace-intelligence.card>
    </div>

    <div class="space-y-6">
        <x-workspace-intelligence.card
            eyebrow="Actions"
            title="Expand team context"
            description="Manage approved team members in Brand, or run a new enrichment from pasted bio text."
            icon="user-plus"
        >
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('app.brand.team-members') }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">
                    Add or edit team members
                </a>
            </div>

            <form method="POST" action="{{ route('app.workspace-intelligence.team-members.store') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="text-sm text-textSecondary" for="wi_team_member_id">Team member</label>
                    <select id="wi_team_member_id" name="team_member_id" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
                        <option value="">Select a team member</option>
                        @foreach ($teamMembers as $teamMember)
                            <option value="{{ $teamMember->id }}">{{ $teamMember->name }}{{ $teamMember->title || $teamMember->role ? ' · ' . ($teamMember->title ?: $teamMember->role) : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="hidden" name="source_type" value="pasted_profile_text">
                <div>
                    <label class="text-sm text-textSecondary" for="wi_team_pasted_profile_text">Pasted profile text</label>
                    <textarea id="wi_team_pasted_profile_text" name="pasted_profile_text" rows="6" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Paste public bio, About text, speaker intro, or profile summary.">{{ old('pasted_profile_text') }}</textarea>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="wi_team_linkedin_reference_url">LinkedIn reference URL</label>
                    <input id="wi_team_linkedin_reference_url" name="linkedin_reference_url" value="{{ old('linkedin_reference_url') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="https://www.linkedin.com/in/...">
                </div>
                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                    Run enrichment
                </button>
            </form>
        </x-workspace-intelligence.card>
    </div>
</div>
