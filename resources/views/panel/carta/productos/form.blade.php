@extends('layouts.panel')

@php
    /** @var \App\Models\Producto $producto */
    $navActivo = 'carta';
    $esNuevo = ! $producto->exists;
    $tipo = old('tipo', $producto->tipo === 'variantes' ? 'variantes' : 'simple');
    $etiquetasElegidas = old('etiquetas', $producto->etiquetas ?? []);
    $categoriaElegida = (int) old('categoria_id', $producto->categoria_id);

    $variantes = old('variantes', $producto->exists
        ? $producto->variantes->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre, 'precio' => number_format((float) $v->precio, 2, '.', ''), 'disponible' => $v->disponible])->all()
        : []);
    while (count($variantes) < 2) {
        $variantes[] = ['id' => null, 'nombre' => '', 'precio' => '', 'disponible' => true];
    }

    $precio = old('precio', $producto->exists && $producto->tipo === 'simple' ? number_format((float) $producto->precio, 2, '.', '') : '');

    $input = 'h-11 w-full rounded-control border bg-surface px-3 text-sm text-ink placeholder:text-faint focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0';
    $ok = 'border-line focus:border-brand-600 focus-visible:outline-brand-600/30';
    $mal = 'border-danger-dot focus:border-danger-dot focus-visible:outline-danger-dot/30';
    $clase = fn (string $campo): string => $input.' '.($errors->has($campo) ? $mal : $ok);
@endphp

@section('title', $esNuevo ? 'Nuevo producto' : 'Editar producto')

