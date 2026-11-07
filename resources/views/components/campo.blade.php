@props([
    'label',
    'for',
    'error' => null,
    'hint' => null,
    'optional' => false,
])

@php
    /**
     * Campo de formulario: etiqueta, control (slot), ayuda y error.
     *
     * - error: clave del error con puntos (p. ej. "dias.1.abre"). El párrafo [data-error-for] lo llena
     *   el servidor (sin JS) o resources/js/configuracion.js (AJAX).
     * - hint: ayuda fija; el control la referencia con aria-describedby="{for}-ayuda".
     */
    $mensaje = $error ? $errors->first($error) : null;
    $idError = $error ? 'error-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $error) : null;
@endphp

<div {{ $attributes }}>
    <label for="{{ $for }}" class="mb-2 block text-sm font-medium text-ink">
        {{ $label }}
        @if ($optional)
            <span class="font-normal text-muted">(opcional)</span>
        @endif
    </label>
    {{ $slot }}
    @if ($hint)
        <p id="{{ $for }}-ayuda" class="mt-1.5 text-xs text-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $idError }}" data-error-for="{{ $error }}" class="mt-1.5 text-xs text-danger-ink" @if (! $mensaje) hidden @endif>{{ $mensaje }}</p>
    @endif
</div>
