@php
    /**
     * Fila del editor de opciones. $indice es numérico o "__INDICE__" en la plantilla.
     *
     * @var array{id: ?int, nombre: string, precio_extra: string, disponible: bool} $opcion
     */
    $base = "opciones.{$indice}";
    $errorNombre = is_numeric($indice) ? $errors->first("{$base}.nombre") : null;
    $errorPrecio = is_numeric($indice) ? $errors->first("{$base}.precio_extra") : null;
    $disponible = filter_var($opcion['disponible'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $campo = 'h-11 w-full rounded-control border bg-surface px-3 text-sm text-ink placeholder:text-faint focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 lg:h-10';
@endphp

<li class="grid grid-cols-[1fr_auto] items-start gap-x-3 gap-y-2 rounded-control bg-raised p-3 sm:grid-cols-[1fr_8rem_7rem_8.5rem] sm:items-center sm:bg-transparent sm:p-1" data-opcion>
    <input type="hidden" name="opciones[{{ $indice }}][id]" value="{{ $opcion['id'] ?? '' }}">

    <div class="col-span-2 sm:col-span-1">
        <label class="sr-only" for="opcion-{{ $indice }}-nombre">Nombre de la opción</label>
        <input id="opcion-{{ $indice }}-nombre" type="text" name="opciones[{{ $indice }}][nombre]" value="{{ $opcion['nombre'] ?? '' }}" maxlength="80" placeholder="Ej.: Papas fritas"
            @if ($errorNombre) aria-invalid="true" @endif
            class="{{ $campo }} {{ $errorNombre ? 'border-danger-dot' : 'border-line focus:border-brand-600 focus-visible:outline-brand-600/30' }}">
        @if ($errorNombre)<p class="mt-1 text-xs text-danger-ink">{{ $errorNombre }}</p>@endif
    </div>

    <div>
        <label class="sr-only" for="opcion-{{ $indice }}-precio">Precio extra de la opción</label>
        <div class="relative">
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted">S/</span>
            <input id="opcion-{{ $indice }}-precio" type="text" inputmode="decimal" name="opciones[{{ $indice }}][precio_extra]" value="{{ $opcion['precio_extra'] ?? '' }}" placeholder="0.00"
                @if ($errorPrecio) aria-invalid="true" @endif
                class="num {{ $campo }} pl-9 text-right {{ $errorPrecio ? 'border-danger-dot' : 'border-line focus:border-brand-600 focus-visible:outline-brand-600/30' }}">
        </div>
        @if ($errorPrecio)<p class="mt-1 text-xs text-danger-ink">{{ $errorPrecio }}</p>@endif
    </div>

    <label class="flex min-h-11 cursor-pointer items-center gap-2 lg:min-h-10">
        <span class="relative inline-flex shrink-0">
            <input type="hidden" name="opciones[{{ $indice }}][disponible]" value="0">
            <input type="checkbox" role="switch" name="opciones[{{ $indice }}][disponible]" value="1" class="peer sr-only" @checked($disponible)>
            <span class="h-6 w-11 rounded-full bg-control transition-colors duration-200 peer-checked:bg-success-fill peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600"></span>
            <span class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-out peer-checked:translate-x-5"></span>
        </span>
        <span class="text-xs text-muted sm:sr-only">Disponible</span>
    </label>

    <div class="col-span-2 flex justify-end gap-1 sm:col-span-1">
        <x-button variant="ghost" size="icon" class="size-10! lg:size-9!" data-opcion-subir aria-label="Subir opción"><x-icon name="chevron-down" class="rotate-180" /></x-button>
        <x-button variant="ghost" size="icon" class="size-10! lg:size-9!" data-opcion-bajar aria-label="Bajar opción"><x-icon name="chevron-down" /></x-button>
        <x-button variant="ghost" size="icon" class="size-10! text-danger-ink lg:size-9!" data-opcion-quitar aria-label="Quitar opción"><x-icon name="x" /></x-button>
    </div>
</li>
