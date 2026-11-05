@extends('layouts.panel')

@section('title', 'Grupos de opciones')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\GrupoOpcion> $grupos */
    $navActivo = 'carta';
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $puedeCrear = auth()->user()->can('create', \App\Models\GrupoOpcion::class);
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Carta</h1>
            <p class="mt-1 text-sm text-muted">Guarniciones, adicionales y otras elecciones que se reutilizan entre productos.</p>
        </div>
        @if ($puedeCrear)
            <x-button href="{{ route('panel.carta.grupos.create') }}" variant="primary" icon="plus" class="sm:self-end">Nuevo grupo</x-button>
        @endif
    </div>

    @include('panel.carta._nav')

    @if ($totalGrupos === 0)
        <x-card class="mt-5" empty="Todavía no tienes grupos de opciones. Crea el primero, por ejemplo «Elige tu guarnición» o «Adicionales».">
            @if ($puedeCrear)
                <x-slot:empty-action>
                    <x-button href="{{ route('panel.carta.grupos.create') }}" size="sm" icon="plus">Crear grupo</x-button>
                </x-slot:empty-action>
            @endif
        </x-card>
    @else
        <form method="GET" action="{{ route('panel.carta.grupos.index') }}" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center" role="search" data-filtros-carta>
            <label for="filtro-q" class="sr-only">Buscar por nombre</label>
            <div class="relative flex-1">
                <input
                    id="filtro-q"
                    name="q"
                    type="search"
                    value="{{ $busqueda }}"
                    placeholder="Buscar por nombre"
                    autocomplete="off"
                    class="h-11 w-full rounded-control border border-control bg-surface px-3 text-sm placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 lg:h-10"
                >
                <x-spinner :size="16" label="Buscando" class="absolute right-3 top-1/2 hidden -translate-y-1/2 text-brand-700" data-filtros-spinner />
            </div>
            <noscript><x-button type="submit">Filtrar</x-button></noscript>
            @if ($busqueda !== '')
                <x-button href="{{ route('panel.carta.grupos.index') }}" variant="ghost" size="sm">Limpiar filtros</x-button>
            @endif
        </form>

        <x-card class="mt-4" :padded="false" data-listado-carta>
            <p class="sr-only" aria-live="polite" data-disponibilidad-live></p>
            <div class="hidden border-b border-rule px-4 py-3 text-sm text-danger-ink sm:px-5" role="alert" data-disponibilidad-error></div>

            @if ($grupos->isEmpty())
                <div class="px-4 py-6 sm:px-5">
                    <p class="rounded-control border border-dashed border-control px-4 py-4 text-sm text-muted">
                        Ningún grupo coincide con «{{ $busqueda }}».
                        <a href="{{ route('panel.carta.grupos.index') }}" class="font-medium text-brand-700 underline-offset-2 hover:underline">Ver todos los grupos</a>
                    </p>
                </div>
            @else
                <ul class="divide-y divide-rule" aria-label="Grupos de opciones">
                    @foreach ($grupos as $grupo)
                        <li class="px-4 py-4 sm:px-5" data-grupo="{{ $grupo->id }}">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                        <span class="font-medium text-ink">{{ $grupo->nombre }}</span>
                                        @if ($grupo->obligatorio)
                                            <x-badge tone="brand" :dot="false">Obligatorio</x-badge>
                                        @endif
                                        @unless ($grupo->activo)
                                            <x-badge tone="neutral" :dot="false">Inactivo</x-badge>
                                        @endunless
                                    </p>
                                    <p class="mt-0.5 text-[0.8125rem] text-muted">
                                        {{ $grupo->descripcionRegla() }} ·
                                        <span class="num">{{ $grupo->productos_count }}</span> {{ $grupo->productos_count === 1 ? 'producto' : 'productos' }}
                                    </p>
                                </div>
                                @can('update', $grupo)
                                    <div class="flex shrink-0 gap-1">
                                        <x-button href="{{ route('panel.carta.grupos.edit', $grupo) }}" size="sm" variant="ghost">Editar</x-button>
                                        <x-button
                                            size="sm"
                                            variant="ghost"
                                            class="text-danger-ink"
                                            data-modal-open="modal-eliminar-grupo"
                                            data-eliminar-url="{{ route('panel.carta.grupos.destroy', $grupo) }}"
                                            data-eliminar-nombre="{{ $grupo->nombre }}"
                                        >Eliminar</x-button>
                                    </div>
                                @endcan
                            </div>

                            <ul class="mt-2 grid grid-cols-1 gap-x-8 gap-y-1 lg:grid-cols-2" aria-label="Opciones de {{ $grupo->nombre }}">
                                @foreach ($grupo->opciones as $opcion)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="min-w-0 text-sm text-ink">
                                            {{ $opcion->nombre }}
                                            @if ((float) $opcion->precio_extra > 0)
                                                <span class="num whitespace-nowrap text-muted">+{{ $soles($opcion->precio_extra) }}</span>
                                            @endif
                                        </span>
                                        @can('cambiarDisponibilidad', $grupo)
                                            @include('panel.carta._switch', [
                                                'disponible' => $opcion->disponible,
                                                'url' => route('panel.carta.grupos.opciones.disponibilidad', [$grupo, $opcion]),
                                                'etiqueta' => $grupo->nombre.', '.$opcion->nombre,
                                            ])
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-col items-start gap-3 border-t border-rule px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <p class="num text-[0.8125rem] text-muted">{{ $grupos->firstItem() }}–{{ $grupos->lastItem() }} de {{ $grupos->total() }}</p>
                    @if ($grupos->hasPages())
                        <div class="flex gap-2">
                            <x-button :href="$grupos->previousPageUrl()" size="sm" :disabled="$grupos->onFirstPage()">Anterior</x-button>
                            <x-button :href="$grupos->nextPageUrl()" size="sm" :disabled="! $grupos->hasMorePages()">Siguiente</x-button>
                        </div>
                    @endif
                </div>
            @endif
        </x-card>
    @endif
@endsection

@push('modals')
    <x-modal id="modal-eliminar-grupo" title="Eliminar grupo" size="sm">
        <p class="text-sm text-ink">¿Eliminar <strong data-eliminar-texto>este grupo</strong> y sus opciones? Los pedidos anteriores conservan lo que se eligió.</p>
        <x-slot:footer>
            <x-button variant="secondary" data-modal-close>Cancelar</x-button>
            <form method="POST" action="" data-eliminar-form data-loading-form>
                @csrf
                @method('DELETE')
                <x-button type="submit" variant="danger" class="w-full sm:w-auto" data-submit data-loading-text="Eliminando…">
                    <span data-submit-spinner hidden><x-spinner :size="16" label="Eliminando" /></span>
                    <span data-submit-label>Eliminar</span>
                </x-button>
            </form>
        </x-slot:footer>
    </x-modal>
@endpush
