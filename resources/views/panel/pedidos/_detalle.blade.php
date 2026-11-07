@php
    /**
     * Detalle de un pedido. Lo inserta resources/js/pedidos.js en el panel derecho o en la hoja.
     *
     * @var \App\Models\Pedido $pedido
     */
    $restaurante = $pedido->restaurante;
    $tz = $restaurante->zona_horaria;
    $hora = fn ($fecha): string => $fecha ? $fecha->timezone($tz)->format('H:i') : '';
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $usuario = auth()->user();
    $verContacto = $usuario->can('verContacto', $pedido);
    $revisarPago = $usuario->can('revisarPago', $pedido) && $pedido->estado_pago === 'por_verificar' && $pedido->estado !== 'cancelado';
    $destinos = collect($pedido->destinosPermitidos())->filter(fn (string $destino): bool => $usuario->can('cambiarEstado', [$pedido, $destino]))->values()->all();
    $activo = ! in_array($pedido->estado, ['entregado', 'cancelado'], true);
    $pidePago = in_array($pedido->estado_pago, ['pendiente', 'rechazado'], true);
    $campo = 'h-11 w-full rounded-control border border-line bg-surface px-3 text-sm text-ink focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 lg:h-10';
    $estadoActual = fn (string $destino): array => ['desde' => $pedido->estado, 'destino' => $destino];
@endphp

