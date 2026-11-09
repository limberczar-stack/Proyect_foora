@extends('layouts.panel')

@section('title', 'Inicio')

@php
    /**
     * Inicio del local para admin y cajero. Las reglas de cada cifra están en App\Services\ResumenInicio.
     *
     * @var string $primerNombre
     * @var string $fecha
     * @var string $zonaHoraria
     * @var array{pedidosHoy: int, ventasHoy: float, entregadosHoy: int, activos: int, nuevos: int} $tarjetas
     * @var bool $tienePedidos
     * @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pedido> $recientes
     * @var list<array{prioridad: string, titulo: string, mensaje: ?string, hace: string, accion: array{etiqueta: string, url: string}|null}> $alertas
     * @var int $alertasTotal
     * @var int $alertasUrgentes
     * @var array{pasos: array<string, array{titulo: string, detalle: string, completo: bool, accion: array{etiqueta: string, url: string}|null}>, completos: int, total: int, enlace: string}|null $primerosPasos
     */
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $hoy = now($zonaHoraria);
    $urlPedidos = route('panel.pedidos.index');

    $columnasPedidos = [
        ['label' => 'Pedido'],
        ['label' => 'Estado'],
        ['label' => 'Total', 'align' => 'right', 'class' => 'w-24'],
        ['label' => 'Acción', 'align' => 'right', 'class' => 'w-28'],
    ];
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Hola, {{ $primerNombre }}</h1>
            <p class="mt-1 text-sm text-muted">
                {{ $fecha }}@if ($alertasUrgentes > 0) · <span class="font-medium text-danger-ink">{{ $alertasUrgentes }} {{ $alertasUrgentes === 1 ? 'alerta urgente' : 'alertas urgentes' }} por atender</span>@endif
            </p>
        </div>
        @if ($tarjetas['nuevos'] > 0)
            <x-button variant="primary" icon="receipt" :href="route('panel.pedidos.index', ['estado' => 'nuevo'])" class="sm:flex-none">Atender {{ $tarjetas['nuevos'] }} {{ $tarjetas['nuevos'] === 1 ? 'nuevo' : 'nuevos' }}</x-button>
        @endif
    </div>

    {{-- Primeros pasos: solo el admin puede completarlos, y desaparece al terminar --}}
    @if ($primerosPasos)
        <x-card
            title="Primeros pasos"
            :subtitle="$primerosPasos['completos'].' de '.$primerosPasos['total'].' completos'"
            class="mt-6"
            data-primeros-pasos
        >
            <div
                class="h-1.5 overflow-hidden rounded-full bg-brand-100"
                role="progressbar"
                aria-label="Avance de los primeros pasos"
                aria-valuemin="0"
                aria-valuemax="{{ $primerosPasos['total'] }}"
                aria-valuenow="{{ $primerosPasos['completos'] }}"
            >
                <div class="h-full rounded-full bg-brand-700" style="width: {{ round($primerosPasos['completos'] / $primerosPasos['total'] * 100, 1) }}%"></div>
            </div>

            <ul class="mt-5 grid grid-cols-1 gap-x-8 gap-y-5 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($primerosPasos['pasos'] as $clave => $paso)
                    <li class="flex items-start gap-3" data-paso="{{ $clave }}" data-paso-completo="{{ $paso['completo'] ? '1' : '0' }}">
                        @if ($paso['completo'])
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-success-bg text-success-ink">
                                <x-icon name="check" size="12" />
                                <span class="sr-only">Completo:</span>
                            </span>
                        @else
                            <span class="mt-0.5 size-5 shrink-0 rounded-full border-[1.5px] border-control">
                                <span class="sr-only">Pendiente:</span>
                            </span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p @class(['font-medium', 'text-ink' => ! $paso['completo'], 'text-muted' => $paso['completo']])>{{ $paso['titulo'] }}</p>
                            <p class="mt-0.5 text-[0.8125rem] text-muted">{{ $paso['detalle'] }}</p>

                            @if ($clave === 'enlace')
                                <p id="enlace-primeros-pasos" class="num mt-2 truncate text-[0.8125rem] text-ink" title="{{ $primerosPasos['enlace'] }}">{{ $primerosPasos['enlace'] }}</p>
                                <button
                                    type="button"
                                    class="press mt-2 inline-flex h-10 items-center gap-1.5 rounded-control border border-control bg-surface px-3 text-[0.8125rem] font-medium text-ink hover:bg-raised lg:h-8"
                                    data-copiar="{{ $primerosPasos['enlace'] }}"
                                    data-copiar-respaldo="enlace-primeros-pasos"
                                >
                                    <x-icon name="copy" size="14" /><span data-copiar-texto>Copiar enlace</span>
                                </button>
                            @elseif (! $paso['completo'] && $paso['accion'])
                                <x-button size="sm" :href="$paso['accion']['url']" class="mt-2">{{ $paso['accion']['etiqueta'] }}</x-button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    {{-- Resumen del día. Un cero solo se muestra cuando el local ya recibe pedidos; antes no significa nada. --}}
    <section aria-labelledby="resumen-titulo" class="mt-6">
        <h2 id="resumen-titulo" class="sr-only">Resumen de hoy</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @if ($tienePedidos)
                <x-stat label="Pedidos de hoy" :value="$tarjetas['pedidosHoy']" caption="Sin contar cancelados" :href="$urlPedidos" data-tarjeta="pedidos-hoy" />
                <x-stat
                    label="Ventas de hoy"
                    prefix="S/"
                    :value="number_format($tarjetas['ventasHoy'], 2)"
                    :caption="match ($tarjetas['entregadosHoy']) { 0 => 'Aún sin entregas hoy', 1 => 'De 1 entrega', default => 'De '.$tarjetas['entregadosHoy'].' entregas' }"
                    :href="route('panel.pedidos.index', ['estado' => 'entregado'])"
                    data-tarjeta="ventas-hoy"
                />
                <x-stat label="Pedidos activos" :value="$tarjetas['activos']" caption="Aún sin entregar" :href="$urlPedidos" data-tarjeta="activos" />
                <x-stat
                    label="Nuevos sin atender"
                    :value="$tarjetas['nuevos']"
                    :caption="$tarjetas['nuevos'] > 0 ? 'Esperan que los aceptes' : 'Ninguno esperando'"
                    :caption-tone="$tarjetas['nuevos'] > 0 ? 'negative' : 'neutral'"
                    :href="route('panel.pedidos.index', ['estado' => 'nuevo'])"
                    data-tarjeta="nuevos"
                />
            @else
                <x-stat label="Pedidos de hoy" empty caption="Aquí verás cuántos recibes al día" data-tarjeta="pedidos-hoy" />
                <x-stat label="Ventas de hoy" empty caption="Se suman al entregar cada pedido" data-tarjeta="ventas-hoy" />
                <x-stat label="Pedidos activos" empty caption="Los que estás atendiendo ahora" data-tarjeta="activos" />
                <x-stat label="Nuevos sin atender" empty caption="Los que esperan que los aceptes" data-tarjeta="nuevos" />
            @endif
        </div>
    </section>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        {{-- Alertas: lo sensible al tiempo va primero en el celular --}}
        <x-card
            title="Alertas pendientes"
            :subtitle="$alertasTotal > count($alertas) ? 'Las '.count($alertas).' más importantes de '.$alertasTotal : ($alertasTotal > 0 ? $alertasTotal.' sin resolver' : null)"
            :padded="$alertas === []"
            :empty="$alertas === [] ? 'Sin alertas por ahora. Aquí verás los pagos por validar y los pedidos nuevos.' : null"
            class="min-w-0 lg:order-last"
            data-alertas
        >
            <ul class="divide-y divide-rule border-t border-rule">
                @foreach ($alertas as $alerta)
                    <li class="px-4 py-3.5 sm:px-5" data-alerta>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-ink">{{ $alerta['titulo'] }}</p>
                                <p class="num mt-0.5 text-[0.8125rem] text-muted">@if ($alerta['mensaje']){{ rtrim($alerta['mensaje'], '.') }} · @endif{{ $alerta['hace'] }}</p>
                            </div>
                            <x-badge :status="$alerta['prioridad']" />
                        </div>
                        @if ($alerta['accion'])
                            <x-button
                                size="sm"
                                :variant="$alerta['prioridad'] === 'urgente' ? 'secondary' : 'ghost'"
                                :href="$alerta['accion']['url']"
                                @class(['mt-2', '-ml-3' => $alerta['prioridad'] !== 'urgente'])
                            >{{ $alerta['accion']['etiqueta'] }}</x-button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>

        {{-- Pedidos recientes --}}
        <x-card
            title="Pedidos recientes"
            :padded="! $tienePedidos"
            :empty="$tienePedidos ? null : 'Todavía no recibes pedidos. Cuando tus clientes pidan desde tu vitrina, los verás aquí.'"
            class="min-w-0"
            data-recientes
        >
            @if ($tienePedidos)
                <x-slot:actions>
                    <x-button :href="$urlPedidos" variant="ghost" size="sm">Ver todos</x-button>
                </x-slot:actions>
            @endif

            <x-slot:empty-action>
                <x-button size="sm" icon="external" :href="auth()->user()->restaurante->urlVitrina()" target="_blank" rel="noopener">Abrir mi vitrina</x-button>
            </x-slot:empty-action>

            <x-table :columns="$columnasPedidos" caption="Últimos pedidos del local">
                @foreach ($recientes as $pedido)
                    @php
                        $creado = $pedido->created_at->timezone($zonaHoraria);
                        $hora = $creado->isSameDay($hoy) ? $creado->format('H:i') : $creado->locale('es')->isoFormat('D MMM, HH:mm');
                    @endphp
                    <tr class="hover:bg-raised/60" data-reciente="{{ $pedido->numero }}">
                        <td data-cell="lead">
                            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                <span class="num font-semibold text-ink">#{{ $pedido->numero }}</span>
                                <span class="text-ink">{{ $pedido->nombre_cliente }}</span>
                                <x-badge :status="$pedido->estado" class="sm:hidden" />
                            </p>
                            <p class="num mt-0.5 text-[0.8125rem] text-muted">
                                {{ $pedido->tipo === 'delivery' ? 'Delivery' : 'Recojo' }} · {{ $hora }} · {{ $pedido->metodoPago?->nombre ?? 'Sin método' }}
                                @if ($pedido->estado_pago === 'por_verificar')
                                    <span class="font-medium text-warning-ink">por verificar</span>
                                @elseif ($pedido->estado_pago === 'pagado')
                                    <span class="text-success-ink">pagado</span>
                                @elseif ($pedido->estado_pago === 'rechazado')
                                    <span class="font-medium text-danger-ink">rechazado</span>
                                @endif
                            </p>
                        </td>
                        <td data-cell="hide-sm">
                            <x-badge :status="$pedido->estado" />
                        </td>
                        <td data-cell="end" class="num whitespace-nowrap text-right font-medium text-ink">
                            {{ $soles($pedido->total) }}
                        </td>
                        <td data-cell="action" class="text-right">
                            <x-button size="sm" variant="ghost" :href="route('panel.pedidos.show', $pedido)" class="max-sm:-ml-3">Ver detalle</x-button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>
@endsection
