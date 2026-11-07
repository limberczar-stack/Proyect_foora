@props([
    'name',
    'id',
    'type' => 'text',
    'value' => null,
    'error' => null,
    'hint' => false,
    'as' => 'input',
])

@php
    /**
     * Control de texto o lista del panel, con el borde rojo y aria-invalid cuando su campo tiene error.
     * error: clave con puntos; por defecto se deriva de name (dias[1][abre] → dias.1.abre).
     * hint: true si el x-campo que lo envuelve tiene ayuda ({id}-ayuda).
     * as="select": el slot lleva las opciones.
     */
    $clave = $error ?? trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $tieneError = $errors->has($clave);
    $describe = array_filter([
        $hint ? $id.'-ayuda' : null,
        $tieneError ? 'error-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $clave) : null,
    ]);
    $clases = 'h-11 w-full rounded-control border border-line bg-surface px-3 text-sm text-ink placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 disabled:cursor-not-allowed disabled:bg-raised disabled:text-muted aria-[invalid=true]:border-danger-dot aria-[invalid=true]:focus-visible:outline-danger-dot/30 lg:h-10';
@endphp

@if ($as === 'select')
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @if ($tieneError) aria-invalid="true" @endif
        @if ($describe) aria-describedby="{{ implode(' ', $describe) }}" @endif
        {{ $attributes->merge(['class' => $clases]) }}
    >{{ $slot }}</select>
@elseif ($as === 'textarea')
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        @if ($tieneError) aria-invalid="true" @endif
        @if ($describe) aria-describedby="{{ implode(' ', $describe) }}" @endif
        {{ $attributes->merge(['class' => str_replace(['h-11 ', ' lg:h-10'], ['min-h-24 py-2.5 ', ''], $clases)]) }}
    >{{ $value }}</textarea>
@else
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ $value }}"
        @if ($tieneError) aria-invalid="true" @endif
        @if ($describe) aria-describedby="{{ implode(' ', $describe) }}" @endif
        {{ $attributes->merge(['class' => $clases]) }}
    >
@endif
