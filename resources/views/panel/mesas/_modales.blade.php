@use('App\Models\Mesa')

@php
    /**
     * Modales del admin en Mesas. Uno por formulario; resources/js/mesas.js los llena al editar.
     * Si un formulario vuelve con errores, su modal se reabre con lo escrito.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\Area> $areas
     * @var ?\App\Models\Area $areaActiva
     * @var ?string $modalConError
     */
    $reabrir = fn (string $modal): bool => $modalConError === $modal;
    // Los errores de un modal no pintan los campos de otro que se llamen igual (codigo, area_id…).
    $errorDe = fn (string $modal, string $clave): string => $reabrir($modal) ? $clave : '_otro_formulario';
    $areaPorDefecto = (int) ($areaActiva?->id ?? 0);
@endphp

{{-- Crear o editar una mesa (el mismo modal; jQuery llena los datos al editar) --}}
<x-modal id="modal-mesa" title="Nueva mesa" :data-reabrir="$reabrir('mesa') ? 'true' : 'false'">
    <form
        method="POST"
        action="{{ old('_accion', route('panel.mesas.store')) }}"
        id="form-mesa"
        class="flex flex-col gap-4"
        data-loading-form
        data-mesa-form
        data-url-crear="{{ route('panel.mesas.store') }}"
        novalidate
    >
        @csrf
        <input type="hidden" name="_method" value="{{ old('_method', 'POST') }}" data-mesa-metodo>
        <input type="hidden" name="_modal" value="mesa">
        <input type="hidden" name="_accion" value="{{ old('_accion', route('panel.mesas.store')) }}" data-mesa-accion>
        <input type="hidden" name="_titulo" value="{{ old('_titulo', 'Nueva mesa') }}" data-mesa-titulo>

        @if ($reabrir('mesa'))
            @include('panel.configuracion._resumen', ['claves' => ['mesas', 'estado']])
        @endif

        <div class="grid grid-cols-2 gap-4">
            <x-campo label="Nombre o número" for="mesa-codigo" :error="$errorDe('mesa', 'codigo')" class="col-span-2 min-[380px]:col-span-1">
                <x-input name="codigo" :error="$errorDe('mesa', 'codigo')" id="mesa-codigo" :value="$reabrir('mesa') ? old('codigo') : ''" maxlength="{{ Mesa::CODIGO_MAXIMO }}" required autocomplete="off" placeholder="Ej.: 12 o T3" data-campo="codigo" />
            </x-campo>
            <x-campo label="Capacidad" for="mesa-capacidad" :error="$errorDe('mesa', 'capacidad')" class="col-span-2 min-[380px]:col-span-1">
                <x-input name="capacidad" :error="$errorDe('mesa', 'capacidad')" id="mesa-capacidad" type="number" inputmode="numeric" min="{{ Mesa::CAPACIDAD_MINIMA }}" max="{{ Mesa::CAPACIDAD_MAXIMA }}" :value="$reabrir('mesa') ? old('capacidad') : 4" required data-campo="capacidad" />
            </x-campo>
        </div>

        <x-campo label="Área" for="mesa-area" :error="$errorDe('mesa', 'area_id')">
            <x-input as="select" name="area_id" :error="$errorDe('mesa', 'area_id')" id="mesa-area" data-campo="area_id">
                @foreach ($areas as $opcion)
                    <option value="{{ $opcion->id }}" @selected(($reabrir('mesa') ? (int) old('area_id') : $areaPorDefecto) === $opcion->id)>{{ $opcion->nombre }}{{ $opcion->activa ? '' : ' (desactivada)' }}</option>
                @endforeach
            </x-input>
        </x-campo>
    </form>

    {{-- Acciones de una mesa existente: solo al editar (mesas.js las muestra y les pone la URL) --}}
    <div class="mt-5 flex flex-col gap-3 border-t border-rule pt-4" data-mesa-acciones hidden>
        <p class="text-sm font-medium text-ink">Acciones</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="" data-mesa-accion-form="estado">
                @csrf
                @method('PATCH')
                <input type="hidden" name="estado" value="fuera_servicio" data-mesa-estado-valor>
                <x-button type="submit" size="sm" data-mesa-estado-texto>Poner fuera de servicio</x-button>
            </form>
            <form method="POST" action="" data-mesa-accion-form="mover">
                @csrf
                @method('PATCH')
                <input type="hidden" name="direccion" value="subir">
                <x-button type="submit" size="sm" variant="ghost" data-mesa-subir><x-icon name="chevron-left" /> Antes</x-button>
            </form>
            <form method="POST" action="" data-mesa-accion-form="mover">
                @csrf
                @method('PATCH')
                <input type="hidden" name="direccion" value="bajar">
                <x-button type="submit" size="sm" variant="ghost" data-mesa-bajar>Después <x-icon name="chevron-right" /></x-button>
            </form>
            <form method="POST" action="" data-mesa-accion-form="activa">
                @csrf
                @method('PATCH')
                <input type="hidden" name="activa" value="0" data-mesa-activa-valor>
                <x-button type="submit" size="sm" variant="danger" data-mesa-activa-texto>Desactivar</x-button>
            </form>
        </div>
        <p class="text-xs text-muted" data-mesa-acciones-ayuda>Una mesa desactivada deja de aparecer en el mapa y de contar para tu plan.</p>
    </div>

    <x-slot:footer>
        <x-button variant="secondary" data-modal-close>Cancelar</x-button>
        <x-button type="submit" form="form-mesa" variant="primary" data-submit data-loading-text="Guardando…">
            <span data-submit-spinner hidden><x-spinner :size="16" label="Guardando" /></span>
            <span data-submit-label>Guardar</span>
        </x-button>
    </x-slot:footer>
