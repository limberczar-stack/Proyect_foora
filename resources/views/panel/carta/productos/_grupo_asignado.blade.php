@php
    /**
     * Fila de un grupo asignado. Vacía en la plantilla: la llena resources/js/carta.js.
     *
     * @var int|string $grupoId
     * @var string $nombre
     * @var string $detalle
     */
@endphp

<li class="flex items-center justify-between gap-3 rounded-control bg-raised p-3 sm:px-3 sm:py-2" data-asignado>
    <input type="hidden" name="grupos[]" value="{{ $grupoId }}">
    <div class="min-w-0">
        <p class="text-sm font-medium text-ink" data-asignado-nombre>{{ $nombre }}</p>
        <p class="text-xs text-muted" data-asignado-detalle>{{ $detalle }}</p>
    </div>
    <div class="flex shrink-0 gap-1">
        <x-button variant="ghost" size="icon" class="size-10! lg:size-9!" data-asignado-subir aria-label="Subir grupo"><x-icon name="chevron-down" class="rotate-180" /></x-button>
        <x-button variant="ghost" size="icon" class="size-10! lg:size-9!" data-asignado-bajar aria-label="Bajar grupo"><x-icon name="chevron-down" /></x-button>
        <x-button variant="ghost" size="icon" class="size-10! text-danger-ink lg:size-9!" data-asignado-quitar aria-label="Quitar grupo"><x-icon name="x" /></x-button>
    </div>
</li>
