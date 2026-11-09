@extends('layouts.panel')

@use('App\Models\Suscripcion')
@use('Illuminate\Support\Carbon')

@section('title', $restaurante->nombre)

@php
    /**
     * @var \App\Models\Restaurante $restaurante
     * @var \Illuminate\Support\Collection<int, Suscripcion> $suscripciones
     * @var \Illuminate\Support\Collection<int, \App\Models\SuscripcionPago> $pagos
     * @var ?\App\Models\SuscripcionPago $pagoAnulable
     * @var \Illuminate\Support\Collection<int, \App\Models\User> $personal
     * @var int $pedidosMes
     * @var \Illuminate\Support\Collection<int, \App\Models\ActividadLog> $historial
     * @var \Illuminate\Support\Collection<int, \App\Models\Rubro> $rubros
     * @var \Illuminate\Support\Collection<int, \App\Models\Plan> $planes
     * @var list<string> $metodos
     */
    $navActivo = 'superadmin-restaurantes';
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $zona = $restaurante->zona_horaria;
    $suscripcion = $restaurante->suscripcionActual;
    $estado = $restaurante->estadoSuscripcion();
    $dias = $restaurante->diasParaVencer();
    $url = fn (string $accion, array $extra = []): string => route('panel.superadmin.restaurantes.'.$accion, [$restaurante, ...$extra]);

    $textoDias = match (true) {
        $dias === null => null,
        $estado === 'cancelada' => null,
        $dias > 1 => "Faltan {$dias} días",
        $dias === 1 => 'Vence mañana',
        $dias === 0 => 'Vence hoy',
        $restaurante->enGracia() => 'Venció: '.($restaurante->diasDeGracia() === 0 ? 'hoy es el último día de gracia' : ($restaurante->diasDeGracia() === 1 ? "queda 1 día de gracia" : "quedan {$restaurante->diasDeGracia()} días de gracia")),
        default => 'Venció: pasó el periodo de gracia',
    };

    // Lo que se propone al registrar un pago: un mes desde que termina lo ya pagado.
    $hoy = Carbon::parse($restaurante->fechaHoy(), 'UTC');
    $desde = $suscripcion && $suscripcion->vence_el->toDateString() >= $hoy->toDateString()
        ? Carbon::parse($suscripcion->vence_el->toDateString(), 'UTC')->addDay()
        : $hoy->copy();
    $hasta = $desde->copy()->addMonthNoOverflow()->subDay();
    $montoSugerido = $suscripcion && (float) $suscripcion->precio_pactado > 0
        ? $suscripcion->precio_pactado
        : ($suscripcion?->plan?->precio_mensual ?? $restaurante->plan?->precio_mensual);

    $columnasPagos = [
        ['label' => 'Pago'],
        ['label' => 'Periodo', 'class' => 'w-52'],
        ['label' => 'Monto', 'align' => 'right', 'class' => 'w-28'],
        ['label' => '', 'class' => 'w-24'],
    ];
    $columnasSuscripciones = [
        ['label' => 'Plan'],
        ['label' => 'Estado', 'class' => 'w-32'],
        ['label' => 'Inicio', 'class' => 'w-28'],
        ['label' => 'Vence', 'class' => 'w-28'],
        ['label' => 'Precio', 'align' => 'right', 'class' => 'w-28'],
    ];
@endphp

