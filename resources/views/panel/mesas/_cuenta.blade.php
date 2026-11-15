@use('App\Services\GestionarCuentas')

@php
    /**
     * Detalle de una cuenta con sus acciones. Va dentro de la hoja inferior del plano (AJAX) o de la
     * página panel.mesas.cuenta. Cada acción es un formulario propio con la version que se está viendo:
     * si otra persona cambió la cuenta, el servidor responde 409 y salon.js vuelve a cargar el detalle.
     *
     * @var \App\Models\Cuenta $cuenta  con mesero y ocupacionesAbiertas.mesa
     * @var bool $demorada
     * @var \Illuminate\Support\Collection<int, \App\Models\Mesa> $disponibles  mesas que se pueden ocupar ahora
     * @var \Illuminate\Support\Collection<int, \App\Models\Cuenta> $otras  las demás cuentas abiertas
     * @var \Illuminate\Support\Collection<int, \App\Models\User> $personal  a quién se puede transferir
     */
    $mesas = $cuenta->ocupacionesAbiertas->pluck('mesa');
    $abierta = $cuenta->estaAbierta();
    $usuario = auth()->user();
    $accion = 'group rounded-control border border-line';
    $titular = 'press flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 text-sm font-medium text-ink [&::-webkit-details-marker]:hidden';
    $ruta = fn (string $nombre): string => route('panel.mesas.cuentas.'.$nombre, $cuenta);
@endphp

