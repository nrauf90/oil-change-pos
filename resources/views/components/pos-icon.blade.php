@props([
    'expression',
    'size' => 'size-5',
])

@php
    $icons = [
        'heroicon-o-squares-2x2',
        'heroicon-o-beaker',
        'heroicon-o-cloud',
        'heroicon-o-cog-6-tooth',
        'heroicon-o-wrench-screwdriver',
        'heroicon-o-pencil-square',
        'heroicon-o-cube',
    ];
@endphp

<span data-pos-icon {{ $attributes->class(['grid place-items-center']) }}>
    @foreach ($icons as $icon)
        <x-filament::icon
            :icon="$icon"
            x-show="{{ $expression }} === '{{ $icon }}'"
            x-cloak
            aria-hidden="true"
            class="{{ $size }}"
        />
    @endforeach
</span>
