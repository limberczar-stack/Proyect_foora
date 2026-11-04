@extends('layouts.panel')

@section('title', 'Inicio')

@php
    /*
     * DATOS ESTÁTICOS DE MUESTRA (reemplazar por datos reales cuando existan los controladores).
     * Los totales, conteos y badges se calculan desde estas filas para que siempre cuadren.
     */
    $soles = fn (float $monto): string => "S/\u{00A0}".number_format($monto, 2);

    $fechaHoy = 'Miércoles 4 de noviembre';
    $horaActual = '19:42';

    /** @var list<array{numero: int, cliente: string, tipo: string, mesa: ?string, hora: string, hace: string, metodo: string, estado_pago: string, estado: string, total: float}> $pedidos */
    $pedidos = [
        ['numero' => 1262, 'cliente' => 'Lucía Torres', 'tipo' => 'delivery', 'mesa' => null, 'hora' => '19:38', 'hace' => 'hace 4 min', 'metodo' => 'Yape', 'estado_pago' => 'por_verificar', 'estado' => 'nuevo', 'total' => 54.00],
        ['numero' => 1261, 'cliente' => 'Carlos Ramírez', 'tipo' => 'salon', 'mesa' => 'M4', 'hora' => '19:34', 'hace' => 'hace 8 min', 'metodo' => 'Efectivo', 'estado_pago' => 'pendiente', 'estado' => 'nuevo', 'total' => 38.50],
        ['numero' => 1260, 'cliente' => 'Jorge Paredes', 'tipo' => 'recojo', 'mesa' => null, 'hora' => '19:30', 'hace' => 'hace 12 min', 'metodo' => 'Plin', 'estado_pago' => 'pagado', 'estado' => 'nuevo', 'total' => 72.00],
        ['numero' => 1259, 'cliente' => 'Ana Huerta', 'tipo' => 'delivery', 'mesa' => null, 'hora' => '19:22', 'hace' => 'hace 20 min', 'metodo' => 'Tarjeta', 'estado_pago' => 'pagado', 'estado' => 'en_preparacion', 'total' => 61.00],
        ['numero' => 1258, 'cliente' => 'Mesa 7', 'tipo' => 'salon', 'mesa' => 'M7', 'hora' => '19:15', 'hace' => 'hace 27 min', 'metodo' => 'Efectivo', 'estado_pago' => 'pendiente', 'estado' => 'en_preparacion', 'total' => 96.00],
        ['numero' => 1257, 'cliente' => 'Pedro Soto', 'tipo' => 'recojo', 'mesa' => null, 'hora' => '19:05', 'hace' => 'hace 37 min', 'metodo' => 'Yape', 'estado_pago' => 'pagado', 'estado' => 'listo', 'total' => 27.50],
        ['numero' => 1256, 'cliente' => 'Milagros Vela', 'tipo' => 'delivery', 'mesa' => null, 'hora' => '18:58', 'hace' => 'hace 44 min', 'metodo' => 'Efectivo', 'estado_pago' => 'pendiente', 'estado' => 'en_camino', 'total' => 45.00],
    ];

    $tiposPedido = ['delivery' => 'Delivery', 'recojo' => 'Recojo', 'salon' => 'Salón'];

    $tabsPedidos = [
        'nuevo' => ['label' => 'Nuevos'],
        'en_preparacion' => ['label' => 'En preparación'],
        'listo' => ['label' => 'Listos'],
        'en_camino' => ['label' => 'En camino'],
        'todos' => ['label' => 'Todos'],
    ];
    foreach ($tabsPedidos as $clave => $tab) {
        $tabsPedidos[$clave]['count'] = $clave === 'todos'
            ? count($pedidos)
            : count(array_filter($pedidos, fn ($p) => $p['estado'] === $clave));
    }

    /** @var list<array{prioridad: string, titulo: string, detalle: string, hace: string, accion: string}> $alertas */
    $alertas = [
        ['prioridad' => 'urgente', 'titulo' => 'Pago Yape por validar', 'detalle' => 'Pedido #1262 · Lucía Torres · '.$soles(54.00), 'hace' => 'hace 4 min', 'accion' => 'Validar pago'],
        ['prioridad' => 'urgente', 'titulo' => 'Pedido #1260 sin respuesta', 'detalle' => 'Recojo de Jorge Paredes, pagado con Plin', 'hace' => 'hace 12 min', 'accion' => 'Responder'],
        ['prioridad' => 'media', 'titulo' => 'Reserva por vencer', 'detalle' => 'Marco Ríos, 19:30 · la tolerancia de 15 min vence a las 19:45', 'hace' => 'hace 12 min', 'accion' => 'Ver reserva'],
        ['prioridad' => 'informativa', 'titulo' => 'Menú incompleto', 'detalle' => '3 productos de la carta no tienen foto', 'hace' => 'hoy', 'accion' => 'Completar'],
    ];
    $alertasUrgentes = count(array_filter($alertas, fn ($a) => $a['prioridad'] === 'urgente'));
    $alertasRecientes = $alertas;

    /** @var list<array{hora: string, cliente: string, personas: int, mesa: ?string, estado: string, nota: ?string}> $reservasProximas */
    $reservasProximas = [
        ['hora' => '20:00', 'cliente' => 'Familia Rojas', 'personas' => 6, 'mesa' => 'M10', 'estado' => 'confirmada', 'nota' => null],
        ['hora' => '20:15', 'cliente' => 'Daniel Cárdenas', 'personas' => 2, 'mesa' => 'T2', 'estado' => 'pendiente', 'nota' => null],
        ['hora' => '20:30', 'cliente' => 'Sofía Mendoza', 'personas' => 4, 'mesa' => 'M3', 'estado' => 'confirmada', 'nota' => 'Cumpleaños'],
        ['hora' => '21:00', 'cliente' => 'Grupo Castillo', 'personas' => 8, 'mesa' => null, 'estado' => 'pendiente', 'nota' => 'Sin mesa asignada'],
    ];
    $reservasHoy = 12;
    $reservasPorConfirmar = count(array_filter($reservasProximas, fn ($r) => $r['estado'] === 'pendiente'));

    /** @var array<string, list<array{codigo: string, capacidad: int, estado: string, detalle: ?string}>> $areas */
    $areas = [
        'Salón principal' => [
            ['codigo' => 'M1', 'capacidad' => 4, 'estado' => 'ocupada', 'detalle' => '35 min'],
            ['codigo' => 'M2', 'capacidad' => 2, 'estado' => 'disponible', 'detalle' => null],
            ['codigo' => 'M3', 'capacidad' => 4, 'estado' => 'reservada', 'detalle' => '20:30'],
            ['codigo' => 'M4', 'capacidad' => 4, 'estado' => 'ocupada', 'detalle' => '52 min'],
            ['codigo' => 'M5', 'capacidad' => 2, 'estado' => 'ocupada', 'detalle' => '18 min'],
            ['codigo' => 'M6', 'capacidad' => 6, 'estado' => 'disponible', 'detalle' => null],
            ['codigo' => 'M7', 'capacidad' => 6, 'estado' => 'ocupada', 'detalle' => '41 min'],
            ['codigo' => 'M8', 'capacidad' => 4, 'estado' => 'ocupada', 'detalle' => '1 h 10 min'],
            ['codigo' => 'M9', 'capacidad' => 4, 'estado' => 'fuera_servicio', 'detalle' => null],
            ['codigo' => 'M10', 'capacidad' => 8, 'estado' => 'reservada', 'detalle' => '20:00'],
        ],
        'Terraza' => [
            ['codigo' => 'T1', 'capacidad' => 2, 'estado' => 'ocupada', 'detalle' => '25 min'],
            ['codigo' => 'T2', 'capacidad' => 2, 'estado' => 'reservada', 'detalle' => '20:15'],
            ['codigo' => 'T3', 'capacidad' => 4, 'estado' => 'ocupada', 'detalle' => '12 min'],
            ['codigo' => 'T4', 'capacidad' => 4, 'estado' => 'ocupada', 'detalle' => '30 min'],
            ['codigo' => 'T5', 'capacidad' => 4, 'estado' => 'disponible', 'detalle' => null],
            ['codigo' => 'T6', 'capacidad' => 2, 'estado' => 'ocupada', 'detalle' => '8 min'],
        ],
    ];
    $todasLasMesas = array_merge(...array_values($areas));
    $mesasPorEstado = array_count_values(array_column($todasLasMesas, 'estado'));
    $mesasTotal = count($todasLasMesas);
    $mesasOcupadas = $mesasPorEstado['ocupada'] ?? 0;

    $estadosMesa = [
        'ocupada' => ['label' => 'Ocupadas', 'tile' => 'bg-warning-bg text-warning-ink', 'dot' => 'bg-warning-dot'],
        'reservada' => ['label' => 'Reservadas', 'tile' => 'bg-brand-100 text-brand-800', 'dot' => 'bg-brand-700'],
        'disponible' => ['label' => 'Disponibles', 'tile' => 'bg-success-bg text-success-ink', 'dot' => 'bg-success-dot'],
        'fuera_servicio' => ['label' => 'Fuera de servicio', 'tile' => 'border border-dashed border-control bg-surface text-neutral-ink', 'dot' => 'bg-danger-dot'],
    ];

    /** @var list<array{nombre: string, tipo: string, usos: int, limite: ?int, hasta: string}> $promociones */
    $promociones = [
        ['nombre' => '2x1 en Pollo a la Brasa', 'tipo' => 'Martes y miércoles', 'usos' => 46, 'limite' => 100, 'hasta' => '30 nov'],
        ['nombre' => 'Delivery gratis desde S/ 60', 'tipo' => 'Solo delivery', 'usos' => 18, 'limite' => null, 'hasta' => '15 dic'],
    ];

    /** @var list<array{hora: string, quien: string, iniciales: string, tinte: string, texto: string}> $actividad */
    $actividad = [
        ['hora' => '19:41', 'quien' => 'Rosa Quispe', 'iniciales' => 'RQ', 'tinte' => 'bg-[#e0f2fe] text-[#0c4a6e]', 'texto' => 'aceptó el pedido #1259'],
        ['hora' => '19:36', 'quien' => 'Luis Chávez', 'iniciales' => 'LC', 'tinte' => 'bg-[#fce7f3] text-[#831843]', 'texto' => 'abrió la mesa T6 para 2 personas'],
        ['hora' => '19:20', 'quien' => 'Rosa Quispe', 'iniciales' => 'RQ', 'tinte' => 'bg-[#e0f2fe] text-[#0c4a6e]', 'texto' => 'confirmó la reserva de Sofía Mendoza'],
        ['hora' => '19:02', 'quien' => 'Carmen Flores', 'iniciales' => 'CF', 'tinte' => 'bg-[#fef9c3] text-[#713f12]', 'texto' => 'marcó listo el pedido #1257'],
    ];

    $pedidosHoy = 38;
    $ventasHoy = 1284.50;
    $pedidosMiercolesPasado = 32;
    $difPedidos = $pedidosHoy - $pedidosMiercolesPasado;

    $navCounts = [
        'pedidos' => $tabsPedidos['nuevo']['count'],
        'alertas' => count($alertas),
        'reservas' => $reservasPorConfirmar,
    ];
    $navActivo = 'inicio';

    $columnasPedidos = [
        ['label' => 'Pedido'],
        ['label' => 'Pago'],
        ['label' => 'Estado'],
        ['label' => 'Total', 'align' => 'right', 'class' => 'w-24'],
        ['label' => 'Acción', 'align' => 'right', 'class' => 'w-28'],
    ];
