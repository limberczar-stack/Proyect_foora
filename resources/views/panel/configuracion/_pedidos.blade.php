@use('App\Http\Requests\Configuracion\PedidosRequest')
@php
    /** @var \App\Models\Restaurante $restaurante */
    $monto = fn (string $campo): string => old($campo, number_format((float) $restaurante->{$campo}, 2, '.', ''));
@endphp

<div data-config-pedidos>
    <form method="POST" action="{{ route('panel.configuracion.pedidos.update') }}" data-ajax-form novalidate>
        @csrf
        @method('PUT')

        @include('panel.configuracion._resumen', ['claves' => []])

        <div class="flex flex-col gap-8" data-form-campos>
            <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-modalidades">
                <div>
                    <h2 id="titulo-modalidades" class="text-section font-semibold">Cómo te piden</h2>
                    <p class="mt-1 text-sm text-muted">Deja activa al menos una modalidad.</p>
                </div>
                <div class="rounded-card border border-line bg-surface px-4 py-2 sm:px-5">
                    <x-toggle name="acepta_delivery" id="pedidos-delivery" label="Delivery" description="Llevas el pedido a la dirección del cliente." :checked="(bool) old('acepta_delivery', $restaurante->acepta_delivery)" />
                    <x-toggle name="acepta_recojo" id="pedidos-recojo" label="Recojo en tienda" description="El cliente pasa a recoger su pedido." :checked="(bool) old('acepta_recojo', $restaurante->acepta_recojo)" class="border-t border-rule" />
                    <p id="error-acepta_delivery" data-error-for="acepta_delivery" class="pb-2 text-xs text-danger-ink" @unless ($errors->has('acepta_delivery')) hidden @endunless>{{ $errors->first('acepta_delivery') }}</p>
                </div>
            </section>

            <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-montos">
                <div>
                    <h2 id="titulo-montos" class="text-section font-semibold">Montos y tiempos</h2>
                    <p class="mt-1 text-sm text-muted">Se muestran al cliente antes de confirmar.</p>
                </div>
                <div class="grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-3 sm:p-5">
                    <x-campo label="Costo del delivery" for="pedidos-costo" error="costo_delivery" hint="En soles. 0 si es gratis.">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted" aria-hidden="true">S/</span>
                            <x-input name="costo_delivery" id="pedidos-costo" type="text" inputmode="decimal" :value="$monto('costo_delivery')" class="num pl-9" maxlength="6" :hint="true" />
                        </div>
                    </x-campo>
                    <x-campo label="Pedido mínimo" for="pedidos-minimo" error="pedido_minimo" hint="En soles. 0 si no tienes.">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted" aria-hidden="true">S/</span>
                            <x-input name="pedido_minimo" id="pedidos-minimo" type="text" inputmode="decimal" :value="$monto('pedido_minimo')" class="num pl-9" maxlength="7" :hint="true" />
                        </div>
                    </x-campo>
                    <x-campo label="Tiempo de preparación" for="pedidos-tiempo" error="tiempo_prep_min" optional hint="En minutos, de {{ PedidosRequest::TIEMPO_PREP_MINIMO }} a {{ PedidosRequest::TIEMPO_PREP_MAXIMO }}.">
                        <x-input name="tiempo_prep_min" id="pedidos-tiempo" type="number" inputmode="numeric" :min="PedidosRequest::TIEMPO_PREP_MINIMO" :max="PedidosRequest::TIEMPO_PREP_MAXIMO" step="1" :value="old('tiempo_prep_min', $restaurante->tiempo_prep_min)" class="num" :hint="true" />
                    </x-campo>
                </div>
            </section>

            <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-recepcion">
                <div>
                    <h2 id="titulo-recepcion" class="text-section font-semibold">Al llegar un pedido</h2>
                    <p class="mt-1 text-sm text-muted">Cómo entra al panel y si te ven en la app.</p>
                </div>
                <div class="rounded-card border border-line bg-surface px-4 py-2 sm:px-5">
                    <x-toggle name="acepta_pedidos_auto" id="pedidos-auto" label="Aceptar pedidos automáticamente" description="Pasan directo a «En preparación», sin que nadie los acepte." :checked="(bool) old('acepta_pedidos_auto', $restaurante->acepta_pedidos_auto)" />
                    <p class="mb-3 flex items-start gap-2 rounded-control bg-info-bg px-3 py-2.5 text-xs text-info-ink">
                        <x-icon name="wallet" size="14" class="mt-px" />
                        <span>Los pedidos pagados con Yape o Plin siempre entran como «Nuevo», aunque esto esté activo: primero tienes que validar el pago.</span>
                    </p>
                    <x-toggle name="sonido_nuevos_pedidos" id="pedidos-sonido" label="Sonido de pedidos nuevos" description="Suena en la pantalla de Pedidos cuando llega uno." :checked="(bool) old('sonido_nuevos_pedidos', $restaurante->sonido_nuevos_pedidos)" class="border-t border-rule" />
                    <x-toggle name="visible_en_app" id="pedidos-visible" label="Visible en la app de FOORA" description="Si lo apagas, solo te encuentran con el enlace de tu vitrina." :checked="(bool) old('visible_en_app', $restaurante->visible_en_app)" class="border-t border-rule" />
                </div>
            </section>
        </div>

        @include('panel.configuracion._guardar')
    </form>
</div>
