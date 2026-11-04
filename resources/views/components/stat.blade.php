@props([
    'label',
    'value' => null,
    'prefix' => null,
    'unit' => null,
    'caption' => null,
    'captionTone' => 'neutral',
    'icon' => null,
    'href' => null,
    'loading' => false,
    'error' => null,
    'empty' => false,
    'disabled' => null,
])

@php
    /**
     * Tarjeta de estadística: etiqueta arriba, cifra en tipografía tabular, una línea de contexto.
     * Sin íconos en recuadros de color ni sparklines: la cifra es la protagonista.
     *
     * - value 0 se imprime como 0 (un cero real no es un estado vacío).
     * - empty: no hay dato aplicable → guion gris.
     * - captionTone: positive | negative | neutral. El color dice bueno o malo, no sube o baja.
     */
    $captionTones = [
        'positive' => 'text-success-ink',
        'negative' => 'text-danger-ink font-medium',
        'neutral' => 'text-muted',
    ];

    $isLink = $href && ! $loading && ! $error && ! $disabled;
    $tag = $isLink ? 'a' : 'div';
    $base = 'group flex min-h-[7.25rem] flex-col justify-between gap-3 rounded-card border border-line bg-surface p-4 sm:p-5';
    $interactive = $isLink ? ' press hover:border-control focus-visible:border-brand-600' : '';
@endphp

<{{ $tag }}
    @if ($isLink) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => $base.$interactive]) }}
    @if ($loading) aria-busy="true" @endif
    @if ($disabled) aria-disabled="true" @endif
>
    <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-2 text-[0.8125rem] text-muted">
            @if ($icon)
                <x-icon :name="$icon" class="text-faint" />
            @endif
            {{ $label }}
        </span>
        @if ($isLink)
            <x-icon name="chevron-right" size="14" class="text-faint transition-transform duration-150 ease-out group-hover:translate-x-0.5" />
        @endif
    </div>

    @if ($loading)
        <div aria-hidden="true" class="flex flex-col gap-2">
            <span class="skeleton h-7 w-16"></span>
            <span class="skeleton h-3 w-28"></span>
        </div>
        <span class="sr-only" role="status">Cargando {{ mb_strtolower($label) }}…</span>
    @elseif ($error)
        <div class="flex flex-col gap-1">
            <span class="text-kpi font-medium text-faint" aria-hidden="true">—</span>
            <span class="flex items-center gap-1.5 text-xs text-danger-ink" role="alert">
                <x-icon name="alert" size="12" />
                {{ $error }}
            </span>
        </div>
    @elseif ($disabled)
        <div class="flex flex-col gap-1">
            <span class="text-kpi font-medium text-faint" aria-hidden="true">—</span>
            <span class="flex items-center gap-1.5 text-xs text-muted">
                <x-icon name="lock" size="12" />
                {{ $disabled }}
            </span>
        </div>
    @elseif ($empty)
        <div class="flex flex-col gap-1">
            <span class="text-kpi font-medium text-faint" aria-label="Sin datos">—</span>
            <span class="text-xs text-muted">{{ $caption ?? 'Sin datos todavía' }}</span>
        </div>
    @else
        <div class="flex flex-col gap-1">
            <span class="num text-kpi font-semibold tracking-tight text-ink">
                @if ($prefix)<span class="mr-1 text-base font-normal text-muted">{{ $prefix }}</span>@endif{{ $value }}@if ($unit)<span class="ml-0.5 text-base font-normal text-muted">{{ $unit }}</span>@endif
            </span>
            @if ($caption)
                <span class="num text-xs {{ $captionTones[$captionTone] ?? $captionTones['neutral'] }}">{{ $caption }}</span>
            @endif
        </div>
    @endif
</{{ $tag }}>