</x-modal>

{{-- Agregar varias mesas de una vez --}}
<x-modal id="modal-varias" title="Agregar varias mesas" :data-reabrir="$reabrir('varias') ? 'true' : 'false'">
    <form method="POST" action="{{ route('panel.mesas.varias') }}" id="form-varias" class="flex flex-col gap-4" data-loading-form data-varias-form novalidate>
        @csrf
        <input type="hidden" name="_modal" value="varias">

        @if ($reabrir('varias'))
            @include('panel.configuracion._resumen', ['claves' => ['mesas', 'codigo']])
        @endif

        <x-campo label="Área" for="varias-area" :error="$errorDe('varias', 'area_id')">
            <x-input as="select" name="area_id" :error="$errorDe('varias', 'area_id')" id="varias-area" data-campo="area_id">
                @foreach ($areas as $opcion)
                    <option value="{{ $opcion->id }}" @selected(($reabrir('varias') ? (int) old('area_id') : $areaPorDefecto) === $opcion->id)>{{ $opcion->nombre }}{{ $opcion->activa ? '' : ' (desactivada)' }}</option>
                @endforeach
            </x-input>
        </x-campo>

        <x-campo label="Prefijo" for="varias-prefijo" :error="$errorDe('varias', 'prefijo')" optional hint="Ej.: «T» crea T1, T2, T3…">
            <x-input name="prefijo" :error="$errorDe('varias', 'prefijo')" id="varias-prefijo" :value="$reabrir('varias') ? old('prefijo') : ''" maxlength="{{ Mesa::CODIGO_MAXIMO - 1 }}" autocomplete="off" placeholder="Sin prefijo: 1, 2, 3…" hint data-varias-prefijo />
        </x-campo>

        <div class="grid grid-cols-3 gap-3">
            <x-campo label="Desde" for="varias-desde" :error="$errorDe('varias', 'desde')">
                <x-input name="desde" :error="$errorDe('varias', 'desde')" id="varias-desde" type="number" inputmode="numeric" min="1" max="999" :value="$reabrir('varias') ? old('desde') : 1" required data-varias-desde />
            </x-campo>
            <x-campo label="Hasta" for="varias-hasta" :error="$errorDe('varias', 'hasta')">
                <x-input name="hasta" :error="$errorDe('varias', 'hasta')" id="varias-hasta" type="number" inputmode="numeric" min="1" max="999" :value="$reabrir('varias') ? old('hasta') : 4" required data-varias-hasta />
            </x-campo>
            <x-campo label="Capacidad" for="varias-capacidad" :error="$errorDe('varias', 'capacidad')">
                <x-input name="capacidad" :error="$errorDe('varias', 'capacidad')" id="varias-capacidad" type="number" inputmode="numeric" min="{{ Mesa::CAPACIDAD_MINIMA }}" max="{{ Mesa::CAPACIDAD_MAXIMA }}" :value="$reabrir('varias') ? old('capacidad') : 4" required />
            </x-campo>
        </div>

        <p class="rounded-control bg-raised px-3 py-2.5 text-sm text-ink" aria-live="polite" data-varias-vista hidden></p>
    </form>

    <x-slot:footer>
        <x-button variant="secondary" data-modal-close>Cancelar</x-button>
        <x-button type="submit" form="form-varias" variant="primary" data-submit data-loading-text="Creando…">
            <span data-submit-spinner hidden><x-spinner :size="16" label="Creando" /></span>
            <span data-submit-label>Crear mesas</span>
        </x-button>
    </x-slot:footer>