@section('content')
    <div class="flex flex-col gap-1">
        <a href="{{ route('panel.carta.productos.index') }}" class="inline-flex w-fit items-center gap-1 rounded-chip text-sm text-muted hover:text-ink">
            <x-icon name="chevron-right" size="14" class="rotate-180" /> Carta
        </a>
        <h1 class="text-title tracking-tight text-ink">{{ $esNuevo ? 'Nuevo producto' : $producto->nombre }}</h1>
    </div>

    @if ($errors->any())
        <div class="mt-5 flex items-start gap-2.5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert">
            <x-icon name="alert" class="mt-0.5" />
            <p>Revisa los campos marcados: {{ $errors->count() === 1 ? 'hay 1 error' : 'hay '.$errors->count().' errores' }}.</p>
        </div>
    @endif

    @if ($categorias->isEmpty())
        <x-card class="mt-5" empty="Antes de agregar productos crea al menos una categoría.">
            <x-slot:empty-action>
                <x-button href="{{ route('panel.carta.categorias.index') }}" size="sm" icon="plus">Crear categoría</x-button>
            </x-slot:empty-action>
        </x-card>
    @else
        <form
            method="POST"
            action="{{ $esNuevo ? route('panel.carta.productos.store') : route('panel.carta.productos.update', $producto) }}"
            enctype="multipart/form-data"
            class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
            data-loading-form
            data-producto-form
            novalidate
        >
            @csrf
            @unless ($esNuevo)
                @method('PUT')
            @endunless

            <div class="flex min-w-0 flex-col gap-4">
                <x-card title="Datos del producto">
                    <div class="flex flex-col gap-4">
                        <div>
                            <label for="nombre" class="mb-2 block text-sm font-medium">Nombre</label>
                            <input id="nombre" name="nombre" type="text" value="{{ old('nombre', $producto->nombre) }}" maxlength="120" required
                                placeholder="Ej.: 1/4 de pollo a la brasa"
                                @error('nombre') aria-invalid="true" aria-describedby="nombre-error" @enderror
                                class="{{ $clase('nombre') }}">
                            @error('nombre')<p id="nombre-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="categoria_id" class="mb-2 block text-sm font-medium">Categoría</label>
                            <select id="categoria_id" name="categoria_id" required data-categoria-select
                                @error('categoria_id') aria-invalid="true" aria-describedby="categoria-error" @enderror
                                class="{{ $clase('categoria_id') }}">
                                <option value="">Elige una categoría</option>
                                @foreach ($categorias as $categoria)
                                    <option value="{{ $categoria->id }}" data-con-fotos="{{ $categoria->con_fotos ? 1 : 0 }}" @selected($categoriaElegida === $categoria->id)>{{ $categoria->nombre }}</option>
                                    @foreach ($categoria->hijas as $hija)
                                        <option value="{{ $hija->id }}" data-con-fotos="{{ $hija->con_fotos ? 1 : 0 }}" @selected($categoriaElegida === $hija->id)>&nbsp;&nbsp;&nbsp;{{ $categoria->nombre }} › {{ $hija->nombre }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                            @error('categoria_id')<p id="categoria-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="descripcion" class="mb-2 block text-sm font-medium">Descripción <span class="font-normal text-muted">(recomendada)</span></label>
                            <textarea id="descripcion" name="descripcion" rows="3" maxlength="1000"
                                placeholder="Ej.: Con papas fritas, ensalada y cremas de la casa."
                                @error('descripcion') aria-invalid="true" aria-describedby="descripcion-error" @enderror
                                class="{{ str_replace('h-11', 'py-2.5', $clase('descripcion')) }}">{{ old('descripcion', $producto->descripcion) }}</textarea>
                            @error('descripcion')<p id="descripcion-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </x-card>

                <x-card title="Precio">
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">¿Cómo se vende?</legend>
                        <div class="grid grid-cols-2 gap-1 rounded-control bg-raised p-1 sm:w-fit">
                            @foreach (\App\Models\Producto::TIPOS_EDITABLES as $valor => $texto)
                                <label class="relative">
                                    <input type="radio" name="tipo" value="{{ $valor }}" class="peer sr-only" data-tipo-radio @checked($tipo === $valor)>
                                    <span class="press flex h-10 cursor-pointer items-center justify-center whitespace-nowrap rounded-[0.5rem] px-4 text-sm text-muted peer-checked:bg-surface peer-checked:font-medium peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">{{ $texto }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- Precio único --}}
                    <div class="mt-4" data-seccion-tipo="simple" @if ($tipo !== 'simple') hidden @endif>
                        <label for="precio" class="mb-2 block text-sm font-medium">Precio</label>
                        <div class="relative sm:w-48">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted">S/</span>
                            <input id="precio" name="precio" type="text" inputmode="decimal" value="{{ $precio }}" placeholder="0.00"
                                @error('precio') aria-invalid="true" aria-describedby="precio-error" @enderror
                                class="num {{ $clase('precio') }} pl-9 text-right">
                        </div>
                        @error('precio')<p id="precio-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>

                    {{-- Variantes --}}
                    <div class="mt-4" data-seccion-tipo="variantes" @if ($tipo !== 'variantes') hidden @endif>
                        <p class="text-sm text-muted">Cada variante tiene su propio precio (por ejemplo Taza, Personal, Familiar). En la carta se mostrará «desde» el menor.</p>
                        @error('variantes')<p class="mt-2 text-sm font-medium text-danger-ink" role="alert">{{ $message }}</p>@enderror

                        <div class="mt-3 hidden grid-cols-[1fr_8rem_7rem_5.5rem] gap-3 px-1 text-xs text-muted sm:grid" aria-hidden="true">
                            <span>Nombre</span><span class="text-right">Precio</span><span>Disponible</span><span></span>
                        </div>

                        <ol class="mt-2 flex flex-col gap-2" data-variantes-lista>
                            @foreach ($variantes as $i => $variante)
                                @include('panel.carta.productos._variante', ['indice' => $i, 'variante' => $variante])
                            @endforeach
                        </ol>

                        <template data-variante-template>
                            @include('panel.carta.productos._variante', ['indice' => '__INDICE__', 'variante' => ['id' => null, 'nombre' => '', 'precio' => '', 'disponible' => true]])
                        </template>

                        <x-button size="sm" icon="plus" class="mt-3" data-variante-agregar>Agregar variante</x-button>
                    </div>
                </x-card>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <x-card title="Foto">
                    <div class="flex flex-col gap-3">
                        <div class="relative aspect-[4/3] overflow-hidden rounded-control bg-raised">
                            <img @if ($producto->imagenUrl()) src="{{ $producto->imagenUrl() }}" @endif alt="Vista previa de la foto" data-imagen-preview
                                @class(['size-full object-cover', 'hidden' => ! $producto->imagenUrl()])>
                            <div data-imagen-vacia @class(['absolute inset-0 flex flex-col items-center justify-center gap-1 text-faint', 'hidden' => $producto->imagenUrl()])>
                                <x-icon name="book" size="24" />
                                <span class="text-xs">Sin foto</span>
                            </div>
                        </div>
                        <label for="imagen" class="text-sm font-medium">{{ $producto->imagen ? 'Cambiar foto' : 'Subir foto' }}</label>
                        <input id="imagen" name="imagen" type="file" accept="image/jpeg,image/png,image/webp" data-imagen-input
                            aria-describedby="imagen-ayuda @error('imagen') imagen-error @enderror"
                            class="block w-full text-sm text-muted file:mr-3 file:h-10 file:cursor-pointer file:rounded-control file:border file:border-control file:bg-surface file:px-3 file:text-sm file:font-medium file:text-ink hover:file:bg-raised">
                        <p id="imagen-ayuda" class="text-xs text-muted">JPG, PNG o WEBP, máximo 2 MB. <span data-con-fotos-nota hidden>En esta categoría la foto es opcional.</span></p>
                        <p class="hidden text-xs text-danger-ink" data-imagen-error role="alert"></p>
                        @error('imagen')<p id="imagen-error" class="text-xs text-danger-ink" role="alert">{{ $message }}</p>@enderror
                        @if ($producto->imagen)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                                <input type="checkbox" name="quitar_imagen" value="1" class="size-[1.125rem] accent-brand-700" @checked(old('quitar_imagen')) data-quitar-imagen>
                                Quitar la foto actual
                            </label>
                        @endif
                    </div>
                </x-card>

                <x-card title="Opciones">
                    <div class="flex flex-col gap-1">
                        <div class="pb-3">
                            <p class="mb-2 text-sm font-medium">Estación</p>
                            <div class="grid grid-cols-2 gap-1 rounded-control bg-raised p-1">
                                @foreach (['cocina' => 'Cocina', 'barra' => 'Barra'] as $valor => $texto)
                                    <label class="relative">
                                        <input type="radio" name="estacion" value="{{ $valor }}" class="peer sr-only" @checked(old('estacion', $producto->estacion ?? 'cocina') === $valor)>
                                        <span class="press flex h-10 cursor-pointer items-center justify-center rounded-[0.5rem] text-sm text-muted peer-checked:bg-surface peer-checked:font-medium peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">{{ $texto }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-1.5 text-xs text-muted">Define a qué impresora va la comanda.</p>
                        </div>
                        <x-toggle name="disponible" label="Disponible" description="Si lo apagas, se muestra como agotado." :checked="(bool) old('disponible', $producto->disponible ?? true)" class="border-t border-rule pt-1" />
                        <x-toggle name="destacado" label="Destacado" description="Aparece primero en la app." :checked="(bool) old('destacado', $producto->destacado)" class="border-t border-rule pt-1" />
                    </div>
                </x-card>

                <x-card title="Etiquetas">
                    <fieldset>
                        <legend class="sr-only">Etiquetas del producto</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\Producto::ETIQUETAS as $clave => $texto)
                                <label class="relative">
                                    <input type="checkbox" name="etiquetas[]" value="{{ $clave }}" class="peer sr-only" @checked(in_array($clave, $etiquetasElegidas, true))>
                                    <span class="press flex h-9 cursor-pointer items-center rounded-full border border-control px-3 text-sm text-muted peer-checked:border-brand-700 peer-checked:bg-brand-50 peer-checked:font-medium peer-checked:text-brand-800 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">{{ $texto }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </x-card>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end lg:col-span-2">
                <x-button href="{{ route('panel.carta.productos.index') }}" variant="secondary">Cancelar</x-button>
                <x-button type="submit" variant="primary" data-submit data-loading-text="Guardando…">
                    <span data-submit-spinner hidden><x-spinner :size="16" label="Guardando" /></span>
                    <span data-submit-label>{{ $esNuevo ? 'Crear producto' : 'Guardar cambios' }}</span>
                </x-button>
            </div>
        </form>
    @endif
@endsection
