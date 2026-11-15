@php
    /**
     * Precuenta para imprimir en papel de 80 mm (o mostrar en pantalla). No es un comprobante de pago:
     * así lo dice arriba y abajo. Incluye las rondas no canceladas y sus líneas no anuladas.
     *
     * @var \App\Models\Cuenta $cuenta  con ocupacionesAbiertas.mesa, mesero y pedidos.items.opciones
     * @var \App\Models\Restaurante $restaurante
     * @var int $totalCentimos
     */
    $tz = $restaurante->zona_horaria;
    $soles = fn ($monto): string => number_format((float) $monto, 2);
    $lineas = $cuenta->pedidos->flatMap->items;
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Precuenta · Cuenta #{{ $cuenta->numero }}</title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f4f5; color: #111; font: 13px/1.35 ui-monospace, 'Cascadia Mono', Menlo, Consolas, monospace; }
        .ticket { width: 72mm; max-width: 100%; margin: 12px auto; padding: 4mm; background: #fff; }
        h1 { margin: 0; font-size: 15px; text-align: center; }
        .centro { text-align: center; }
        .aviso { margin: 6px 0; padding: 4px 0; border-top: 1px dashed #111; border-bottom: 1px dashed #111; font-weight: 700; text-align: center; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        td.monto { text-align: right; white-space: nowrap; padding-left: 6px; }
        .detalle { color: #444; font-size: 12px; }
        .total td { padding-top: 6px; border-top: 1px dashed #111; font-size: 15px; font-weight: 700; }
        .acciones { width: 72mm; max-width: 100%; margin: 0 auto 16px; display: flex; gap: 8px; }
        .acciones button, .acciones a { flex: 1; min-height: 44px; border: 1px solid #d4d4d8; border-radius: 8px; background: #fff; font: 600 14px system-ui, sans-serif; color: #111; text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        @media print {
            body { background: #fff; }
            .ticket { margin: 0; padding: 0; width: auto; }
            .acciones { display: none; }
        }
    </style>
</head>
<body>
    <main class="ticket">
        <h1>{{ $restaurante->nombre }}</h1>
        @if ($restaurante->direccion)
            <p class="centro" style="margin: 2px 0">{{ $restaurante->direccion }}</p>
        @endif

        <p class="aviso">Precuenta. No es comprobante de pago</p>

        <p style="margin: 4px 0">
            Cuenta #{{ $cuenta->numero }} · {{ $cuenta->mesasTexto() ?: 'Sin mesa' }}<br>
            {{ $cuenta->comensales }} {{ $cuenta->comensales === 1 ? 'comensal' : 'comensales' }}@if ($cuenta->mesero) · Atiende: {{ $cuenta->mesero->name }}@endif<br>
            {{ now()->timezone($tz)->format('d/m/Y H:i') }}
        </p>

        <table aria-label="Consumo">
            @forelse ($lineas as $item)
                @php $detalle = collect([$item->variante_nombre, ...$item->opciones->pluck('nombre')])->filter()->implode(', '); @endphp
                <tr>
                    <td>{{ $item->cantidad }} × {{ $item->nombre }}@if ($detalle !== '')<br><span class="detalle">{{ $detalle }}</span>@endif</td>
                    <td class="monto">{{ $soles($item->subtotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="centro">Sin consumo</td></tr>
            @endforelse
            <tr class="total">
                <td>TOTAL S/</td>
                <td class="monto">{{ number_format($totalCentimos / 100, 2) }}</td>
            </tr>
        </table>

        <p class="aviso">Precuenta. No es comprobante de pago</p>
        <p class="centro" style="margin: 0">Solicite su comprobante al pagar.</p>
    </main>

    <div class="acciones">
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="{{ route('panel.mesas.index', ['cuenta' => $cuenta->id]) }}">Volver</a>
    </div>
</body>
</html>
