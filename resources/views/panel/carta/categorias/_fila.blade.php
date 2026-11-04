@php
    /**
     * Fila de una categoría (nivel 0) o subcategoría (nivel 1).
     *
     * @var \App\Models\CategoriaProducto $categoria
     */
    $puedeEditar = auth()->user()->can('update', $categoria);
    $tieneHijas = $nivel === 0 && $categoria->hijas->isNotEmpty();
@endphp

<li @class(['flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-4 sm:px-5', 'bg-raised/40 pl-8 sm:pl-12' => $nivel === 1])>
    <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @if ($nivel === 1)
                <span class="text-faint" aria-hidden="true">↳</span>
                <span class="sr-only">Subcategoría de {{ $padre->nombre }}:</span>
            @endif
            <span @class(['font-medium', 'text-ink' => $categoria->activa, 'text-muted' => ! $categoria->activa])>{{ $categoria->nombre }}</span>
            @unless ($categoria->activa)
                <x-badge tone="neutral">Desactivada</x-badge>
            @endunless
            @unless ($categoria->con_fotos)
                <x-badge tone="neutral" :dot="false">Sin fotos</x-badge>
            @endunless
        </p>
        <p class="num mt-0.5 text-[0.8125rem] text-muted">
            {{ $categoria->productos_count }} {{ $categoria->productos_count === 1 ? 'producto' : 'productos' }}@if ($tieneHijas) · {{ $categoria->hijas->count() }} {{ $categoria->hijas->count() === 1 ? 'subcategoría' : 'subcategorías' }}@endif
            @if ($categoria->descripcion) · {{ $categoria->descripcion }}@endif
        </p>
    </div>

    @if ($puedeEditar)
        <div class="flex flex-wrap items-center gap-1">
            <form method="POST" action="{{ route('panel.carta.categorias.mover', $categoria) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="direccion" value="subir">
                <x-button type="submit" variant="ghost" size="icon" class="size-10! lg:size-9!" :disabled="$esPrimera" aria-label="Subir {{ $categoria->nombre }}">
                    <x-icon name="chevron-down" class="rotate-180" />
                </x-button>
            </form>
            <form method="POST" action="{{ route('panel.carta.categorias.mover', $categoria) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="direccion" value="bajar">
                <x-button type="submit" variant="ghost" size="icon" class="size-10! lg:size-9!" :disabled="$esUltima" aria-label="Bajar {{ $categoria->nombre }}">
                    <x-icon name="chevron-down" />
                </x-button>
            </form>
            <form method="POST" action="{{ route('panel.carta.categorias.estado', $categoria) }}">
                @csrf
                @method('PATCH')
                <x-button type="submit" size="sm" variant="ghost">{{ $categoria->activa ? 'Desactivar' : 'Activar' }}</x-button>
            </form>
            <x-button
                size="sm"
                variant="ghost"
                data-modal-open="modal-categoria"
                data-categoria-editar
                data-url="{{ route('panel.carta.categorias.update', $categoria) }}"
                data-nombre="{{ $categoria->nombre }}"
                data-descripcion="{{ $categoria->descripcion }}"
                data-parent-id="{{ $categoria->parent_id }}"
                data-activa="{{ $categoria->activa ? 1 : 0 }}"
                data-con-fotos="{{ $categoria->con_fotos ? 1 : 0 }}"
                data-tiene-hijas="{{ $tieneHijas ? 1 : 0 }}"
                data-id="{{ $categoria->id }}"
            >Editar</x-button>
            <x-button
                size="sm"
                variant="ghost"
                class="text-danger-ink"
                data-modal-open="modal-eliminar-categoria"
                data-eliminar-url="{{ route('panel.carta.categorias.destroy', $categoria) }}"
                data-eliminar-nombre="{{ $categoria->nombre }}"
            >Eliminar</x-button>
        </div>
    @endif
</li>
