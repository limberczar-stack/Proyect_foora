@props([
    'variant' => 'secondary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'loading' => false,
    'disabled' => false,
    'loadingText' => null,
])

@php
    /**
     * Botón de FOORA.
     *
     * Variantes: primary (morado, una por pantalla), secondary (borde), ghost (texto),
     * success (aceptar), danger (rechazar, rojo suave).
     * Estados: loading (spinner + aria-busy, no clicable) y disabled (legible, no clicable).
     */
    $isInactive = $loading || $disabled;

    $variants = [
        'primary' => 'bg-brand-700 text-white hover:bg-brand-800 border border-transparent',
        'secondary' => 'bg-surface text-ink border border-control hover:bg-raised',
        'ghost' => 'bg-transparent text-ink border border-transparent hover:bg-raised',
        'success' => 'bg-success-fill text-white border border-transparent hover:bg-success-ink',
        'danger' => 'bg-danger-bg text-danger-ink border border-transparent hover:bg-[#fecdd3]',
    ];

    $inactive = [
        'primary' => 'bg-brand-200 text-brand-900 border border-transparent',
        'secondary' => 'bg-raised text-faint border border-line',
        'ghost' => 'bg-transparent text-faint border border-transparent',
        'success' => 'bg-success-bg text-success-ink border border-transparent',
        'danger' => 'bg-danger-bg text-danger-ink border border-transparent',
    ];

    $sizes = [
        'sm' => 'h-10 px-3 text-[0.8125rem] gap-1.5 lg:h-8',
        'md' => 'h-11 px-4 text-sm gap-2 lg:h-10',
        'icon' => 'size-11 lg:size-10 justify-center',
    ];

    $classes = implode(' ', [
        'press inline-flex items-center justify-center whitespace-nowrap rounded-control font-medium select-none',
        $sizes[$size] ?? $sizes['md'],
        $isInactive ? ($inactive[$variant] ?? $inactive['secondary']).' cursor-not-allowed' : ($variants[$variant] ?? $variants['secondary']),
        $loading ? 'cursor-progress' : '',
    ]);

    $iconSize = $size === 'sm' ? 14 : 16;
@endphp

@if ($href && ! $isInactive)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-icon :name="$icon" :size="$iconSize" />
        @endif
        {{ $slot }}
    </a>
@elseif ($href)
    <a role="link" aria-disabled="true" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)
            <x-spinner :size="$iconSize" :label="$loadingText ?? 'Cargando'" />
        @elseif ($icon)
            <x-icon :name="$icon" :size="$iconSize" />
        @endif
        {{ $loading && $loadingText ? $loadingText : $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($disabled) disabled @endif
        @if ($loading) aria-disabled="true" aria-busy="true" @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading)
            <x-spinner :size="$iconSize" :label="$loadingText ?? 'Cargando'" />
        @elseif ($icon)
            <x-icon :name="$icon" :size="$iconSize" />
        @endif
        @if ($size !== 'icon')
            {{ $loading && $loadingText ? $loadingText : $slot }}
        @else
            {{ $slot }}
        @endif
    </button>
@endif
