@php
    /**
     * Rondas de una cuenta dentro de su detalle: estado, líneas (las anuladas tachadas, con su motivo),
     * total acumulado del servidor y, según el rol, servir, anular una línea o cancelar la ronda.
     *
     * Las observaciones las escribe el mesero: se pintan siempre con {{ }}, nunca sin escapar.
     *
     * @var \App\Models\Cuenta $cuenta  con pedidos.items.opciones, pedidos.items.anuladoPor y pedidos.creadoPor
     * @var int $totalCentimos
     */
    $usuario = auth()->user();
    $abierta = $cuenta->estaAbierta();
    $puedeServir = $abierta && $usuario->can('servir', $cuenta);
    $puedeCorregir = $abierta && $usuario->can('corregirRondas', $cuenta);
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $tz = $usuario->restaurante->zona_horaria;
    $estados = ['en_preparacion' => 'En preparación', 'listo' => 'Listo para servir', 'entregado' => 'Servido', 'cancelado' => 'Cancelada'];
    $tonos = ['en_preparacion' => 'warning', 'listo' => 'success', 'entregado' => 'neutral', 'cancelado' => 'danger'];
    $campo = 'h-11 w-full rounded-control border border-line bg-surface px-3 text-sm text-ink focus:border-brand-600 focus:outline-none lg:h-10';
@endphp

<section class="mt-5" aria-labelledby="rondas-{{ $cuenta->id }}" data-cuenta-rondas>
    <div class="flex items-baseline justify-between gap-3">
        <h3 id="rondas-{{ $cuenta->id }}" class="text-sm font-semibold text-ink">Rondas</h3>
        <p class="num text-base font-bold text-ink" data-cuenta-total="{{ $totalCentimos }}">{{ $soles($totalCentimos / 100) }}</p>
    </div>

    @if ($cuenta->pedidos->isEmpty())
        <p class="mt-2 rounded-control border border-dashed border-control px-3 py-3 text-sm text-muted">Todavía no hay rondas. Toma el pedido con «Agregar pedido».</p>
    @else
        <ol class="mt-2 flex flex-col gap-2">
            @foreach ($cuenta->pedidos as $pedido)
                <li @class(['rounded-control border p-3', 'border-success-dot/60 bg-success-bg/40' => $pedido->estado === 'listo', 'border-line' => $pedido->estado !== 'listo', 'opacity-70' => $pedido->estado === 'cancelado']) data-ronda="{{ $pedido->ronda }}" data-ronda-estado="{{ $pedido->estado }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-ink">
                            Ronda {{ $pedido->ronda }}
                            <span class="num font-normal text-muted">· #{{ $pedido->numero }} · {{ $pedido->created_at->timezone($tz)->format('H:i') }}@if ($pedido->creadoPor) · {{ \Illuminate\Support\Str::before($pedido->creadoPor->name, ' ') }}@endif</span>
                        </p>
                        <x-badge :tone="$tonos[$pedido->estado] ?? 'neutral'">{{ $estados[$pedido->estado] ?? $pedido->estado }}</x-badge>
                    </div>

                    <ul class="mt-2 flex flex-col divide-y divide-rule">
                        @foreach ($pedido->items as $item)
                            @php $detalle = collect([$item->variante_nombre, ...$item->opciones->pluck('nombre')])->filter()->implode(' · '); @endphp
                            <li @class(['py-1.5 text-sm', 'opacity-60' => $item->estaAnulada()]) @if ($item->estaAnulada()) data-item-anulado @endif>
                                <div class="flex items-start justify-between gap-3">
                                    <span @class(['min-w-0 text-ink', 'line-through' => $item->estaAnulada()])><span class="num">{{ $item->cantidad }}</span> × {{ $item->nombre }}</span>
                                    <span @class(['num shrink-0 text-ink', 'line-through' => $item->estaAnulada()])>{{ $soles($item->subtotal) }}</span>
                                </div>
                                @if ($detalle !== '')
                                    <p class="text-[0.8125rem] text-muted">{{ $detalle }}</p>
                                @endif
                                @if ($item->observacion)
                                    <p class="mt-0.5 rounded-chip bg-warning-bg px-2 py-0.5 text-[0.8125rem] text-warning-ink" data-item-observacion>Nota: {{ $item->observacion }}</p>
                                @endif
                                @if ($item->estaAnulada())
                                    <p class="text-[0.8125rem] font-medium text-danger-ink">Anulada: {{ $item->motivo_anulacion }}@if ($item->anuladoPor) · {{ $item->anuladoPor->name }}@endif</p>
                                @elseif ($puedeCorregir && $pedido->estado !== 'cancelado')
                                    <details class="mt-1">
                                        <summary class="press inline-flex min-h-9 cursor-pointer list-none items-center text-[0.8125rem] font-medium text-danger-ink [&::-webkit-details-marker]:hidden">Anular esta línea</summary>
                                        <form method="POST" action="{{ route('panel.mesas.cuentas.lineas.anular', [$cuenta, $item->id]) }}" class="mt-1 flex flex-col gap-2 sm:flex-row" data-cuenta-form>
                                            @csrf
                                            @method('PATCH')
                                            <label for="anular-linea-{{ $item->id }}" class="sr-only">Motivo</label>
                                            <input id="anular-linea-{{ $item->id }}" name="motivo" required maxlength="200" placeholder="Motivo, p. ej.: el cliente lo cambió" class="{{ $campo }}">
                                            <x-button type="submit" size="sm" variant="danger" data-cuenta-enviar data-texto="Anular" data-texto-ocupado="Anulando…">Anular</x-button>
                                        </form>
                                    </details>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($pedido->estado === 'cancelado' && $pedido->cancelacion_detalle)
                        <p class="mt-1 text-[0.8125rem] text-danger-ink">{{ $pedido->cancelacion_detalle }}</p>
                    @endif

                    @if ($pedido->estado !== 'cancelado')
                        <p class="num mt-1 text-right text-[0.8125rem] text-muted">Ronda: {{ $soles($pedido->total) }}</p>
                    @endif

                    @if ($puedeServir && $pedido->estado === 'listo')
                        <form method="POST" action="{{ route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]) }}" class="mt-2" data-cuenta-form>
                            @csrf
                            @method('PATCH')
                            <x-button type="submit" variant="success" class="w-full" data-cuenta-enviar data-texto="Servido" data-texto-ocupado="Guardando…">Servido</x-button>
                        </form>
                    @endif

                    @if ($puedeCorregir && in_array($pedido->estado, ['en_preparacion', 'listo'], true))
                        <details class="mt-2">
                            <summary class="press inline-flex min-h-9 cursor-pointer list-none items-center text-[0.8125rem] font-medium text-danger-ink [&::-webkit-details-marker]:hidden">Cancelar la ronda entera</summary>
                            <form method="POST" action="{{ route('panel.mesas.cuentas.pedidos.cancelar', [$cuenta, $pedido]) }}" class="mt-1 flex flex-col gap-2" data-cuenta-form>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="desde" value="{{ $pedido->estado }}">
                                <label for="cancelar-ronda-{{ $pedido->id }}" class="sr-only">Motivo</label>
                                <input id="cancelar-ronda-{{ $pedido->id }}" name="motivo" required maxlength="200" placeholder="Motivo de la cancelación" class="{{ $campo }}">
                                <x-button type="submit" size="sm" variant="danger" data-cuenta-enviar data-texto="Cancelar ronda" data-texto-ocupado="Cancelando…">Cancelar ronda</x-button>
                            </form>
                        </details>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
