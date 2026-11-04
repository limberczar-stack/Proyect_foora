@extends('layouts.panel')

@section('title', 'Categorías')

@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\CategoriaProducto> $categorias */
    $navActivo = 'carta';
    $puedeGestionar = auth()->user()->can('create', \App\Models\CategoriaProducto::class);

    // Si el formulario del modal volvió con errores, se reabre con lo escrito.
    $reabrirModal = $errors->any() && old('_modal') === 'categoria';
    $campo = 'h-11 w-full rounded-control border bg-surface px-3 text-sm text-ink placeholder:text-faint focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0';
    $ok = 'border-line focus:border-brand-600 focus-visible:outline-brand-600/30';
    $mal = 'border-danger-dot focus:border-danger-dot focus-visible:outline-danger-dot/30';
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Carta</h1>
            <p class="mt-1 text-sm text-muted">Organiza tu carta en categorías y subcategorías (por ejemplo Bebidas › Frías).</p>
        </div>
        @if ($puedeGestionar)
            <x-button variant="primary" icon="plus" data-modal-open="modal-categoria" data-categoria-nueva>Nueva categoría</x-button>
        @endif
    </div>

    @include('panel.carta._nav')

    @if ($categorias->isEmpty())
        <x-card class="mt-5" empty="Todavía no tienes categorías. Crea la primera, por ejemplo «Pollos a la brasa» o «Bebidas».">
            @if ($puedeGestionar)
                <x-slot:empty-action>
                    <x-button size="sm" icon="plus" data-modal-open="modal-categoria" data-categoria-nueva>Crear categoría</x-button>
                </x-slot:empty-action>
            @endif
        </x-card>
    @else
        <x-card class="mt-5" :padded="false">
            <ul class="divide-y divide-rule" aria-label="Categorías de la carta">
                @foreach ($categorias as $categoria)
                    @include('panel.carta.categorias._fila', [
                        'categoria' => $categoria,
                        'esPrimera' => $loop->first,
                        'esUltima' => $loop->last,
                        'nivel' => 0,
                    ])
                    @foreach ($categoria->hijas as $hija)
                        @include('panel.carta.categorias._fila', [
                            'categoria' => $hija,
                            'esPrimera' => $loop->first,
                            'esUltima' => $loop->last,
                            'nivel' => 1,
                            'padre' => $categoria,
                        ])
                    @endforeach
                @endforeach
            </ul>
        </x-card>
        <p class="mt-3 text-xs text-muted">Las categorías desactivadas no se muestran a tus clientes. «Sin fotos» significa que a sus productos no se les exige foto (por ejemplo bebidas).</p>
    @endif
@endsection

@push('modals')
    @if ($puedeGestionar)
        {{-- Crear o editar categoría (un solo modal; jQuery llena los datos al editar) --}}
        <x-modal id="modal-categoria" title="Nueva categoría" :data-reabrir="$reabrirModal ? 'true' : 'false'">
            <form
                method="POST"
                action="{{ old('_accion', route('panel.carta.categorias.store')) }}"
                id="form-categoria"
                class="flex flex-col gap-4"
                data-loading-form
                data-categoria-form
                data-url-crear="{{ route('panel.carta.categorias.store') }}"
                novalidate
            >
                @csrf
                <input type="hidden" name="_method" value="{{ old('_method', 'POST') }}" data-categoria-metodo>
                <input type="hidden" name="_modal" value="categoria">
                <input type="hidden" name="_accion" value="{{ old('_accion', route('panel.carta.categorias.store')) }}" data-categoria-accion>
                <input type="hidden" name="_titulo" value="{{ old('_titulo', 'Nueva categoría') }}" data-categoria-titulo>

                <div>
                    <label for="categoria-nombre" class="mb-2 block text-sm font-medium">Nombre</label>
                    <input id="categoria-nombre" name="nombre" type="text" maxlength="80" required value="{{ $reabrirModal ? old('nombre') : '' }}"
                        placeholder="Ej.: Bebidas" data-campo="nombre"
                        @error('nombre') aria-invalid="true" aria-describedby="categoria-nombre-error" @enderror
                        class="{{ $campo }} {{ $errors->has('nombre') ? $mal : $ok }}">
                    @error('nombre')<p id="categoria-nombre-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="categoria-parent" class="mb-2 block text-sm font-medium">Dentro de <span class="font-normal text-muted">(opcional)</span></label>
                    <select id="categoria-parent" name="parent_id" data-campo="parent_id"
                        @error('parent_id') aria-invalid="true" aria-describedby="categoria-parent-error" @enderror
                        class="{{ $campo }} {{ $errors->has('parent_id') ? $mal : $ok }}">
                        <option value="">Ninguna: es una categoría principal</option>
                        @foreach ($categorias as $opcion)
                            <option value="{{ $opcion->id }}" @selected($reabrirModal && (int) old('parent_id') === $opcion->id)>{{ $opcion->nombre }}</option>
                        @endforeach
                    </select>
                    @error('parent_id')<p id="categoria-parent-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="categoria-descripcion" class="mb-2 block text-sm font-medium">Descripción <span class="font-normal text-muted">(opcional)</span></label>
                    <input id="categoria-descripcion" name="descripcion" type="text" maxlength="255" value="{{ $reabrirModal ? old('descripcion') : '' }}"
                        placeholder="Ej.: Refrescos, jugos y chicha" data-campo="descripcion"
                        @error('descripcion') aria-invalid="true" aria-describedby="categoria-descripcion-error" @enderror
                        class="{{ $campo }} {{ $errors->has('descripcion') ? $mal : $ok }}">
                    @error('descripcion')<p id="categoria-descripcion-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>

                <div class="flex flex-col">
                    <x-toggle name="activa" label="Activa" description="Se muestra a tus clientes." :checked="$reabrirModal ? (bool) old('activa') : true" id="categoria-activa" />
                    <x-toggle name="con_fotos" label="Con fotos" description="Apágalo en bebidas o vinos: no se les exigirá foto." :checked="$reabrirModal ? (bool) old('con_fotos') : true" id="categoria-con-fotos" class="border-t border-rule pt-1" />
                </div>
            </form>

            <x-slot:footer>
                <x-button variant="secondary" data-modal-close>Cancelar</x-button>
                <x-button type="submit" form="form-categoria" variant="primary" data-submit data-loading-text="Guardando…">
                    <span data-submit-spinner hidden><x-spinner :size="16" label="Guardando" /></span>
                    <span data-submit-label>Guardar</span>
                </x-button>
            </x-slot:footer>
        </x-modal>

        {{-- Confirmar eliminación --}}
        <x-modal id="modal-eliminar-categoria" title="Eliminar categoría" size="sm">
            <p class="text-sm text-ink">¿Eliminar la categoría <strong data-eliminar-texto>esta categoría</strong>? Solo se puede eliminar si no tiene productos ni subcategorías.</p>
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
    @endif
@endpush