@endphp

@section('content')
    {{-- Título de la pantalla --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Inicio</h1>
            <p class="mt-1 text-sm text-muted">
                {{ $fechaHoy }} · Actualizado {{ $horaActual }} ·
                <span class="font-medium text-danger-ink">{{ $alertasUrgentes }} alertas urgentes</span>
                por atender
            </p>
        </div>
        <div class="flex gap-2">
            <x-button variant="secondary" icon="printer" class="flex-1 sm:flex-none">Imprimir tickets</x-button>
            <x-button variant="primary" icon="plus" class="flex-1 sm:flex-none" data-modal-open="modal-nuevo-pedido">Nuevo pedido</x-button>
        </div>
    </div>

    {{-- Resumen del día --}}
    <section aria-labelledby="resumen-titulo" class="mt-6">
        <h2 id="resumen-titulo" class="sr-only">Resumen de hoy</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <x-stat
                label="Pedidos hoy"
                :value="$pedidosHoy"
                :caption="'+'.$difPedidos.' vs. el miércoles pasado'"
                caption-tone="positive"
                href="#"
            />
            <x-stat
                label="Ventas hoy"
                prefix="S/"
                :value="number_format($ventasHoy, 2)"
                :caption="'en '.$pedidosHoy.' pedidos'"
                href="#"
            />
            <x-stat
                label="Mesas ocupadas"
                :value="$mesasOcupadas"
                :unit="' /'.$mesasTotal"
                :caption="round($mesasOcupadas / $mesasTotal * 100).'% de ocupación'"
                href="#"
            />
            <x-stat
                label="Reservas hoy"
                :value="$reservasHoy"
                :caption="$reservasPorConfirmar.' por confirmar'"
                href="#"
            />
        </div>
    </section>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_24rem] lg:items-start">
        {{-- Columna principal --}}
        <div class="flex min-w-0 flex-col gap-4">
            {{-- Alertas pendientes: lo sensible al tiempo va primero --}}
            <x-card title="Alertas pendientes" :subtitle="count($alertas).' sin resolver, '.$alertasUrgentes.' urgentes'" :padded="false">
                <x-slot:actions>
                    <x-button href="#" variant="ghost" size="sm">Ver todas</x-button>
                </x-slot:actions>

                <ul class="divide-y divide-rule border-t border-rule">
                    @foreach ($alertas as $alerta)
                        <li class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-4 sm:px-5">
                            <div class="flex min-w-0 flex-1 flex-col items-start gap-2 sm:flex-row sm:gap-3">
                                <div class="sm:mt-0.5 sm:w-[6.75rem] sm:shrink-0">
                                    <x-badge :status="$alerta['prioridad']" />
                                </div>
                                <div class="min-w-0">
                                    <p class="font-medium text-ink">{{ $alerta['titulo'] }}</p>
                                    <p class="num mt-0.5 text-[0.8125rem] text-muted">{{ $alerta['detalle'] }} · {{ $alerta['hace'] }}</p>
                                </div>
                            </div>
                            <x-button
                                size="sm"
                                :variant="$alerta['prioridad'] === 'urgente' ? 'secondary' : 'ghost'"
                                class="self-start sm:self-center"
                            >{{ $alerta['accion'] }}</x-button>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            {{-- Pedidos recientes --}}
            <x-card title="Pedidos recientes" subtitle="Pedidos activos del turno" :padded="false">
                <x-slot:actions>
                    <x-button href="#" variant="ghost" size="sm">Ir a pedidos</x-button>
                </x-slot:actions>

                <x-tabs id="tabs-pedidos" :tabs="$tabsPedidos" active="nuevo" label="Pedidos por estado" class="px-4 sm:px-5">
                    @foreach ($tabsPedidos as $clave => $tab)
                        @php $filas = $clave === 'todos' ? $pedidos : array_values(array_filter($pedidos, fn ($p) => $p['estado'] === $clave)); @endphp
                        <x-tabs.panel tabs-id="tabs-pedidos" :name="$clave" :active="$clave === 'nuevo'" class="-mx-4 sm:-mx-5">
                            <x-table
                                :columns="$columnasPedidos"
                                :caption="'Pedidos: '.$tab['label']"
                                :empty="count($filas) === 0 ? 'No hay pedidos en este estado.' : null"
                            >
                                @foreach ($filas as $pedido)
                                    <tr class="hover:bg-raised/60">
                                        <td data-cell="lead">
                                            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                                <span class="num font-semibold text-ink">#{{ $pedido['numero'] }}</span>
                                                <span class="text-ink">{{ $pedido['cliente'] }}</span>
                                                <x-badge :status="$pedido['estado']" class="sm:hidden" />
                                            </p>
                                            <p class="num mt-0.5 whitespace-nowrap text-[0.8125rem] text-muted">
                                                {{ $tiposPedido[$pedido['tipo']] }}@if ($pedido['mesa']) {{ $pedido['mesa'] }}@endif · {{ $pedido['hora'] }} · {{ $pedido['hace'] }}
                                            </p>
                                        </td>
                                        <td data-cell="meta" data-label="Pago" class="whitespace-nowrap text-muted">
                                            {{ $pedido['metodo'] }}
                                            @if ($pedido['estado_pago'] === 'por_verificar')
                                                <span class="font-medium text-warning-ink">· por verificar</span>
                                            @elseif ($pedido['estado_pago'] === 'pagado')
                                                <span class="text-success-ink">· pagado</span>
                                            @endif
                                        </td>
                                        <td data-cell="hide-sm">
                                            <x-badge :status="$pedido['estado']" />
                                        </td>
                                        <td data-cell="end" class="num whitespace-nowrap text-right font-medium text-ink">
                                            {{ $soles($pedido['total']) }}
                                        </td>
                                        <td data-cell="action" class="text-right">
                                            @if ($pedido['estado'] === 'nuevo')
                                                <x-button size="sm" variant="success" icon="check">Aceptar</x-button>
                                            @else
                                                <x-button size="sm" variant="ghost">Ver detalle</x-button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </x-table>
                        </x-tabs.panel>
                    @endforeach
                </x-tabs>
            </x-card>

            {{-- Mesas en tiempo real: el plano del local --}}
            <x-card title="Mesas en tiempo real" :subtitle="$mesasOcupadas.' de '.$mesasTotal.' ocupadas'">
                <x-slot:actions>
                    <x-button href="#" variant="ghost" size="sm">Ver plano</x-button>
                </x-slot:actions>

                <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1.5 text-[0.8125rem] text-muted" aria-label="Leyenda de estados">
                    @foreach ($estadosMesa as $clave => $estado)
                        <li class="flex items-center gap-1.5">
                            <span class="size-2 rounded-full {{ $estado['dot'] }}" aria-hidden="true"></span>
                            {{ $estado['label'] }} <span class="num font-medium text-ink">{{ $mesasPorEstado[$clave] ?? 0 }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-col gap-5">
                    @foreach ($areas as $area => $mesas)
                        <div>
                            <h3 class="mb-2 text-[0.8125rem] font-medium text-ink">{{ $area }}</h3>
                            <ul class="grid grid-cols-[repeat(auto-fill,minmax(5.25rem,1fr))] gap-2">
                                @foreach ($mesas as $mesa)
                                    @php
                                        $estilo = $estadosMesa[$mesa['estado']];
                                        $textoEstado = match ($mesa['estado']) {
                                            'ocupada' => $mesa['detalle'],
                                            'reservada' => $mesa['detalle'],
                                            'disponible' => 'Libre',
                                            'fuera_servicio' => 'Fuera',
                                        };
                                        $etiquetaEstado = match ($mesa['estado']) {
                                            'ocupada' => 'ocupada hace '.$mesa['detalle'],
                                            'reservada' => 'reservada a las '.$mesa['detalle'],
                                            'disponible' => 'disponible',
                                            'fuera_servicio' => 'fuera de servicio',
                                        };
                                    @endphp
                                    <li>
                                        <button
                                            type="button"
                                            class="press flex h-[4.25rem] w-full flex-col justify-between rounded-control p-2.5 text-left {{ $estilo['tile'] }} hover:brightness-[0.97]"
                                            aria-label="Mesa {{ $mesa['codigo'] }}, {{ $mesa['capacidad'] }} personas, {{ $etiquetaEstado }}"
                                        >
                                            <span class="flex items-baseline justify-between gap-1">
                                                <span class="text-sm font-semibold">{{ $mesa['codigo'] }}</span>
                                                <span class="num text-xs opacity-80">{{ $mesa['capacidad'] }}p</span>
                                            </span>
                                            <span class="num truncate text-xs font-medium">{{ $textoEstado }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>

        {{-- Columna lateral --}}
        <div class="grid min-w-0 grid-cols-1 items-start gap-4 md:grid-cols-2 lg:grid-cols-1">
            <x-card title="Reservas próximas" :subtitle="$reservasPorConfirmar.' por confirmar'" :padded="false" class="md:col-span-2 lg:col-span-1">
                <x-slot:actions>
                    <x-button href="#" variant="ghost" size="sm">Ver todas</x-button>
                </x-slot:actions>
                <ul class="divide-y divide-rule border-t border-rule">
                    @foreach ($reservasProximas as $reserva)
                        <li class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="num w-12 shrink-0 text-sm font-semibold text-ink">{{ $reserva['hora'] }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-ink">{{ $reserva['cliente'] }}</p>
                                <p class="num truncate text-[0.8125rem] text-muted">
                                    {{ $reserva['personas'] }} personas · {{ $reserva['mesa'] ? 'Mesa '.$reserva['mesa'] : '' }}{{ $reserva['mesa'] && $reserva['nota'] ? ' · ' : '' }}{{ $reserva['nota'] }}
                                </p>
                            </div>
                            <x-badge :status="$reserva['estado']" />
                        </li>
                    @endforeach
                </ul>
            </x-card>

            <x-card title="Promociones activas" :subtitle="count($promociones).' vigentes'">
                <ul class="flex flex-col gap-4">
                    @foreach ($promociones as $promo)
                        <li>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-ink">{{ $promo['nombre'] }}</p>
                                    <p class="text-[0.8125rem] text-muted">{{ $promo['tipo'] }} · hasta el {{ $promo['hasta'] }}</p>
                                </div>
                                <p class="num shrink-0 text-right text-sm">
                                    <span class="font-semibold text-ink">{{ $promo['usos'] }}</span><span class="text-muted">{{ $promo['limite'] ? ' /'.$promo['limite'] : ' usos' }}</span>
                                </p>
                            </div>
                            @if ($promo['limite'])
                                <div
                                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-brand-100"
                                    role="progressbar"
                                    aria-label="Usos de {{ $promo['nombre'] }}"
                                    aria-valuemin="0"
                                    aria-valuemax="{{ $promo['limite'] }}"
                                    aria-valuenow="{{ $promo['usos'] }}"
                                >
                                    <div class="h-full rounded-full bg-brand-700" style="width: {{ round($promo['usos'] / $promo['limite'] * 100, 1) }}%"></div>
                                </div>
                            @else
                                <p class="mt-1 text-xs text-muted">Sin límite de usos</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-card>

            <x-card title="Actividad reciente" :padded="false">
                <x-slot:actions>
                    <x-button href="#" variant="ghost" size="sm">Ver todo</x-button>
                </x-slot:actions>
                <ul class="divide-y divide-rule border-t border-rule">
                    @foreach ($actividad as $evento)
                        <li class="flex items-start gap-3 px-4 py-3 sm:px-5">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-[0.6875rem] font-semibold {{ $evento['tinte'] }}" aria-hidden="true">{{ $evento['iniciales'] }}</span>
                            <p class="min-w-0 flex-1 text-[0.8125rem] text-muted">
                                <span class="font-medium text-ink">{{ $evento['quien'] }}</span> {{ $evento['texto'] }}
                            </p>
                            <span class="num shrink-0 text-xs text-muted">{{ $evento['hora'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>

    {{-- Referencia de estados de los componentes --}}
    <section aria-labelledby="estados-titulo" class="mt-16 border-t border-line pt-8">
        <h2 id="estados-titulo" class="text-lg font-semibold text-ink">Estados de componentes</h2>
        <p class="mt-1 max-w-2xl text-sm text-muted">Referencia de diseño con datos de muestra: cada componente vacío, cargando, con error y deshabilitado.</p>

        <h3 class="mb-3 mt-8 text-section font-semibold">Estadísticas</h3>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <x-stat label="Pedidos hoy" loading />
            <x-stat label="Reservas hoy" empty caption="Aún no hay reservas para hoy" />
            <x-stat label="Ventas hoy" error="No se pudo cargar" />
            <x-stat label="Ticket promedio" disabled="Incluido en el plan Premium" />
        </div>

        <h3 class="mb-3 mt-8 text-section font-semibold">Botones</h3>
        <div class="flex flex-wrap items-center gap-2">
            <x-button variant="primary" icon="plus">Principal</x-button>
            <x-button variant="secondary">Secundario</x-button>
            <x-button variant="ghost">Texto</x-button>
            <x-button variant="success" icon="check">Aceptar</x-button>
            <x-button variant="danger">Rechazar</x-button>
            <x-button variant="primary" loading loading-text="Guardando…">Guardar</x-button>
            <x-button variant="primary" disabled>Deshabilitado</x-button>
            <x-button variant="secondary" disabled>Deshabilitado</x-button>
            <x-button variant="ghost" size="icon" aria-label="Imprimir"><x-icon name="printer" /></x-button>
        </div>

        <h3 class="mb-3 mt-8 text-section font-semibold">Badges</h3>
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['nuevo', 'en_preparacion', 'listo', 'en_camino', 'entregado', 'cancelado', 'por_verificar', 'reservada', 'informativa'] as $estado)
                <x-badge :status="$estado" />
            @endforeach
            <x-badge loading />
            <x-badge status="pagado" disabled />
            <x-badge />
        </div>

        <h3 class="mb-3 mt-8 text-section font-semibold">Cards</h3>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-card title="Reservas próximas" loading />
            <x-card title="Promociones activas" empty="No hay promociones activas en este momento.">
                <x-slot:empty-action>
                    <x-button size="sm" icon="plus">Crear promoción</x-button>
                </x-slot:empty-action>
            </x-card>
            <x-card title="Actividad reciente" error="No se pudo cargar la actividad. Último dato: 19:30." />
            <x-card title="Reportes de ventas" disabled="Los reportes están incluidos en el plan Premium." />
        </div>

        <h3 class="mb-3 mt-8 text-section font-semibold">Pestañas y tabla</h3>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-card title="Pestañas: deshabilitada y cargando" :padded="false">
                <x-tabs
                    id="tabs-demo"
                    :tabs="['hoy' => ['label' => 'Hoy', 'count' => 12], 'manana' => ['label' => 'Mañana', 'count' => 5], 'canceladas' => ['label' => 'Canceladas', 'count' => 0, 'disabled' => true]]"
                    label="Demostración de pestañas"
                    class="px-4 sm:px-5"
                >
                    <x-tabs.panel tabs-id="tabs-demo" name="hoy" active class="py-4">
                        <p class="text-sm text-muted">Pestaña activa. «Canceladas» está deshabilitada y el teclado la salta.</p>
                    </x-tabs.panel>
                    <x-tabs.panel tabs-id="tabs-demo" name="manana" class="py-4">
                        <p class="text-sm text-muted">5 reservas para mañana.</p>
                    </x-tabs.panel>
                    <x-tabs.panel tabs-id="tabs-demo" name="canceladas" class="py-4">
                        <p class="text-sm text-muted">Sin reservas canceladas.</p>
                    </x-tabs.panel>
                </x-tabs>
                <div class="border-t border-rule px-4 sm:px-5">
                    <x-tabs id="tabs-demo-carga" :tabs="['a' => ['label' => 'Nuevos', 'count' => 0], 'b' => ['label' => 'Listos', 'count' => 0]]" loading label="Pestañas cargando" />
                </div>
            </x-card>

            <x-card title="Tabla: cargando" :padded="false">
                <x-table :columns="[['label' => 'Pedido'], ['label' => 'Estado'], ['label' => 'Total', 'align' => 'right']]" loading :skeleton-rows="3" />
            </x-card>
            <x-card title="Tabla: vacía" :padded="false">
                <x-table :columns="[['label' => 'Pedido'], ['label' => 'Estado'], ['label' => 'Total', 'align' => 'right']]" empty="No hay pedidos cancelados hoy." />
            </x-card>
            <x-card title="Tabla: con error" :padded="false">
                <x-table :columns="[['label' => 'Pedido'], ['label' => 'Estado'], ['label' => 'Total', 'align' => 'right']]" error="No se pudieron cargar los pedidos." />
            </x-card>
        </div>

        <h3 class="mb-3 mt-8 text-section font-semibold">Modales</h3>
        <div class="flex flex-wrap gap-2">
            <x-button data-modal-open="modal-nuevo-pedido">Modal con formulario</x-button>
            <x-button data-modal-open="modal-cargando">Modal cargando</x-button>
            <x-button data-modal-open="modal-error">Modal con error</x-button>
        </div>
    </section>
@endsection

@push('modals')
    <x-modal id="modal-nuevo-pedido" title="Nuevo pedido" description="Registra un pedido tomado en el local o por teléfono.">
        <form class="flex flex-col gap-4" onsubmit="return false">
            <fieldset>
                <legend class="mb-2 text-sm font-medium">Tipo de pedido</legend>
                <div class="grid grid-cols-3 gap-1 rounded-control bg-raised p-1">
                    @foreach (['salon' => 'Salón', 'recojo' => 'Recojo', 'delivery' => 'Delivery'] as $valor => $texto)
                        <label class="relative">
                            <input type="radio" name="tipo" value="{{ $valor }}" class="peer sr-only" @checked($valor === 'salon')>
                            <span class="press flex h-10 cursor-pointer items-center justify-center rounded-[0.5rem] text-sm text-muted peer-checked:bg-surface peer-checked:font-medium peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">{{ $texto }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="pedido-cliente" class="mb-2 block text-sm font-medium">Cliente</label>
                    <input id="pedido-cliente" type="text" autocomplete="name" placeholder="Nombre del cliente" class="h-11 w-full rounded-control border border-line bg-surface px-3 text-sm placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30">
                </div>
                <div>
                    <label for="pedido-telefono" class="mb-2 block text-sm font-medium">Teléfono</label>
                    <input id="pedido-telefono" type="tel" inputmode="tel" autocomplete="tel" placeholder="987 654 321" aria-describedby="pedido-telefono-ayuda" class="num h-11 w-full rounded-control border border-line bg-surface px-3 text-sm placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30">
                    <p id="pedido-telefono-ayuda" class="mt-1.5 text-xs text-muted">9 dígitos, sin código de país.</p>
                </div>
            </div>

            <div>
                <label for="pedido-mesa" class="mb-2 block text-sm font-medium">Mesa</label>
                <select id="pedido-mesa" class="h-11 w-full rounded-control border border-control bg-surface px-3 text-sm focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30">
                    @foreach ($todasLasMesas as $mesa)
                        @if ($mesa['estado'] === 'disponible')
                            <option>{{ $mesa['codigo'] }} · {{ $mesa['capacidad'] }} personas</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div>
                <label for="pedido-obs" class="mb-2 block text-sm font-medium">Observaciones <span class="font-normal text-muted">(opcional)</span></label>
                <textarea id="pedido-obs" rows="3" placeholder="Sin ají, poca sal…" class="w-full rounded-control border border-line bg-surface px-3 py-2.5 text-sm placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30"></textarea>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="secondary" data-modal-close>Cancelar</x-button>
            <x-button variant="primary">Crear pedido</x-button>
        </x-slot:footer>
    </x-modal>

    <x-modal id="modal-cargando" title="Detalle del pedido #1259" loading>
        <x-slot:footer>
            <x-button variant="secondary" data-modal-close>Cerrar</x-button>
            <x-button variant="success" disabled>Aceptar</x-button>
        </x-slot:footer>
    </x-modal>

    <x-modal id="modal-error" title="Detalle del pedido #1259" error="No se pudo cargar el pedido. Revisa tu conexión e inténtalo otra vez." size="sm">
        <x-slot:footer>
            <x-button variant="secondary" data-modal-close>Cerrar</x-button>
        </x-slot:footer>
    </x-modal>
@endpush
