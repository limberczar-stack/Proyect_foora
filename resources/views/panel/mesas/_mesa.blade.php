@php
    /**
     * Una mesa del plano para quien atiende. La usan la página (index) y la actualización en vivo
     * (CuentaController::plano), así que debe pintarse igual en las dos.
     *
     * - Disponible: botón que abre el formulario de cuenta nueva.
     * - Ocupada: enlace al detalle de su cuenta (salon.js lo abre en la hoja inferior).
     * - Fuera de servicio (o reservada, más adelante): no se toca.
     *
     * @var \App\Models\Mesa $mesa
     * @var array{cuenta_id: int, numero: int, version: int, tiempo: string, minutos: int, comensales: int, mesero: ?string, grupo: ?string, demorada: bool}|null $cuenta
     */
    $horas = \App\Services\GestionarCuentas::HORAS_PARA_RESALTAR;
@endphp

@if ($mesa->estado === 'ocupada' && $cuenta !== null)
    <x-mesa
        as="a"
        href="{{ route('panel.mesas.cuentas.show', $cuenta['cuenta_id']) }}"
        :codigo="$mesa->codigo"
        :capacidad="$mesa->capacidad"
        estado="ocupada"
        :alerta="$cuenta['demorada'] ? 'Más de '.$horas.' h abierta' : null"
        aria-label="Mesa {{ $mesa->codigo }}, ocupada hace {{ $cuenta['tiempo'] }}. Ver cuenta {{ $cuenta['numero'] }}"
        data-cuenta-abrir
        data-cuenta-id="{{ $cuenta['cuenta_id'] }}"
    >
        <span>{{ $cuenta['tiempo'] }} · {{ $cuenta['comensales'] }} {{ $cuenta['comensales'] === 1 ? 'comensal' : 'comensales' }}</span>
        @if ($cuenta['mesero'])
            <span class="truncate">{{ \Illuminate\Support\Str::before($cuenta['mesero'], ' ') }}</span>
        @endif
        @if ($cuenta['grupo'])
            <span class="font-medium" data-mesa-grupo>{{ $cuenta['grupo'] }}</span>
        @endif
    </x-mesa>
@elseif ($mesa->estado === 'disponible' && ($puedeAtender ?? true))
    <x-mesa
        as="button"
        :codigo="$mesa->codigo"
        :capacidad="$mesa->capacidad"
        estado="disponible"
        aria-label="Mesa {{ $mesa->codigo }}, disponible. Abrir cuenta"
        data-modal-open="modal-abrir-cuenta"
        data-mesa-libre
        data-mesa-id="{{ $mesa->id }}"
        data-mesa-codigo="{{ $mesa->codigo }}"
        data-mesa-capacidad="{{ $mesa->capacidad }}"
    />
@else
    <x-mesa :codigo="$mesa->codigo" :capacidad="$mesa->capacidad" :estado="$mesa->estado" />
@endif
