@php
    $properties = $schema['properties'] ?? [];
    $required = $schema['required'] ?? [];
    $compact = $compact ?? false;
@endphp

@if (!empty($properties))
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-border text-left text-xs text-textSecondary">
                    <th class="pb-2 pr-4">Field</th>
                    <th class="pb-2 pr-4">Type</th>
                    @unless($compact)
                        <th class="pb-2 pr-4">Format</th>
                    @endunless
                    <th class="pb-2 pr-4">Required</th>
                    @unless($compact)
                        <th class="pb-2">Description</th>
                    @endunless
                </tr>
            </thead>
            <tbody>
                @foreach ($properties as $name => $prop)
                    @php
                        $type = $prop['type'] ?? 'any';
                        $format = $prop['format'] ?? null;
                        $nullable = $prop['nullable'] ?? false;
                        $isRequired = in_array($name, $required);
                        $enum = $prop['enum'] ?? null;
                        $items = $prop['items'] ?? null;
                        $nested = $prop['properties'] ?? null;
                    @endphp
                    <tr class="border-b border-border/50">
                        <td class="py-2 pr-4">
                            <code class="text-xs text-textPrimary">{{ $name }}</code>
                        </td>
                        <td class="py-2 pr-4 text-xs">
                            <span class="text-textSecondary">{{ $type }}</span>
                            @if ($type === 'array' && $items)
                                <span class="text-textTertiary">[{{ $items['type'] ?? 'any' }}]</span>
                            @endif
                            @if ($nullable)
                                <span class="text-textTertiary">| null</span>
                            @endif
                        </td>
                        @unless($compact)
                            <td class="py-2 pr-4 text-xs text-textSecondary">
                                {{ $format ?? '-' }}
                            </td>
                        @endunless
                        <td class="py-2 pr-4">
                            @if ($isRequired)
                                <span class="text-xs font-medium text-rose-600">required</span>
                            @else
                                <span class="text-xs text-textSecondary">optional</span>
                            @endif
                        </td>
                        @unless($compact)
                            <td class="py-2 text-xs text-textSecondary">
                                @if ($enum)
                                    <span>enum: </span>
                                    <code class="rounded bg-surfaceSubtle px-1 py-0.5">{{ implode(', ', array_slice($enum, 0, 5)) }}</code>
                                    @if (count($enum) > 5)
                                        <span class="text-textTertiary">+{{ count($enum) - 5 }} more</span>
                                    @endif
                                @elseif (isset($prop['maxLength']))
                                    max: {{ $prop['maxLength'] }}
                                @elseif (isset($prop['minimum']) || isset($prop['maximum']))
                                    @if (isset($prop['minimum']))
                                        min: {{ $prop['minimum'] }}
                                    @endif
                                    @if (isset($prop['maximum']))
                                        max: {{ $prop['maximum'] }}
                                    @endif
                                @endif
                            </td>
                        @endunless
                    </tr>

                    {{-- Nested object properties --}}
                    @if ($nested && !$compact)
                        @foreach ($nested as $nestedName => $nestedProp)
                            @php
                                $nestedType = $nestedProp['type'] ?? 'any';
                                $nestedFormat = $nestedProp['format'] ?? null;
                                $nestedNullable = $nestedProp['nullable'] ?? false;
                            @endphp
                            <tr class="border-b border-border/30 bg-surfaceSubtle/50">
                                <td class="py-1.5 pl-4 pr-4">
                                    <code class="text-xs text-textSecondary">{{ $name }}.{{ $nestedName }}</code>
                                </td>
                                <td class="py-1.5 pr-4 text-xs text-textSecondary">
                                    {{ $nestedType }}
                                    @if ($nestedNullable)
                                        <span class="text-textTertiary">| null</span>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-4 text-xs text-textSecondary">
                                    {{ $nestedFormat ?? '-' }}
                                </td>
                                <td class="py-1.5 pr-4 text-xs text-textSecondary">-</td>
                                <td class="py-1.5 text-xs text-textSecondary">-</td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="text-xs text-textSecondary">No schema properties defined.</p>
@endif
