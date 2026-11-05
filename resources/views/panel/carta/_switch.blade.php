@php
    /**
     * Interruptor de disponibilidad (AJAX, ver initDisponibilidad en resources/js/carta.js).
     *
     * @var bool $disponible
     * @var string $url
     * @var string $etiqueta
     */
@endphp

<button
    type="button"
    role="switch"
    aria-checked="{{ $disponible ? 'true' : 'false' }}"
    aria-label="Disponible: {{ $etiqueta }}"
    data-disponibilidad
    data-url="{{ $url }}"
    class="group inline-flex min-h-11 items-center gap-2.5 rounded-control px-1 lg:min-h-9"
>
    <span class="relative inline-flex">
        <span data-switch-track @class(['h-6 w-11 rounded-full transition-colors duration-200', 'bg-success-fill' => $disponible, 'bg-control' => ! $disponible])></span>
        <span data-switch-thumb @class(['absolute left-0.5 top-0.5 flex size-5 items-center justify-center rounded-full bg-white shadow-sm transition-transform duration-200 ease-out', 'translate-x-5' => $disponible])>
            <x-spinner :size="12" label="Guardando" class="hidden text-brand-700" data-switch-spinner />
        </span>
    </span>
    <span data-switch-label class="w-16 text-left text-[0.8125rem] text-muted">{{ $disponible ? 'Disponible' : 'Agotado' }}</span>
</button>
