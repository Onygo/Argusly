@php
    $rt = $rt ?? function (string $value, array $replace = []): string {
        $key = 'app.runtime.'.$value;
        $translated = __($key, $replace);

        return $translated === $key ? strtr($value, collect($replace)->mapWithKeys(fn ($replacement, $placeholder) => [':'.$placeholder => $replacement])->all()) : $translated;
    };
@endphp

<div class="mb-6 flex flex-wrap gap-2 border-b border-border">
    <a href="{{ route('app.brand.company-profile') }}" class="px-4 py-2 text-sm font-medium border-b-2 {{ request()->routeIs('app.brand.company-profile') ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}">{{ $rt('Company Profile') }}</a>
    <a href="{{ route('app.brand.company-intelligence') }}" class="px-4 py-2 text-sm font-medium border-b-2 {{ request()->routeIs('app.brand.company-intelligence*') ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}">{{ $rt('Company Intelligence') }}</a>
    <a href="{{ route('app.brand.voices') }}" class="px-4 py-2 text-sm font-medium border-b-2 {{ request()->routeIs('app.brand.voices') ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}">{{ $rt('Brand Voices') }}</a>
    <a href="{{ route('app.brand.writer-profiles') }}" class="px-4 py-2 text-sm font-medium border-b-2 {{ request()->routeIs('app.brand.writer-profiles*') ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}">{{ $rt('Writer Profiles') }}</a>
    <a href="{{ route('app.brand.personas') }}" class="px-4 py-2 text-sm font-medium border-b-2 {{ request()->routeIs('app.brand.personas') ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}">{{ $rt('Buyer Personas') }}</a>
    <a href="{{ route('app.brand.team-members') }}" class="px-4 py-2 text-sm font-medium border-b-2 {{ request()->routeIs('app.brand.team-members*') ? 'border-primary text-textPrimary' : 'border-transparent text-textSecondary hover:text-textPrimary' }}">{{ $rt('Team Personas') }}</a>
</div>
