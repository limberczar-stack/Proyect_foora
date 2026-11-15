@extends('layouts.panel')

@use('App\Models\Cuenta')
@use('App\Services\GestionarCuentas')

@section('title', 'Mesas')

@php
    /**
     * Plano de mesas para atender (admin, cajero y mesero). Lo mantiene al día resources/js/salon.js.
     * La gestión del salón (crear y editar áreas y mesas) está en gestion.blade.php.
     *
     * @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Area> $areas  activas, con sus mesas activas
     * @var \Illuminate\Support\Collection<int, array<string, mixed>> $ocupaciones  mesa_id => datos de su cuenta
     * @var array{disponible: int, ocupada: int, fuera_servicio: int, total: int} $resumen
     * @var ?\App\Models\Area $areaActiva
     * @var bool $puedeGestionar
     */
    $navActivo = 'mesas';

    $tabs = $areas->mapWithKeys(fn ($area): array => ['area-'.$area->id => [
        'label' => $area->nombre,
        'count' => $area->mesas->count(),
    ]])->all();
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Mesas</h1>
            <p class="mt-1 text-sm text-muted">Toca una mesa disponible para abrir su cuenta, o una ocupada para verla.</p>
        </div>
        @if ($puedeGestionar)
            <x-button icon="settings" :href="route('panel.mesas.index', ['editar' => 1])">Editar salón</x-button>
        @endif
    </div>

    @if ($errors->any())
        <div class="mt-5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert">
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
            ? 'Todavía no tienes áreas activas. Crea la primera, por ejemplo «Salón principal» o «Terraza», y luego agrega sus mesas.'
            : 'El administrador aún no configuró las mesas del local.'">
            @if ($puedeGestionar)
                <x-slot:empty-action>
                    <x-button size="sm" icon="plus" :href="route('panel.mesas.index', ['editar' => 1])">Configurar salón</x-button>
                </x-slot:empty-action>
            @endif
        </x-card>
    @else
        <div
            data-plano
            data-url="{{ route('panel.mesas.plano') }}"
            data-url-abrir="{{ route('panel.mesas.cuentas.store') }}"
            data-mesas-maximo="{{ GestionarCuentas::MESAS_MAXIMO }}"
        >
            <div class="mt-5 flex flex-wrap items-center gap-2" aria-label="Resumen de mesas">
                <x-badge status="disponible"><span class="num" data-resumen="disponible">{{ $resumen['disponible'] }}</span>&nbsp;disponibles</x-badge>
                <x-badge status="ocupada"><span class="num" data-resumen="ocupada">{{ $resumen['ocupada'] }}</span>&nbsp;ocupadas</x-badge>
                <x-badge status="fuera_servicio"><span class="num" data-resumen="fuera_servicio">{{ $resumen['fuera_servicio'] }}</span>&nbsp;fuera de servicio</x-badge>
            </div>

            <p class="mt-3 hidden items-center gap-2 rounded-control bg-success-bg px-4 py-3 text-sm text-success-ink" role="status" data-plano-aviso></p>
            <p class="mt-3 hidden items-center gap-2 rounded-control bg-warning-bg px-4 py-3 text-sm text-warning-ink" role="status" data-plano-sin-conexion>
                <x-icon name="alert" /> Sin conexión: el plano puede estar desactualizado. Reintentando…
            </p>

            <x-tabs id="mesas-areas" class="mt-4" :tabs="$tabs" :active="'area-'.$areaActiva->id" label="Áreas del local">
                @foreach ($areas as $area)
                    <x-tabs.panel tabs-id="mesas-areas" :name="'area-'.$area->id" :active="$area->is($areaActiva)" class="pt-4">
                        @if ($area->mesas->isEmpty())
                            <x-card empty="Esta área no tiene mesas en uso." />
                        @else
                            <ul class="grid grid-cols-2 gap-3 min-[380px]:grid-cols-3 sm:grid-cols-4 lg:grid-cols-6" aria-label="Mesas de {{ $area->nombre }}">
                                @foreach ($area->mesas as $mesa)
                                    <li data-mesa="{{ $mesa->id }}" data-area="{{ $area->nombre }}">
                                        @include('panel.mesas._mesa', ['mesa' => $mesa, 'cuenta' => $ocupaciones[$mesa->id] ?? null])
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-tabs.panel>
                @endforeach
            </x-tabs>
        </div>
    @endif
@endsection

@push('modals')
    @if ($areas->isNotEmpty())
        {{-- Abrir una cuenta en la mesa tocada; salon.js arma la lista de mesas que se pueden unir --}}
        <x-modal id="modal-abrir-cuenta" title="Abrir cuenta">
            <form method="POST" action="{{ route('panel.mesas.cuentas.store') }}" id="form-abrir-cuenta" class="flex flex-col gap-4" data-cuenta-form data-abrir-form novalidate>
                @csrf
                <input type="hidden" name="mesas[]" value="" data-abrir-mesa>

                <p class="hidden rounded-control bg-danger-bg px-3 py-2.5 text-sm text-danger-ink" role="alert" data-cuenta-error></p>

                <x-campo label="Comensales" for="abrir-comensales" error="comensales">
                    <x-input name="comensales" id="abrir-comensales" type="number" inputmode="numeric" min="1" max="{{ Cuenta::COMENSALES_MAXIMO }}" value="2" required />
                </x-campo>

                <x-campo label="Nota" for="abrir-nota" error="nota" optional>
                    <x-input name="nota" id="abrir-nota" maxlength="255" autocomplete="off" placeholder="Ej.: cumpleaños, silla para bebé" />
                </x-campo>

                <fieldset data-abrir-unir hidden>
                    <legend class="mb-2 block text-sm font-medium text-ink">Unir con otras mesas <span class="font-normal text-muted">(opcional)</span></legend>
                    <div class="flex max-h-52 flex-col gap-3 overflow-y-auto rounded-control border border-line p-3" data-abrir-lista></div>
                    <p class="mt-1.5 hidden text-xs text-danger-ink" data-error-for="mesas"></p>
                </fieldset>
            </form>

            <x-slot:footer>
                <x-button variant="secondary" data-modal-close>Cancelar</x-button>
                <x-button type="submit" form="form-abrir-cuenta" variant="primary" data-cuenta-enviar data-texto="Abrir cuenta" data-texto-ocupado="Abriendo…">Abrir cuenta</x-button>
            </x-slot:footer>
        </x-modal>

        {{-- Detalle de una cuenta: el cuerpo llega por AJAX (panel.mesas._cuenta) --}}
        <x-modal id="modal-cuenta" title="Cuenta">
            <div data-cuenta-cuerpo>
                <div class="flex items-center gap-3 py-6 text-muted">
                    <x-spinner :size="18" label="Cargando cuenta" class="text-brand-700" />
                    <span>Cargando…</span>
                </div>
            </div>
        </x-modal>
    @endif
@endpush
