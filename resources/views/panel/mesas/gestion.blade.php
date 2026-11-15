@extends('layouts.panel')

@use('App\Models\Mesa')

@section('title', 'Editar salón')

@php
    /**
     * Gestión del salón (solo el admin, panel/mesas?editar=1): áreas y mesas, también las desactivadas.
     * El plano para atender está en index.blade.php.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\Area> $areas  con todas sus mesas
     * @var ?\App\Models\Area $areaActiva
     * @var bool $puedeGestionar
     * @var array{activas: int, limite: ?int}|null $cupo
     * @var ?string $planNombre
     */
    $navActivo = 'mesas';

    // Si un formulario de modal volvió con errores, se reabre ese modal con lo escrito.
    $modalConError = $errors->any() ? old('_modal') : null;
    $erroresSueltos = $errors->any() && $modalConError === null;

    // Solo lo que está en servicio cuenta en el resumen: mesas activas de áreas activas.
    $enServicio = $areas->where('activa', true)->flatMap->mesas->where('activa', true);
    $resumen = [
        'disponible' => $enServicio->where('estado', 'disponible')->count(),
        'fuera_servicio' => $enServicio->where('estado', 'fuera_servicio')->count(),
    ];

    $tabs = $areas->mapWithKeys(fn ($area): array => ['area-'.$area->id => [
        'label' => $area->nombre.($area->activa ? '' : ' (desactivada)'),
        'count' => $area->mesas->where('activa', true)->count(),
    ]])->all();
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('panel.mesas.index') }}" class="press mb-1 inline-flex items-center gap-1 rounded-chip text-sm text-muted hover:text-ink"><x-icon name="chevron-left" /> Volver al plano</a>
            <h1 class="text-title tracking-tight text-ink">Editar salón</h1>
            @if ($puedeGestionar && $cupo !== null)
                <p class="num mt-1 text-sm text-muted" data-mesas-cupo>
                    @if ($cupo['limite'] === null)
                        {{ $cupo['activas'] }} {{ $cupo['activas'] === 1 ? 'mesa activa' : 'mesas activas' }}
                    @else
                        {{ $cupo['activas'] }} de {{ $cupo['limite'] }} mesas activas de tu plan {{ $planNombre }}
                    @endif
                </p>
            @else
                <p class="mt-1 text-sm text-muted">Estado de las mesas del local por área.</p>
            @endif
        </div>
        @if ($puedeGestionar)
            <div class="flex flex-wrap gap-2">
                @if ($areas->isNotEmpty())
                    <x-button variant="primary" icon="plus" data-modal-open="modal-mesa" data-mesa-nueva data-area-id="{{ $areaActiva?->id }}">Mesa</x-button>
                    <x-button icon="plus" data-modal-open="modal-varias" data-varias-abrir data-area-id="{{ $areaActiva?->id }}">Agregar varias</x-button>
                @endif
                <x-button :variant="$areas->isEmpty() ? 'primary' : 'secondary'" icon="plus" data-modal-open="modal-area" data-area-nueva>Área</x-button>
            </div>
        @endif
    </div>

    @if ($erroresSueltos)
        <div class="mt-5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert" data-mesas-errores>
            <p class="flex items-center gap-2 font-medium"><x-icon name="alert" /> No se pudo completar</p>
            <ul class="mt-1 list-disc pl-10">
                @foreach ($errors->all() as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($areas->isEmpty())
        <x-card class="mt-5" :empty="$puedeGestionar
            ? 'Todavía no tienes áreas. Crea la primera, por ejemplo «Salón principal» o «Terraza», y luego agrega sus mesas.'
            : 'El administrador aún no configuró las mesas del local.'">
            @if ($puedeGestionar)
                <x-slot:empty-action>
                    <x-button size="sm" icon="plus" data-modal-open="modal-area" data-area-nueva>Crear área</x-button>
                </x-slot:empty-action>
            @endif
        </x-card>
    @else
        <div class="mt-5 flex flex-wrap items-center gap-2" aria-label="Resumen de mesas en servicio">
            <x-badge status="disponible"><span class="num">{{ $resumen['disponible'] }}</span>&nbsp;{{ $resumen['disponible'] === 1 ? 'disponible' : 'disponibles' }}</x-badge>
            <x-badge status="fuera_servicio"><span class="num">{{ $resumen['fuera_servicio'] }}</span>&nbsp;fuera de servicio</x-badge>
        </div>

        <x-tabs id="mesas-areas" class="mt-4" :tabs="$tabs" :active="'area-'.$areaActiva->id" label="Áreas del local">
            @foreach ($areas as $area)
                @php
                    $mesasVisibles = $area->mesas;
                @endphp
                <x-tabs.panel tabs-id="mesas-areas" :name="'area-'.$area->id" :active="$area->is($areaActiva)" class="pt-4">
                    @unless ($area->activa)
                        <p class="mb-3 flex items-start gap-2 rounded-control bg-raised px-3 py-2.5 text-sm text-muted">
                            <x-icon name="lock" class="mt-0.5" />
                            <span>Área desactivada: cajeros y meseros no la ven. Sus mesas activas siguen contando para tu plan.</span>
                        </p>
                    @endunless

                    @if ($mesasVisibles->isEmpty())
                        <x-card :empty="$puedeGestionar ? 'Esta área aún no tiene mesas.' : 'Esta área no tiene mesas en uso.'">
                            @if ($puedeGestionar)
                                <x-slot:empty-action>
                                    <x-button size="sm" icon="plus" data-modal-open="modal-varias" data-varias-abrir data-area-id="{{ $area->id }}">Agregar mesas</x-button>
                                </x-slot:empty-action>
                            @endif
                        </x-card>
                    @else
                        <ul class="grid grid-cols-2 gap-3 min-[380px]:grid-cols-3 sm:grid-cols-4 lg:grid-cols-6" aria-label="Mesas de {{ $area->nombre }}">
                            @foreach ($mesasVisibles as $mesa)
                                <li>
                                    @if ($puedeGestionar)
                                        <x-mesa
                                            as="button"
                                            :codigo="$mesa->codigo"
                                            :capacidad="$mesa->capacidad"
                                            :estado="$mesa->estado"
                                            :inactiva="! $mesa->activa || ! $area->activa"
                                            aria-label="Editar mesa {{ $mesa->codigo }}"
                                            data-modal-open="modal-mesa"
                                            data-mesa-editar
                                            data-url="{{ route('panel.mesas.update', $mesa) }}"
                                            data-url-estado="{{ route('panel.mesas.estado', $mesa) }}"
                                            data-url-activa="{{ route('panel.mesas.activa', $mesa) }}"
                                            data-url-mover="{{ route('panel.mesas.mover', $mesa) }}"
                                            data-codigo="{{ $mesa->codigo }}"
                                            data-capacidad="{{ $mesa->capacidad }}"
                                            data-area-id="{{ $mesa->area_id }}"
                                            data-estado="{{ $mesa->estado }}"
                                            data-activa="{{ $mesa->activa ? 1 : 0 }}"
                                            data-primera="{{ $loop->first ? 1 : 0 }}"
                                            data-ultima="{{ $loop->last ? 1 : 0 }}"
                                        />
                                    @else
                                        <x-mesa :codigo="$mesa->codigo" :capacidad="$mesa->capacidad" :estado="$mesa->estado" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-tabs.panel>
            @endforeach
        </x-tabs>

        @if ($puedeGestionar)
            <x-card class="mt-6" title="Áreas" subtitle="El orden de esta lista es el de las pestañas." :padded="false">
                <ul class="divide-y divide-rule border-t border-rule" aria-label="Áreas del local">
                    @foreach ($areas as $area)
                        @php
                            $total = $area->mesas->count();
                        @endphp
                        <li class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-4 sm:px-5">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span @class(['font-medium', 'text-ink' => $area->activa, 'text-muted' => ! $area->activa])>{{ $area->nombre }}</span>
                                    @unless ($area->activa)
                                        <x-badge tone="neutral">Desactivada</x-badge>
                                    @endunless
                                </p>
                                <p class="num mt-0.5 text-[0.8125rem] text-muted">{{ $total }} {{ $total === 1 ? 'mesa' : 'mesas' }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @foreach (['subir' => 'Subir', 'bajar' => 'Bajar'] as $direccion => $texto)
                                    <form method="POST" action="{{ route('panel.mesas.areas.mover', $area) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="direccion" value="{{ $direccion }}">
                                        <x-button type="submit" variant="ghost" size="icon" class="size-10! lg:size-9!" :disabled="$direccion === 'subir' ? $loop->parent->first : $loop->parent->last" aria-label="{{ $texto }} {{ $area->nombre }}">
                                            <x-icon name="chevron-down" :class="$direccion === 'subir' ? 'rotate-180' : ''" />
                                        </x-button>
                                    </form>
                                @endforeach
                                <form method="POST" action="{{ route('panel.mesas.areas.activa', $area) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activa" value="{{ $area->activa ? 0 : 1 }}">
                                    <x-button type="submit" size="sm" variant="ghost">{{ $area->activa ? 'Desactivar' : 'Activar' }}</x-button>
                                </form>
                                <x-button size="sm" variant="ghost" data-modal-open="modal-area" data-area-editar data-url="{{ route('panel.mesas.areas.update', $area) }}" data-nombre="{{ $area->nombre }}">Editar</x-button>
                                <x-button
                                    size="sm"
                                    variant="ghost"
                                    class="text-danger-ink"
                                    data-modal-open="modal-eliminar-area"
                                    data-eliminar-area-url="{{ route('panel.mesas.areas.destroy', $area) }}"
                                    data-eliminar-area-nombre="{{ $area->nombre }}"
                                    data-eliminar-area-mesas="{{ $total }}"
                                >Eliminar</x-button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>
            <p class="mt-3 text-xs text-muted">Toca una mesa para editarla, cambiar su orden, ponerla fuera de servicio o desactivarla. Las mesas no se borran: se desactivan y dejan de contar para tu plan.</p>
        @endif
    @endif
@endsection

@push('modals')
    @if ($puedeGestionar)
        @include('panel.mesas._modales')
    @endif
@endpush
