@php
    /**
     * Pie de un formulario del superadmin: estado del envío (con Reintentar si falla) y botones.
     *
     * @var string $texto
     * @var string|null $ocupado  texto mientras se envía
     * @var bool|null $peligro  la acción es delicada: botón rojo suave
     * @var bool|null $cancelable  está en un modal: agrega Cancelar
     */
    $ocupado ??= 'Guardando…';
    $peligro ??= false;
    $cancelable ??= false;
@endphp

<div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
    <div class="guardado-estado flex min-w-0 items-center gap-2 text-sm sm:mr-auto" role="status" aria-live="polite" data-form-estado hidden></div>
    @if ($cancelable)
        <x-button variant="secondary" data-modal-close>Cancelar</x-button>
    @endif
    <button
        type="submit"
        @class([
            'press inline-flex h-11 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-control border border-transparent px-4 text-sm font-medium lg:h-10',
            'bg-danger-bg text-danger-ink hover:bg-[#fecdd3] disabled:opacity-60' => $peligro,
            'bg-brand-700 text-white hover:bg-brand-800 disabled:bg-brand-200 disabled:text-brand-900' => ! $peligro,
        ])
        data-guardar
        data-texto-ocupado="{{ $ocupado }}"
    >
        <span class="size-4 animate-spin-fast rounded-full border-2 border-current border-r-transparent" data-guardar-spinner aria-hidden="true" hidden></span>
        <span data-guardar-texto>{{ $texto }}</span>
    </button>
</div>