@section('content')
    <a href="{{ route('panel.superadmin.restaurantes.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-icon name="chevron-left" size="14" /> Restaurantes</a>

    <div class="mt-2 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-title tracking-tight text-ink">{{ $restaurante->nombre }}</h1>
                <x-badge :status="$restaurante->estaSuspendido() ? 'suspendido' : 'operando'" />
            </div>
            <p class="mt-1 text-sm text-muted">
                {{ $restaurante->ciudad }}@if ($restaurante->rubro) · {{ $restaurante->rubro->nombre }}@endif · /{{ $restaurante->slug }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-button icon="external" :href="$restaurante->urlVitrina()" target="_blank" rel="noopener">Ver vitrina</x-button>
            @if ($restaurante->estaSuspendido())
                <x-button variant="primary" data-modal-open="modal-reactivar">Reactivar local</x-button>
            @else
                <x-button variant="danger" data-modal-open="modal-suspender">Suspender local</x-button>
            @endif
        </div>
    </div>

    @unless ($restaurante->operativo())
        <p class="mt-4 flex items-start gap-2.5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="status">
            <x-icon name="alert" class="mt-0.5" />
            <span>Este local no está operando: su vitrina se muestra como no disponible y su panel queda para ver y terminar pedidos en curso.</span>
        </p>
    @endunless

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="flex min-w-0 flex-col gap-4">
            <x-card title="Suscripción" subtitle="Plan, estado y vencimiento actuales.">
                @if ($suscripcion === null)
                    <div class="flex flex-col items-start gap-3 rounded-control border border-dashed border-control px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-muted">Este local no tiene suscripción. Al asignarle un plan empieza una prueba de {{ Suscripcion::DIAS_PRUEBA }} días.</p>
                        <x-button size="sm" data-modal-open="modal-plan">Asignar plan</x-button>
                    </div>
                @else
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-[0.8125rem] text-muted">Plan</dt>
                            <dd class="mt-1 font-medium text-ink">{{ $suscripcion->plan?->nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-muted">Estado</dt>
                            <dd class="mt-1"><x-badge :status="$estado" /></dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-muted">Precio pactado</dt>
                            <dd class="num mt-1 font-medium text-ink">{{ $soles($suscripcion->precio_pactado) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-muted">Vence el</dt>
                            <dd class="num mt-1 font-medium text-ink">{{ $suscripcion->vence_el->format('d/m/Y') }}</dd>
                            @if ($textoDias)
                                <dd class="mt-0.5 text-xs text-muted">{{ $textoDias }}</dd>
                            @endif
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-muted">Desde</dt>
                            <dd class="num mt-1 text-ink">{{ $suscripcion->inicia_el->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-muted">Pedidos del mes</dt>
                            <dd class="num mt-1 text-ink">{{ $pedidosMes }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-rule pt-4">
                        <x-button variant="primary" icon="wallet" data-modal-open="modal-pago">Registrar pago</x-button>
                        <x-button data-modal-open="modal-plan">Cambiar plan</x-button>
                        @if ($suscripcion->estado !== 'cancelada')
                            <x-button variant="ghost" class="text-danger-ink!" data-modal-open="modal-cancelar">Cancelar suscripción</x-button>
                        @endif
                    </div>
                @endif
            </x-card>

            <x-card title="Pagos" subtitle="Nunca se borran: un pago equivocado se anula y queda a la vista." :padded="false">
                @if ($pagos->isEmpty())
                    <p class="mx-4 mb-4 rounded-control border border-dashed border-control px-4 py-5 text-sm text-muted sm:mx-5 sm:mb-5">Todavía no se ha registrado ningún pago.</p>
                @else
                    <x-table :columns="$columnasPagos" caption="Pagos del local">
                        @foreach ($pagos as $pago)
                            <tr>
                                <td data-cell="lead">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-ink">{{ $pago->metodo }}</span>
                                        @if ($pago->estaAnulado())
                                            <x-badge status="anulado" />
                                        @endif
                                    </span>
                                    <span class="block text-[0.8125rem] text-muted">
                                        {{ $pago->pagado_at?->timezone($zona)->format('d/m/Y') }}@if ($pago->referencia) · Ref. {{ $pago->referencia }}@endif
                                        @if ($pago->registradoPor) · {{ $pago->registradoPor->name }}@endif
                                    </span>
                                    @if ($pago->estaAnulado())
                                        <span class="block text-[0.8125rem] text-muted">Motivo: {{ $pago->motivo_anulacion }}</span>
                                    @endif
                                </td>
                                <td data-cell="meta" data-label="Periodo" class="num text-muted">{{ $pago->periodo_desde->format('d/m/Y') }} al {{ $pago->periodo_hasta->format('d/m/Y') }}</td>
                                <td data-cell="end" @class(['num whitespace-nowrap text-right font-medium', 'text-ink' => ! $pago->estaAnulado(), 'text-faint line-through' => $pago->estaAnulado()])>{{ $soles($pago->monto) }}</td>
                                <td data-cell="action" class="text-right">
                                    @if ($pagoAnulable?->is($pago))
                                        <x-button size="sm" variant="ghost" class="text-danger-ink!" data-modal-open="modal-anular" aria-label="Anular el pago de {{ $soles($pago->monto) }}">Anular</x-button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </x-card>

            <x-card title="Historial" subtitle="Altas, planes, suspensiones y pagos de este local." :padded="false">
                @if ($suscripciones->isNotEmpty())
                    <x-table :columns="$columnasSuscripciones" caption="Suscripciones del local" :stack="false">
                        @foreach ($suscripciones as $fila)
                            <tr>
                                <td class="text-ink">{{ $fila->plan?->nombre ?? '—' }}</td>
                                <td><x-badge :status="$fila->estadoEl($restaurante->fechaHoy())" /></td>
                                <td class="num text-muted">{{ $fila->inicia_el->format('d/m/Y') }}</td>
                                <td class="num text-muted">{{ $fila->vence_el->format('d/m/Y') }}</td>
                                <td class="num text-right text-ink">{{ $soles($fila->precio_pactado) }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif

                @if ($historial->isEmpty())
                    <p class="mx-4 my-4 rounded-control border border-dashed border-control px-4 py-5 text-sm text-muted sm:mx-5 sm:mb-5">Todavía no hay cambios registrados.</p>
                @else
                    <ul class="divide-y divide-rule border-t border-rule">
                        @foreach ($historial as $entrada)
                            <li class="flex flex-col gap-0.5 px-4 py-3 sm:flex-row sm:items-baseline sm:gap-4 sm:px-5">
                                <span class="num shrink-0 text-[0.8125rem] text-muted sm:w-32">{{ $entrada->created_at?->timezone($zona)->format('d/m/Y H:i') }}</span>
                                <span class="min-w-0 flex-1 text-sm text-ink">{{ $entrada->descripcion }}</span>
                                <span class="shrink-0 text-[0.8125rem] text-muted">{{ $entrada->user?->name ?? 'Sistema' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <div class="flex min-w-0 flex-col gap-4">
            <x-card title="Datos del local">
                <form method="POST" action="{{ $url('update') }}" data-ajax-form data-recargar novalidate class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    @include('panel.configuracion._resumen', ['claves' => []])
                    <div>
                        <p class="mb-2 text-sm font-medium text-ink">Dirección de la vitrina</p>
                        <p class="flex min-h-11 items-center gap-2 rounded-control bg-raised px-3 text-sm text-muted lg:min-h-10"><x-icon name="lock" class="text-faint" /> <span class="min-w-0 truncate">/{{ $restaurante->slug }}</span></p>
                    </div>
                    <x-campo label="Nombre" for="datos-nombre" error="nombre">
                        <x-input name="nombre" id="datos-nombre" :value="$restaurante->nombre" maxlength="120" required autocomplete="off" />
                    </x-campo>
                    <x-campo label="Rubro" for="datos-rubro" error="rubro_id" optional>
                        <x-input as="select" name="rubro_id" id="datos-rubro">
                            <option value="">Sin rubro</option>
                            @foreach ($rubros as $rubro)
                                <option value="{{ $rubro->id }}" @selected($restaurante->rubro_id === $rubro->id)>{{ $rubro->nombre }}</option>
                            @endforeach
                        </x-input>
                    </x-campo>
                    <x-campo label="Ciudad" for="datos-ciudad" error="ciudad">
                        <x-input name="ciudad" id="datos-ciudad" :value="$restaurante->ciudad" maxlength="80" required autocomplete="off" />
                    </x-campo>
                    <x-campo label="Celular" for="datos-telefono" error="telefono" optional>
                        <x-input name="telefono" id="datos-telefono" type="tel" inputmode="numeric" :value="$restaurante->telefono" maxlength="15" autocomplete="off" />
                    </x-campo>
                    <x-campo label="Dirección física" for="datos-direccion" error="direccion" optional>
                        <x-input name="direccion" id="datos-direccion" :value="$restaurante->direccion" maxlength="255" autocomplete="off" />
                    </x-campo>
                    @include('panel.superadmin._enviar', ['texto' => 'Guardar datos'])
                </form>
            </x-card>

            <x-card title="Notas internas" subtitle="Solo las ve el equipo de FOORA, nunca el local.">
                <form method="POST" action="{{ $url('notas') }}" data-ajax-form data-recargar novalidate class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    @include('panel.configuracion._resumen', ['claves' => []])
                    <x-campo label="Notas" for="notas-internas" error="notas_internas">
                        <x-input as="textarea" name="notas_internas" id="notas-internas" :value="$restaurante->notas_internas" maxlength="5000" rows="4" />
                    </x-campo>
                    @include('panel.superadmin._enviar', ['texto' => 'Guardar notas'])
                </form>
            </x-card>

            <x-card title="Personal" :padded="false">
                @if ($personal->isEmpty())
                    <p class="mx-4 mb-4 rounded-control border border-dashed border-control px-4 py-5 text-sm text-muted sm:mx-5 sm:mb-5">Este local no tiene usuarios.</p>
                @else
                    <ul class="divide-y divide-rule border-t border-rule">
                        @foreach ($personal as $persona)
                            <li class="px-4 py-3 sm:px-5">
                                <p class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 truncate text-sm font-medium text-ink">{{ $persona->name }}</span>
                                    <span class="shrink-0 text-xs text-muted">{{ $persona->rolEtiqueta() }}@unless ($persona->activo) · desactivado @endunless</span>
                                </p>
                                <p class="truncate text-xs text-muted">{{ $persona->email }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection

@push('modals')
    {{-- Registrar pago --}}
    @if ($suscripcion)
        <x-modal id="modal-pago" title="Registrar pago" description="Extiende el vencimiento hasta el fin del periodo y activa la suscripción.">
            <form method="POST" action="{{ $url('pagos.store') }}" data-ajax-form data-recargar novalidate class="flex flex-col gap-4">
                @csrf
                @include('panel.configuracion._resumen', ['claves' => []])
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-campo label="Monto" for="pago-monto" error="monto" hint="En soles.">
                        <x-input name="monto" id="pago-monto" inputmode="decimal" :value="$montoSugerido !== null ? number_format((float) $montoSugerido, 2, '.', '') : ''" maxlength="9" class="num" required :hint="true" autocomplete="off" />
                    </x-campo>
                    <x-campo label="Método" for="pago-metodo" error="metodo">
                        <x-input as="select" name="metodo" id="pago-metodo" required>
                            @foreach ($metodos as $metodo)
                                <option value="{{ $metodo }}">{{ $metodo }}</option>
                            @endforeach
                        </x-input>
                    </x-campo>
                    <x-campo label="Referencia" for="pago-referencia" error="referencia" optional hint="Código de operación. No se acepta dos veces." class="sm:col-span-2">
                        <x-input name="referencia" id="pago-referencia" maxlength="80" :hint="true" autocomplete="off" />
                    </x-campo>
                    <x-campo label="Periodo desde" for="pago-desde" error="periodo_desde">
                        <x-input name="periodo_desde" id="pago-desde" type="date" :value="$desde->toDateString()" required />
                    </x-campo>
                    <x-campo label="Periodo hasta" for="pago-hasta" error="periodo_hasta">
                        <x-input name="periodo_hasta" id="pago-hasta" type="date" :value="$hasta->toDateString()" required />
                    </x-campo>
                </div>
                @include('panel.superadmin._enviar', ['texto' => 'Registrar pago', 'ocupado' => 'Registrando…', 'cancelable' => true])
            </form>
        </x-modal>
    @endif

    {{-- Cambiar o asignar plan --}}
    <x-modal id="modal-plan" :title="$suscripcion ? 'Cambiar plan' : 'Asignar plan'" size="sm" description="El vencimiento y el estado no cambian.">
        <form method="POST" action="{{ $url('plan') }}" data-ajax-form data-recargar novalidate class="flex flex-col gap-4">
            @csrf
            @method('PUT')
            @include('panel.configuracion._resumen', ['claves' => []])
            <x-campo label="Plan" for="plan-id" error="plan_id">
                <x-input as="select" name="plan_id" id="plan-id" required data-plan-select>
                    @foreach ($planes as $plan)
                        <option value="{{ $plan->id }}" data-precio="{{ number_format((float) $plan->precio_mensual, 2, '.', '') }}" @selected(($suscripcion?->plan_id ?? $restaurante->plan_id) === $plan->id)>{{ $plan->nombre }}</option>
                    @endforeach
                </x-input>
            </x-campo>
            <x-campo label="Precio pactado al mes" for="plan-precio" error="precio_pactado" hint="En soles. Al elegir un plan se propone su precio de lista.">
                <x-input name="precio_pactado" id="plan-precio" inputmode="decimal" :value="number_format((float) ($suscripcion?->precio_pactado ?? $planes->firstWhere('id', $restaurante->plan_id)?->precio_mensual ?? 0), 2, '.', '')" maxlength="7" class="num" required :hint="true" data-plan-precio autocomplete="off" />
            </x-campo>
            @include('panel.superadmin._enviar', ['texto' => 'Guardar plan', 'cancelable' => true])
        </form>
    </x-modal>

    {{-- Acciones con motivo --}}
    @foreach (array_filter([
        ! $restaurante->estaSuspendido() ? ['modal-suspender', 'Suspender local', 'Su vitrina dejará de estar disponible. Su personal podrá entrar para ver y terminar los pedidos en curso.', 'suspender', 'Suspender', 'Suspendiendo…', true] : null,
        $restaurante->estaSuspendido() ? ['modal-reactivar', 'Reactivar local', 'Su vitrina vuelve a estar disponible, si su suscripción está al día.', 'reactivar', 'Reactivar', 'Reactivando…', false] : null,
        $suscripcion && $suscripcion->estado !== 'cancelada' ? ['modal-cancelar', 'Cancelar suscripción', 'La vitrina deja de estar disponible de inmediato, sin periodo de gracia. Se revierte registrando un pago.', 'cancelar', 'Cancelar suscripción', 'Cancelando…', true] : null,
    ]) as [$modalId, $titulo, $explicacion, $accion, $boton, $ocupado, $peligro])
        <x-modal :id="$modalId" :title="$titulo" size="sm" :description="$explicacion">
            <form method="POST" action="{{ $url($accion) }}" data-ajax-form data-recargar novalidate class="flex flex-col gap-4">
                @csrf
                @method('PATCH')
                @include('panel.configuracion._resumen', ['claves' => []])
                <x-campo label="Motivo" :for="$modalId.'-motivo'" error="motivo" hint="Queda en la auditoría.">
                    <x-input as="textarea" name="motivo" :id="$modalId.'-motivo'" maxlength="200" rows="2" required :hint="true" />
                </x-campo>
                @include('panel.superadmin._enviar', ['texto' => $boton, 'ocupado' => $ocupado, 'peligro' => $peligro, 'cancelable' => true])
            </form>
        </x-modal>
    @endforeach

    @if ($pagoAnulable)
        <x-modal id="modal-anular" title="Anular pago" size="sm" description="El pago queda a la vista como anulado. El vencimiento y el estado vuelven a como estaban antes de él.">
            <form method="POST" action="{{ $url('pagos.anular', ['pago' => $pagoAnulable->id]) }}" data-ajax-form data-recargar novalidate class="flex flex-col gap-4">
                @csrf
                @method('PATCH')
                @include('panel.configuracion._resumen', ['claves' => []])
                <p class="rounded-control bg-raised px-3 py-2.5 text-sm">{{ $pagoAnulable->metodo }} · <span class="num font-medium">{{ $soles($pagoAnulable->monto) }}</span> · <span class="num">{{ $pagoAnulable->periodo_desde->format('d/m/Y') }} al {{ $pagoAnulable->periodo_hasta->format('d/m/Y') }}</span></p>
                <x-campo label="Motivo" for="anular-motivo" error="motivo" hint="Queda en la auditoría.">
                    <x-input as="textarea" name="motivo" id="anular-motivo" maxlength="200" rows="2" required :hint="true" />
                </x-campo>
                @include('panel.superadmin._enviar', ['texto' => 'Anular pago', 'ocupado' => 'Anulando…', 'peligro' => true, 'cancelable' => true])
            </form>
        </x-modal>
    @endif
@endpush
