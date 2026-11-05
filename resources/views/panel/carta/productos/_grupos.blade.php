@php
    /**
     * Grupos de opciones asignados al producto. El orden de las filas es el que ve el cliente.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\GrupoOpcion> $gruposDisponibles
     * @var \Illuminate\Support\Collection<int, \App\Models\GrupoOpcion> $gruposElegidos
     */
    $idsElegidos = $gruposElegidos->pluck('id')->all();
    $errorGrupos = $errors->first('grupos.*') ?: $errors->first('grupos');
    $detalle = fn (\App\Models\GrupoOpcion $grupo): string => collect([
        $grupo->descripcionRegla(),
        $grupo->obligatorio ? 'Obligatorio' : 'Opcional',
        $grupo->opciones_count.' '.($grupo->opciones_count === 1 ? 'opción' : 'opciones'),
        $grupo->activo ? null : 'Inactivo',
    ])->filter()->implode(' · ');
@endphp

<x-card title="Grupos de opciones" subtitle="Lo que el cliente elige o agrega: guarnición, adicionales, gaseosa…">
    @if ($gruposDisponibles->isEmpty())
        <div class="flex flex-col items-start gap-3 rounded-control border border-dashed border-control px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted">Todavía no tienes grupos de opciones.</p>
            <x-button href="{{ route('panel.carta.grupos.create') }}" size="sm" icon="plus">Crear grupo</x-button>
        </div>
    @else
        @if ($errorGrupos)
            <p class="mb-2 text-sm font-medium text-danger-ink" role="alert">{{ $errorGrupos }}</p>
        @endif

        <p class="rounded-control border border-dashed border-control px-4 py-3 text-sm text-muted" data-asignado-vacio @if ($gruposElegidos->isNotEmpty()) hidden @endif>Este producto no tiene grupos asignados.</p>

        <ol class="flex flex-col gap-2" data-asignado-lista>
            @foreach ($gruposElegidos as $grupo)
                @include('panel.carta.productos._grupo_asignado', ['grupoId' => $grupo->id, 'nombre' => $grupo->nombre, 'detalle' => $detalle($grupo)])
            @endforeach
        </ol>

        <template data-asignado-plantilla>
            @include('panel.carta.productos._grupo_asignado', ['grupoId' => '', 'nombre' => '', 'detalle' => ''])
        </template>

        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
            <label for="grupo-agregar" class="sr-only">Grupo para agregar</label>
            <select id="grupo-agregar" data-asignado-select class="h-11 min-w-0 flex-1 rounded-control border border-line bg-surface px-3 text-sm text-ink focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 lg:h-10">
                <option value="">Elige un grupo</option>
                @foreach ($gruposDisponibles as $grupo)
                    <option value="{{ $grupo->id }}" data-detalle="{{ $detalle($grupo) }}" @disabled(in_array($grupo->id, $idsElegidos, true))>{{ $grupo->nombre }}</option>
                @endforeach
            </select>
            <x-button size="sm" icon="plus" data-asignado-elegir>Agregar grupo</x-button>
        </div>
    @endif
</x-card>
