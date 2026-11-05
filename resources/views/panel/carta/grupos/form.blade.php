@extends('layouts.panel')

@php
    /** @var \App\Models\GrupoOpcion $grupo */
    $navActivo = 'carta';
    $esNuevo = ! $grupo->exists;
    $tipo = old('tipo', $grupo->tipo);

    $opciones = old('opciones', $grupo->exists
        ? $grupo->opciones->map(fn ($o) => ['id' => $o->id, 'nombre' => $o->nombre, 'precio_extra' => number_format((float) $o->precio_extra, 2, '.', ''), 'disponible' => $o->disponible])->all()
        : []);
    if ($opciones === []) {
        $opciones[] = ['id' => null, 'nombre' => '', 'precio_extra' => '', 'disponible' => true];
    }

    $input = 'h-11 w-full rounded-control border bg-surface px-3 text-sm text-ink placeholder:text-faint focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0';
    $ok = 'border-line focus:border-brand-600 focus-visible:outline-brand-600/30';
    $mal = 'border-danger-dot focus:border-danger-dot focus-visible:outline-danger-dot/30';
    $clase = fn (string $campo): string => $input.' '.($errors->has($campo) ? $mal : $ok);
@endphp

@section('title', $esNuevo ? 'Nuevo grupo de opciones' : 'Editar grupo de opciones')

@section('content')
    <div class="flex flex-col gap-1">
        <a href="{{ route('panel.carta.grupos.index') }}" class="inline-flex w-fit items-center gap-1 rounded-chip text-sm text-muted hover:text-ink">
            <x-icon name="chevron-right" size="14" class="rotate-180" /> Grupos de opciones
        </a>
        <h1 class="text-title tracking-tight text-ink">{{ $esNuevo ? 'Nuevo grupo de opciones' : $grupo->nombre }}</h1>
    </div>

    @if ($errors->any())
        <div class="mt-5 flex items-start gap-2.5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert">
            <x-icon name="alert" class="mt-0.5" />
            <p>Revisa los campos marcados: {{ $errors->count() === 1 ? 'hay 1 error' : 'hay '.$errors->count().' errores' }}.</p>
        </div>
    @endif

    <form
        method="POST"
        action="{{ $esNuevo ? route('panel.carta.grupos.store') : route('panel.carta.grupos.update', $grupo) }}"
        class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
        data-loading-form
        data-grupo-form
        novalidate
    >
        @csrf
        @unless ($esNuevo)
            @method('PUT')
        @endunless

        <x-card title="Opciones" subtitle="El precio extra se suma al del producto. Déjalo vacío si no tiene costo.">
            @error('opciones')<p class="mb-2 text-sm font-medium text-danger-ink" role="alert">{{ $message }}</p>@enderror

            <div class="hidden grid-cols-[1fr_8rem_7rem_8.5rem] gap-3 px-1 text-xs text-muted sm:grid" aria-hidden="true">
                <span>Nombre</span><span class="text-right">Precio extra</span><span>Disponible</span><span></span>
            </div>

            <ol class="mt-2 flex flex-col gap-2" data-opcion-lista>
                @foreach ($opciones as $i => $opcion)
                    @include('panel.carta.grupos._opcion', ['indice' => $i, 'opcion' => $opcion])
                @endforeach
            </ol>

            <template data-opcion-template>
                @include('panel.carta.grupos._opcion', ['indice' => '__INDICE__', 'opcion' => ['id' => null, 'nombre' => '', 'precio_extra' => '', 'disponible' => true]])
            </template>

            <x-button size="sm" icon="plus" class="mt-3" data-opcion-agregar>Agregar opción</x-button>
        </x-card>

        <x-card title="Datos del grupo" class="order-first lg:order-none lg:col-start-2 lg:row-start-1">
            <div class="flex flex-col gap-4">
                <div>
                    <label for="nombre" class="mb-2 block text-sm font-medium">Nombre</label>
                    <input id="nombre" name="nombre" type="text" value="{{ old('nombre', $grupo->nombre) }}" maxlength="80" required
                        placeholder="Ej.: Elige tu guarnición"
                        @error('nombre') aria-invalid="true" aria-describedby="nombre-error" @enderror
                        class="{{ $clase('nombre') }}">
                    <p class="mt-1.5 text-xs text-muted">Es lo que ve el cliente.</p>
                    @error('nombre')<p id="nombre-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium">¿Cuántas puede elegir?</legend>
                    <div class="grid grid-cols-2 gap-1 rounded-control bg-raised p-1">
                        @foreach (['unica' => 'Solo una', 'multiple' => 'Varias'] as $valor => $texto)
                            <label class="relative">
                                <input type="radio" name="tipo" value="{{ $valor }}" class="peer sr-only" data-grupo-tipo @checked($tipo === $valor)>
                                <span class="press flex h-10 cursor-pointer items-center justify-center rounded-[0.5rem] text-sm text-muted peer-checked:bg-surface peer-checked:font-medium peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">{{ $texto }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('tipo')<p class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </fieldset>

                <x-toggle name="obligatorio" label="Obligatorio" description="El cliente no puede continuar sin elegir." :checked="(bool) old('obligatorio', $grupo->obligatorio)" />

                <div class="grid grid-cols-2 gap-3" data-grupo-limites @if ($tipo !== 'multiple') hidden @endif>
                    <div>
                        <label for="min_opciones" class="mb-2 block text-sm font-medium">Mínimo</label>
                        <input id="min_opciones" name="min_opciones" type="number" inputmode="numeric" min="0" max="{{ \App\Models\GrupoOpcion::LIMITE_OPCIONES }}" value="{{ old('min_opciones', $grupo->min_opciones) }}"
                            @error('min_opciones') aria-invalid="true" aria-describedby="min-error" @enderror
                            class="num {{ $clase('min_opciones') }}">
                    </div>
                    <div>
                        <label for="max_opciones" class="mb-2 block text-sm font-medium">Máximo</label>
                        <input id="max_opciones" name="max_opciones" type="number" inputmode="numeric" min="1" max="{{ \App\Models\GrupoOpcion::LIMITE_OPCIONES }}" value="{{ old('max_opciones', $grupo->tipo === 'multiple' ? $grupo->max_opciones : null) }}" placeholder="Sin límite"
                            @error('max_opciones') aria-invalid="true" aria-describedby="max-error" @enderror
                            class="num {{ $clase('max_opciones') }}">
                    </div>
                    @error('min_opciones')<p id="min-error" class="col-span-2 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    @error('max_opciones')<p id="max-error" class="col-span-2 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>

                <x-toggle name="activo" label="Activo" description="Si lo apagas, no se muestra ni se exige en ningún producto." :checked="(bool) old('activo', $grupo->activo)" class="border-t border-rule pt-1" />
            </div>
        </x-card>

        <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end lg:col-span-2">
            <x-button href="{{ route('panel.carta.grupos.index') }}" variant="secondary">Cancelar</x-button>
            <x-button type="submit" variant="primary" data-submit data-loading-text="Guardando…">
                <span data-submit-spinner hidden><x-spinner :size="16" label="Guardando" /></span>
                <span data-submit-label>{{ $esNuevo ? 'Crear grupo' : 'Guardar cambios' }}</span>
            </x-button>
        </div>
    </form>
@endsection