<div data-detalle="{{ $pedido->id }}" data-estado="{{ $pedido->estado }}">
    <header class="flex items-start justify-between gap-3 border-b border-rule px-5 py-4">
        <div class="min-w-0">
            <h2 class="flex flex-wrap items-center gap-2 text-lg font-bold tracking-tight">
                <span class="num">Pedido #{{ $pedido->numero }}</span>
                <x-badge :status="$pedido->estado" />
            </h2>
            <p class="num mt-0.5 text-[0.8125rem] text-muted">Recibido a las {{ $hora($pedido->created_at) }} · {{ $pedido->tipoTexto() }}</p>
        </div>
        <x-button variant="ghost" size="icon" class="-mr-2 -mt-1 lg:hidden" data-modal-close aria-label="Cerrar"><x-icon name="x" /></x-button>
    </header>

    <div class="flex flex-col gap-5 px-5 py-4">
        <p class="hidden rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert" data-accion-error></p>

        {{-- Cliente --}}
        <section aria-label="Cliente">
            <p class="text-base font-semibold text-ink">{{ $pedido->nombre_cliente }}</p>
            @if ($verContacto)
                <p class="num text-sm text-muted">{{ $pedido->telefono_cliente }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($pedido->llamadaCliente())
                        <x-button size="sm" href="{{ $pedido->llamadaCliente() }}">Llamar</x-button>
                    @endif
                    @if ($pedido->whatsappCliente())
                        <x-button size="sm" href="{{ $pedido->whatsappCliente() }}" target="_blank" rel="noopener noreferrer">WhatsApp</x-button>
                        <x-button size="sm" variant="success" href="{{ $pedido->whatsappCliente($pedido->mensajeParaCliente($restaurante)) }}" target="_blank" rel="noopener noreferrer">Notificar al cliente</x-button>
                    @endif
                </div>
            @endif

            @if ($pedido->tipo === 'delivery' && $verContacto)
                <dl class="mt-3 flex flex-col gap-1 text-sm">
                    <div><dt class="inline text-muted">Dirección:</dt> <dd class="inline text-ink">{{ $pedido->direccion_entrega }}</dd></div>
                    @if ($pedido->referencia_entrega)
                        <div><dt class="inline text-muted">Referencia:</dt> <dd class="inline text-ink">{{ $pedido->referencia_entrega }}</dd></div>
                    @endif
                </dl>
            @endif

            @if ($pedido->eta_at && $pedido->estado === 'en_camino')
                <p class="num mt-2 text-sm text-info-ink">Llega cerca de las {{ $hora($pedido->eta_at) }}.</p>
            @endif
            @if ($pedido->recoger_antes_de && $pedido->estado === 'listo')
                <p class="num mt-2 text-sm text-warning-ink">Debe recogerse antes de las {{ $hora($pedido->recoger_antes_de) }}.</p>
            @endif
        </section>

        {{-- Pago --}}
        @if ($verContacto)
            <section aria-label="Pago" class="rounded-control bg-raised px-4 py-3">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-ink">{{ $pedido->metodoPago->nombre }}</p>
                    <x-badge :status="$pedido->estado_pago" />
                </div>
                @if ($pedido->referencia_pago)
                    <p class="mt-1 text-[0.8125rem] text-muted">Código de operación: <span class="font-medium text-ink">{{ $pedido->referencia_pago }}</span></p>
                @endif

                @if ($revisarPago)
                    <p class="mt-2 text-[0.8125rem] text-muted">Revisa tu app de {{ $pedido->metodoPago->nombre }} antes de validar.</p>
                    <div class="mt-2 flex gap-2">
                        <form method="POST" action="{{ route('panel.pedidos.pago', $pedido) }}" data-accion-form>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="accion" value="validar">
                            <x-button type="submit" size="sm" variant="success" data-accion-enviar>Validar pago</x-button>
                        </form>
                        <form method="POST" action="{{ route('panel.pedidos.pago', $pedido) }}" data-accion-form>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="accion" value="rechazar">
                            <x-button type="submit" size="sm" variant="danger" data-accion-enviar>Rechazar pago</x-button>
                        </form>
                    </div>
                @endif

                @if ($pedido->estado_pago === 'rechazado' && $activo)
                    <p class="mt-2 text-[0.8125rem] text-danger-ink">El pago fue rechazado. Si no se cobrará, cancela el pedido.</p>
                    @if (in_array('cancelado', $destinos, true))
                        <x-button size="sm" variant="secondary" class="mt-2" data-mostrar="cancelar-{{ $pedido->id }}" data-motivo="pago_no_confirmado">Cancelar por pago no confirmado</x-button>
                    @endif
                @endif
            </section>
        @endif

        {{-- Productos --}}
        <section aria-label="Productos">
            <ul class="flex flex-col divide-y divide-rule">
                @foreach ($pedido->items as $item)
                    <li class="py-2.5">
                        <div class="flex items-start justify-between gap-3 text-sm">
                            <span class="min-w-0 font-medium text-ink"><span class="num">{{ $item->cantidad }}</span> × {{ $item->nombre }}</span>
                            <span class="num shrink-0 text-ink">{{ $soles($item->subtotal) }}</span>
                        </div>
                        @php $detalle = collect([$item->variante_nombre, ...$item->opciones->map(fn ($o) => $o->nombre.((float) $o->precio_extra > 0 ? ' (+'.number_format((float) $o->precio_extra, 2).')' : ''))])->filter()->implode(' · '); @endphp
                        @if ($detalle !== '')
                            <p class="mt-0.5 text-[0.8125rem] text-muted">{{ $detalle }}</p>
                        @endif
                        @if ($item->observacion)
                            <p class="mt-1 rounded-chip bg-warning-bg px-2 py-1 text-[0.8125rem] text-warning-ink">Nota: {{ $item->observacion }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            <dl class="mt-2 flex flex-col gap-0.5 border-t border-rule pt-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted">Subtotal</dt><dd class="num">{{ $soles($pedido->subtotal) }}</dd></div>
                @if ($pedido->tipo === 'delivery')
                    <div class="flex justify-between gap-3"><dt class="text-muted">Delivery</dt><dd class="num">{{ $soles($pedido->costo_delivery) }}</dd></div>
                @endif
                <div class="flex justify-between gap-3 text-base font-bold"><dt>Total</dt><dd class="num">{{ $soles($pedido->total) }}</dd></div>
            </dl>
        </section>

        {{-- Acciones --}}
        @if ($destinos !== [])
            <section aria-label="Acciones" class="flex flex-col gap-2 border-t border-rule pt-4">
                @if (in_array('en_preparacion', $destinos, true))
                    <form method="POST" action="{{ route('panel.pedidos.estado', $pedido) }}" data-accion-form>
                        @csrf
                        @method('PATCH')
                        @foreach ($estadoActual('en_preparacion') as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                        <x-button type="submit" variant="success" class="w-full" data-accion-enviar>Aceptar pedido</x-button>
                    </form>
                @endif

                @if (in_array('listo', $destinos, true))
                    <form method="POST" action="{{ route('panel.pedidos.estado', $pedido) }}" data-accion-form>
                        @csrf
                        @method('PATCH')
                        @foreach ($estadoActual('listo') as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                        <x-button type="submit" variant="primary" class="w-full" data-accion-enviar>Marcar listo</x-button>
                    </form>
                @endif

                @if (in_array('en_camino', $destinos, true))
                    <form method="POST" action="{{ route('panel.pedidos.estado', $pedido) }}" class="flex items-end gap-2" data-accion-form>
                        @csrf
                        @method('PATCH')
                        @foreach ($estadoActual('en_camino') as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                        <div class="w-28 shrink-0">
                            <label for="minutos-{{ $pedido->id }}" class="mb-1 block text-xs text-muted">Llega en (min)</label>
                            <input id="minutos-{{ $pedido->id }}" name="minutos" type="number" inputmode="numeric" min="1" max="{{ \App\Models\Pedido::MINUTOS_ETA_MAXIMO }}" value="{{ \App\Models\Pedido::MINUTOS_ETA_POR_DEFECTO }}" class="num {{ $campo }}">
                        </div>
                        <x-button type="submit" variant="primary" class="flex-1" data-accion-enviar>Enviar en camino</x-button>
                    </form>
                @endif

                @if (in_array('entregado', $destinos, true))
                    <form method="POST" action="{{ route('panel.pedidos.estado', $pedido) }}" class="flex flex-col gap-2" data-accion-form>
                        @csrf
                        @method('PATCH')
                        @foreach ($estadoActual('entregado') as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                        @if ($pidePago)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                                <input type="checkbox" name="cobrado" value="1" class="size-[1.125rem] accent-brand-700" @checked($pedido->estado_pago === 'pendiente')>
                                {{ $pedido->estado_pago === 'rechazado' ? 'Cobrado de otra forma' : 'Cobrado ('.$pedido->metodoPago->nombre.')' }}
                            </label>
                        @endif
                        <x-button type="submit" variant="success" class="w-full" data-accion-enviar>{{ $pedido->tipo === 'recojo' ? 'Pedido recogido' : 'Marcar entregado' }}</x-button>
                    </form>
                @endif

                @if (in_array('cancelado', $destinos, true))
                    <x-button variant="danger" class="w-full" data-mostrar="cancelar-{{ $pedido->id }}">{{ $pedido->estado === 'nuevo' ? 'Rechazar pedido' : 'Cancelar pedido' }}</x-button>
                    <form method="POST" action="{{ route('panel.pedidos.estado', $pedido) }}" id="cancelar-{{ $pedido->id }}" class="flex flex-col gap-2 rounded-control bg-danger-bg/40 p-3" data-accion-form hidden>
                        @csrf
                        @method('PATCH')
                        @foreach ($estadoActual('cancelado') as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                        <label for="motivo-{{ $pedido->id }}" class="text-sm font-medium">Motivo</label>
                        <select id="motivo-{{ $pedido->id }}" name="motivo" required class="{{ $campo }}">
                            <option value="">Elige un motivo</option>
                            @foreach (\App\Models\Pedido::MOTIVOS_CANCELACION as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        <label for="detalle-{{ $pedido->id }}" class="text-sm font-medium">Detalle <span class="font-normal text-muted">(opcional)</span></label>
                        <input id="detalle-{{ $pedido->id }}" name="detalle" type="text" maxlength="255" class="{{ $campo }}">
                        <x-button type="submit" variant="danger" data-accion-enviar>Confirmar</x-button>
                    </form>
                @endif
            </section>
        @endif

        {{-- Historial --}}
        <section aria-label="Historial">
            <h3 class="text-sm font-semibold text-ink">Historial</h3>
            <ol class="mt-2 flex flex-col gap-1.5">
                @foreach ($pedido->historial->sortBy('id') as $registro)
                    <li class="flex gap-3 text-[0.8125rem]">
                        <span class="num w-11 shrink-0 text-muted">{{ $hora($registro->created_at) }}</span>
                        <span class="min-w-0 text-ink">
                            {{ $registro->estado_anterior === $registro->estado_nuevo ? '' : (\App\Models\Pedido::ESTADOS[$registro->estado_nuevo] ?? $registro->estado_nuevo) }}
                            @if ($registro->nota)
                                <span class="text-muted">{{ $registro->nota }}</span>
                            @endif
                            <span class="block text-muted">{{ $registro->user?->name ?? 'Vitrina' }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</div>
