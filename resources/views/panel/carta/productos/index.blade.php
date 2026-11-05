@extends('layouts.panel')

@section('title', 'Carta')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\Producto> $productos */
    $navActivo = 'carta';
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $hayFiltros = $categoriaId || $busqueda !== '' || $soloIncompletos;
    $puedeCrear = auth()->user()->can('create', \App\Models\Producto::class);
    $columnas = [
        ['label' => 'Producto'],
        ['label' => 'Precio', 'align' => 'right', 'class' => 'w-32'],
        ['label' => 'Estación', 'class' => 'w-28'],
        ['label' => 'Disponible', 'class' => 'w-32'],
        ['label' => 'Acciones', 'align' => 'right', 'class' => 'w-28'],
    ];
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Carta</h1>
            <p class="mt-1 text-sm text-muted">
                <span class="num">{{ $totalProductos }}</span> {{ $totalProductos === 1 ? 'producto' : 'productos' }} en tu carta
            </p>
        </div>
        @if ($puedeCrear)
            <x-button href="{{ route('panel.carta.productos.create') }}" variant="primary" icon="plus" class="sm:self-end">Nuevo producto</x-button>
        @endif
    </div>

    @include('panel.carta._nav')

    @if ($totalProductos === 0)
        {{-- Carta vacía --}}
        <x-card class="mt-5">
            <div class="flex flex-col items-start gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-section font-semibold text-ink">Tu carta está vacía</h2>
                    <p class="mt-1 text-sm text-muted">
                        @if ($categorias->isEmpty())
                            Empieza creando las categorías (por ejemplo Pollos a la brasa o Bebidas) y luego agrega tus productos.
                        @else
                            Ya tienes categorías. Agrega tu primer producto para que tus clientes lo vean.
                        @endif
                    </p>
                </div>
                @if ($puedeCrear)
                    @if ($categorias->isEmpty())
                        <x-button href="{{ route('panel.carta.categorias.index') }}" variant="primary" icon="plus">Crear categorías</x-button>
                    @else
                        <x-button href="{{ route('panel.carta.productos.create') }}" variant="primary" icon="plus">Crea tu primer producto</x-button>
                    @endif
                @endif
            </div>
        </x-card>
    @else
        {{-- Carta incompleta --}}
        @if ($totalIncompletos > 0)
            <div class="mt-5 flex flex-col gap-3 rounded-card bg-warning-bg px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <p class="flex items-start gap-2.5 text-sm text-warning-ink">
                    <x-icon name="alert" class="mt-0.5" />
                    <span>
                        <strong class="num font-semibold">Carta incompleta: {{ $totalIncompletos }} {{ $totalIncompletos === 1 ? 'producto' : 'productos' }}</strong>
                        sin descripción o sin foto. Los productos con todo completo venden mejor en la app.
                    </span>
                </p>
                @if ($soloIncompletos)
                    <x-button href="{{ route('panel.carta.productos.index', array_filter(['categoria' => $categoriaId, 'q' => $busqueda ?: null])) }}" size="sm" variant="secondary">Ver todos</x-button>
                @else
                    <x-button href="{{ route('panel.carta.productos.index', array_filter(['categoria' => $categoriaId, 'q' => $busqueda ?: null, 'incompletos' => 1])) }}" size="sm" variant="secondary">Ver incompletos</x-button>
                @endif
            </div>
        @endif

        {{-- Filtros --}}
        <form method="GET" action="{{ route('panel.carta.productos.index') }}" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center" role="search" data-filtros-carta>
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
            <label for="filtro-categoria" class="sr-only">Categoría</label>
            <select
                id="filtro-categoria"
                name="categoria"
                class="h-11 rounded-control border border-control bg-surface px-3 text-sm focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 sm:w-64 lg:h-10"
            >
                <option value="">Todas las categorías</option>
                @foreach ($categorias as $categoria)
                    <option value="{{ $categoria->id }}" @selected($categoriaId === $categoria->id)>{{ $categoria->nombre }}</option>
                    @foreach ($categoria->hijas as $hija)
                        <option value="{{ $hija->id }}" @selected($categoriaId === $hija->id)>&nbsp;&nbsp;&nbsp;{{ $categoria->nombre }} › {{ $hija->nombre }}</option>
                    @endforeach
                @endforeach
            </select>
            @if ($soloIncompletos)
                <input type="hidden" name="incompletos" value="1">
            @endif
            <noscript><x-button type="submit">Filtrar</x-button></noscript>
            @if ($hayFiltros)
                <x-button href="{{ route('panel.carta.productos.index') }}" variant="ghost" size="sm">Limpiar filtros</x-button>
            @endif
        </form>

        {{-- Listado --}}
        <x-card class="mt-4" :padded="false" data-listado-carta>
            <p class="sr-only" aria-live="polite" data-disponibilidad-live></p>
            <div class="hidden border-b border-rule px-4 py-3 text-sm text-danger-ink sm:px-5" role="alert" data-disponibilidad-error></div>

            @if ($productos->isEmpty())
                <div class="px-4 py-6 sm:px-5">
                    <p class="rounded-control border border-dashed border-control px-4 py-4 text-sm text-muted">
                        @if ($soloIncompletos && ! $categoriaId && $busqueda === '')
                            No hay productos incompletos. Tu carta está completa.
                        @elseif ($busqueda !== '')
                            Ningún producto coincide con «{{ $busqueda }}».
                        @else
                            No hay productos con estos filtros.
                        @endif
                        <a href="{{ route('panel.carta.productos.index') }}" class="font-medium text-brand-700 underline-offset-2 hover:underline">Ver toda la carta</a>
                    </p>
                </div>
            @else
                <x-table :columns="$columnas" caption="Productos de la carta">
                    @foreach ($productos as $producto)
                        @php
                            $categoriaNombre = $producto->categoria
                                ? ($producto->categoria->parent ? $producto->categoria->parent->nombre.' › ' : '').$producto->categoria->nombre
                                : 'Sin categoría';
                            $faltaDescripcion = blank($producto->descripcion);
                            $faltaFoto = blank($producto->imagen) && $producto->requiereImagen();
                        @endphp
                        <tr class="hover:bg-raised/60" data-producto="{{ $producto->id }}">
                            <td data-cell="lead">
                                <div class="flex items-center gap-3">
                                    @if ($producto->imagenUrl())
                                        <img src="{{ $producto->imagenUrl() }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-chip object-cover">
                                    @else
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-chip bg-raised text-faint" aria-hidden="true">
                                            <x-icon name="book" />
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                            <span class="font-medium text-ink">{{ $producto->nombre }}</span>
                                            @if ($producto->destacado)
                                                <x-badge tone="brand" :dot="false">Destacado</x-badge>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-[0.8125rem] text-muted">
                                            {{ $categoriaNombre }}
                                            @foreach ($producto->etiquetas ?? [] as $etiqueta)
                                                · {{ \App\Models\Producto::ETIQUETAS[$etiqueta] ?? $etiqueta }}
                                            @endforeach
                                        </p>
                                        @if ($faltaDescripcion || $faltaFoto)
                                            <p class="mt-0.5 text-xs font-medium text-warning-ink">
                                                {{ collect([$faltaFoto ? 'Falta foto' : null, $faltaDescripcion ? 'Falta descripción' : null])->filter()->implode(' · ') }}
                                            </p>
                                        @endif
                                        @if ($producto->tieneVariantes() && auth()->user()->can('cambiarDisponibilidad', $producto))
                                            <details class="mt-1">
                                                <summary class="cursor-pointer py-2 text-[0.8125rem] font-medium text-brand-700 lg:py-1">Disponibilidad por variante</summary>
                                                <ul class="mt-1 flex flex-col gap-1" aria-label="Variantes de {{ $producto->nombre }}">
                                                    @foreach ($producto->variantes as $variante)
                                                        <li class="flex items-center justify-between gap-3">
                                                            <span class="min-w-0 text-sm text-ink">{{ $variante->nombre }}</span>
                                                            @include('panel.carta._switch', [
                                                                'disponible' => $variante->disponible,
                                                                'url' => route('panel.carta.productos.variantes.disponibilidad', [$producto, $variante]),
                                                                'etiqueta' => $producto->nombre.', '.$variante->nombre,
                                                            ])
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td data-cell="meta" data-label="Precio" class="num whitespace-nowrap text-right text-ink">
                                @if ($producto->tieneVariantes())
                                    <span class="text-muted">desde</span> {{ $soles($producto->precio) }}
                                    <span class="block text-xs text-muted">{{ $producto->variantes->count() }} variantes</span>
                                @elseif ($producto->esCombo())
                                    {{ $soles($producto->precio) }}
                                    <span class="block text-xs text-muted">Combo · Para {{ $producto->personas }} {{ $producto->personas === 1 ? 'persona' : 'personas' }}</span>
                                @else
                                    {{ $soles($producto->precio) }}
                                @endif
                            </td>
                            <td data-cell="hide-sm" class="text-muted">{{ $producto->estacion === 'barra' ? 'Barra' : 'Cocina' }}</td>
                            <td data-cell="end">
                                @can('cambiarDisponibilidad', $producto)
                                    @include('panel.carta._switch', [
                                        'disponible' => $producto->disponible,
                                        'url' => route('panel.carta.productos.disponibilidad', $producto),
                                        'etiqueta' => $producto->nombre,
                                    ])
                                @else
                                    <x-badge :tone="$producto->disponible ? 'success' : 'neutral'">{{ $producto->disponible ? 'Disponible' : 'Agotado' }}</x-badge>
                                @endcan
                            </td>
                            <td data-cell="action" class="text-right">
                                <div class="flex justify-start gap-1 sm:justify-end">
                                    @can('update', $producto)
                                        <x-button href="{{ route('panel.carta.productos.edit', $producto) }}" size="sm" variant="ghost">Editar</x-button>
                                    @endcan
                                    @can('delete', $producto)
                                        <x-button
                                            size="sm"
                                            variant="ghost"
                                            class="text-danger-ink"
                                            data-modal-open="modal-eliminar-producto"
                                            data-eliminar-url="{{ route('panel.carta.productos.destroy', $producto) }}"
                                            data-eliminar-nombre="{{ $producto->nombre }}"
                                        >Eliminar</x-button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-table>

                @if ($productos->hasPages() || $productos->total() > 0)
                    <div class="flex flex-col items-start gap-3 border-t border-rule px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <p class="num text-[0.8125rem] text-muted">{{ $productos->firstItem() }}–{{ $productos->lastItem() }} de {{ $productos->total() }}</p>
                        @if ($productos->hasPages())
                            <div class="flex gap-2">
                                <x-button :href="$productos->previousPageUrl()" size="sm" :disabled="$productos->onFirstPage()">Anterior</x-button>
                                <x-button :href="$productos->nextPageUrl()" size="sm" :disabled="! $productos->hasMorePages()">Siguiente</x-button>
                            </div>
                        @endif
                    </div>
                @endif
            @endif
        </x-card>
    @endif
@endsection

@push('modals')
    <x-modal id="modal-eliminar-producto" title="Eliminar producto" size="sm">
        <p class="text-sm text-ink">¿Eliminar <strong data-eliminar-texto>este producto</strong> de la carta? Dejará de mostrarse a tus clientes. Los pedidos anteriores lo conservan.</p>
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