<div data-cuenta-detalle data-cuenta-id="{{ $cuenta->id }}" data-version="{{ $cuenta->version }}" data-titulo="Cuenta #{{ $cuenta->numero }}">
    <div class="flex flex-wrap items-center gap-2">
        <x-badge :tone="$abierta ? 'warning' : 'neutral'">{{ $cuenta->estadoEtiqueta() }}</x-badge>
        @if ($demorada)
            <x-badge tone="danger">Más de {{ GestionarCuentas::HORAS_PARA_RESALTAR }} h abierta</x-badge>
        @endif
    </div>

    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
        @if ($abierta)
            <div>
                <dt class="text-xs text-muted">{{ $mesas->count() === 1 ? 'Mesa' : 'Mesas' }}</dt>
                <dd class="font-medium text-ink" data-cuenta-mesas>{{ $mesas->pluck('codigo')->implode(' + ') }}</dd>
            </div>
        @endif
        <div>
            <dt class="text-xs text-muted">{{ $abierta ? 'Tiempo' : 'Duró' }}</dt>
            <dd class="num font-medium text-ink">{{ $cuenta->tiempoAbierta() }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted">Comensales</dt>
            <dd class="num font-medium text-ink">{{ $cuenta->comensales }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted">Atiende</dt>
            <dd class="truncate font-medium text-ink">{{ $cuenta->mesero?->name ?? 'Sin asignar' }}</dd>
        </div>
        @if ($cuenta->nota)
            <div class="col-span-2">
                <dt class="text-xs text-muted">Nota</dt>
                <dd class="text-ink">{{ $cuenta->nota }}</dd>
            </div>
        @endif
        @if ($cuenta->estado === 'cerrada')
            <div class="col-span-2">
                <dt class="text-xs text-muted">Cierre</dt>
                <dd class="text-ink">{{ $cuenta->cierre_tipo === 'externo' ? 'Cobrado en otro sistema' : 'Venta' }}@if ($cuenta->cerradaPor) · {{ $cuenta->cerradaPor->name }}@endif</dd>
            </div>
        @elseif ($cuenta->estado === 'anulada')
            <div class="col-span-2">
                <dt class="text-xs text-muted">Anulación</dt>
                <dd class="text-ink">{{ $cuenta->motivo_anulacion }}@if ($cuenta->anuladaPor) · {{ $cuenta->anuladaPor->name }}@endif</dd>
            </div>
        @elseif ($cuenta->estado === 'fusionada' && $cuenta->fusionadaEn)
            <div class="col-span-2">
                <dt class="text-xs text-muted">Unida con</dt>
                <dd class="text-ink">Cuenta #{{ $cuenta->fusionadaEn->numero }}</dd>
            </div>
        @endif
    </dl>

    @if ($abierta)
        <p class="mt-4 hidden rounded-control bg-danger-bg px-3 py-2.5 text-sm text-danger-ink" role="alert" data-cuenta-error></p>

        <div class="mt-4 flex flex-col gap-2" data-cuenta-acciones>
            @if ($disponibles->isNotEmpty() && $mesas->count() < GestionarCuentas::MESAS_MAXIMO)
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }}">Agregar una mesa <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('mesas.agregar') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        <x-campo label="Mesa disponible" for="cuenta-agregar-mesa" error="mesa_id">
                            <x-input as="select" name="mesa_id" id="cuenta-agregar-mesa">
                                @foreach ($disponibles as $mesa)
                                    <option value="{{ $mesa->id }}">{{ $mesa->codigo }} · {{ $mesa->area->nombre }} · {{ $mesa->capacidad }} pers.</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                        <x-button type="submit" variant="primary" data-cuenta-enviar data-texto="Agregar mesa" data-texto-ocupado="Agregando…">Agregar mesa</x-button>
                    </form>
                </details>
            @endif

            @if ($mesas->count() > 1)
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }}">Quitar una mesa <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('mesas.quitar') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        <x-campo label="Mesa que queda libre" for="cuenta-quitar-mesa" error="mesa_id">
                            <x-input as="select" name="mesa_id" id="cuenta-quitar-mesa">
                                @foreach ($mesas as $mesa)
                                    <option value="{{ $mesa->id }}">{{ $mesa->codigo }}</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                        <x-button type="submit" data-cuenta-enviar data-texto="Quitar mesa" data-texto-ocupado="Quitando…">Quitar mesa</x-button>
                    </form>
                </details>
            @endif

            @if ($disponibles->isNotEmpty())
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }}">Mover de mesa <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('mesas.mover') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        @if ($mesas->count() === 1)
                            <input type="hidden" name="desde_mesa_id" value="{{ $mesas->first()->id }}">
                        @else
                            <x-campo label="Deja la mesa" for="cuenta-mover-desde" error="desde_mesa_id">
                                <x-input as="select" name="desde_mesa_id" id="cuenta-mover-desde">
                                    @foreach ($mesas as $mesa)
                                        <option value="{{ $mesa->id }}">{{ $mesa->codigo }}</option>
                                    @endforeach
                                </x-input>
                            </x-campo>
                        @endif
                        <x-campo label="Pasa a la mesa" for="cuenta-mover-hacia" error="hacia_mesa_id">
                            <x-input as="select" name="hacia_mesa_id" id="cuenta-mover-hacia">
                                @foreach ($disponibles as $mesa)
                                    <option value="{{ $mesa->id }}">{{ $mesa->codigo }} · {{ $mesa->area->nombre }} · {{ $mesa->capacidad }} pers.</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                        <x-button type="submit" data-cuenta-enviar data-texto="Mover" data-texto-ocupado="Moviendo…">Mover</x-button>
                    </form>
                </details>
            @endif

            @if ($otras->isNotEmpty())
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }}">Unir con otra cuenta <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('fusionar') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        {{-- El valor lleva id y version de la otra cuenta: si cambió mientras tanto, el servidor responde 409. --}}
                        <x-campo label="Cuenta que se une a esta" for="cuenta-fusionar-otra" error="otra_cuenta_id" hint="Sus mesas pasan a esta cuenta y los comensales se suman.">
                            <x-input as="select" name="otra" id="cuenta-fusionar-otra" error="otra_cuenta_id" hint>
                                @foreach ($otras as $otra)
                                    <option value="{{ $otra->id }}:{{ $otra->version }}">#{{ $otra->numero }} · {{ $otra->ocupacionesAbiertas->pluck('mesa.codigo')->implode(' + ') }} · {{ $otra->comensales }} com.</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                        <x-button type="submit" data-cuenta-enviar data-texto="Unir cuentas" data-texto-ocupado="Uniendo…">Unir cuentas</x-button>
                    </form>
                </details>
            @endif

            @if ($personal->isNotEmpty())
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }}">Transferir a otra persona <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('transferir') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        <x-campo label="Pasa a" for="cuenta-transferir-mesero" error="mesero_id">
                            <x-input as="select" name="mesero_id" id="cuenta-transferir-mesero">
                                @foreach ($personal as $persona)
                                    <option value="{{ $persona->id }}">{{ $persona->name }} · {{ $persona->rolEtiqueta() }}</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                        <x-button type="submit" data-cuenta-enviar data-texto="Transferir" data-texto-ocupado="Transfiriendo…">Transferir</x-button>
                    </form>
                </details>
            @endif

            @if ($usuario->can('cerrar', $cuenta))
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }}">Cerrar: cobrado en otro sistema <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('cerrar') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        <p class="text-sm text-ink">La cuenta se cierra y {{ $mesas->count() === 1 ? 'su mesa queda disponible' : 'sus '.$mesas->count().' mesas quedan disponibles' }}. Úsalo cuando ya cobraste en tu sistema de ventas.</p>
                        <x-button type="submit" variant="success" data-cuenta-enviar data-texto="Cerrar cuenta" data-texto-ocupado="Cerrando…">Cerrar cuenta</x-button>
                    </form>
                </details>
            @endif

            @if ($usuario->can('anular', $cuenta))
                <details class="{{ $accion }}">
                    <summary class="{{ $titular }} text-danger-ink!">Anular cuenta <x-icon name="chevron-down" class="text-faint group-open:rotate-180" /></summary>
                    <form method="POST" action="{{ $ruta('anular') }}" class="flex flex-col gap-3 border-t border-rule p-3" data-cuenta-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $cuenta->version }}">
                        <x-campo label="Motivo" for="cuenta-anular-motivo" error="motivo" hint="Queda en el historial. Las mesas se liberan.">
                            <x-input name="motivo" id="cuenta-anular-motivo" maxlength="255" required autocomplete="off" placeholder="Ej.: se abrió por error" hint />
                        </x-campo>
                        <x-button type="submit" variant="danger" data-cuenta-enviar data-texto="Anular cuenta" data-texto-ocupado="Anulando…">Anular cuenta</x-button>
                    </form>
                </details>
            @endif
        </div>
    @endif
</div>