</x-modal>

{{-- Crear o renombrar un área --}}
<x-modal id="modal-area" title="Nueva área" size="sm" :data-reabrir="$reabrir('area') ? 'true' : 'false'">
    <form
        method="POST"
        action="{{ old('_accion', route('panel.mesas.areas.store')) }}"
        id="form-area"
        class="flex flex-col gap-4"
        data-loading-form
        data-area-form
        data-url-crear="{{ route('panel.mesas.areas.store') }}"
        novalidate
    >
        @csrf
        <input type="hidden" name="_method" value="{{ old('_method', 'POST') }}" data-area-metodo>
        <input type="hidden" name="_modal" value="area">
        <input type="hidden" name="_accion" value="{{ old('_accion', route('panel.mesas.areas.store')) }}" data-area-accion>
        <input type="hidden" name="_titulo" value="{{ old('_titulo', 'Nueva área') }}" data-area-titulo>

        <x-campo label="Nombre" for="area-nombre" :error="$errorDe('area', 'nombre')">
            <x-input name="nombre" :error="$errorDe('area', 'nombre')" id="area-nombre" :value="$reabrir('area') ? old('nombre') : ''" maxlength="60" required autocomplete="off" placeholder="Ej.: Terraza" data-campo="nombre" />
        </x-campo>
    </form>

    <x-slot:footer>
        <x-button variant="secondary" data-modal-close>Cancelar</x-button>
        <x-button type="submit" form="form-area" variant="primary" data-submit data-loading-text="Guardando…">
            <span data-submit-spinner hidden><x-spinner :size="16" label="Guardando" /></span>
            <span data-submit-label>Guardar</span>
        </x-button>
    </x-slot:footer>
</x-modal>

{{-- Confirmar eliminación de un área (solo si no tiene mesas) --}}
<x-modal id="modal-eliminar-area" title="Eliminar área" size="sm">
    <p class="text-sm text-ink" data-eliminar-area-texto>¿Eliminar esta área?</p>
    <x-slot:footer>
        <x-button variant="secondary" data-modal-close>Cancelar</x-button>
        <form method="POST" action="" data-eliminar-area-form data-loading-form>
            @csrf
            @method('DELETE')
            <x-button type="submit" variant="danger" class="w-full sm:w-auto" data-submit data-eliminar-area-boton data-loading-text="Eliminando…">
                <span data-submit-spinner hidden><x-spinner :size="16" label="Eliminando" /></span>
                <span data-submit-label>Eliminar</span>
            </x-button>
        </form>
    </x-slot:footer>
</x-modal>
