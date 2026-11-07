@php
    /**
     * Pie de un formulario de configuración: estado del guardado (con Reintentar si falla) y el botón.
     *
     * @var string|null $textoBoton
     */
    $textoBoton ??= 'Guardar cambios';
@endphp

<div class="sticky bottom-0 z-10 -mx-4 mt-6 flex flex-col-reverse gap-3 border-t border-line bg-ground px-4 py-3 sm:mx-0 sm:flex-row sm:items-center sm:justify-end sm:px-0">
    <div class="guardado-estado flex min-w-0 items-center gap-2 text-sm" role="status" aria-live="polite" data-form-estado hidden></div>
    <button
        type="submit"
        class="press inline-flex h-11 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-control border border-transparent bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800 disabled:bg-brand-200 disabled:text-brand-900 lg:h-10"
        data-guardar
    >
        <span class="size-4 animate-spin-fast rounded-full border-2 border-brand-300 border-t-white" data-guardar-spinner aria-hidden="true" hidden></span>
        <span data-guardar-texto>{{ $textoBoton }}</span>
    </button>
</div>
