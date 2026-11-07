@php
    /** @var \App\Models\Restaurante $restaurante */
    $tipos = array_filter([
        'delivery' => $restaurante->acepta_delivery ? 'Delivery' : null,
        'recojo' => $restaurante->acepta_recojo ? 'Recojo en local' : null,
    ]);
    $metodos = $restaurante->metodosPagoDisponibles();
    $campo = 'h-12 w-full rounded-control border border-line bg-surface px-3 text-base text-ink placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 aria-[invalid=true]:border-danger-dot';
    $errorCampo = 'mt-1.5 text-[0.8125rem] font-medium text-danger-ink';
@endphp

<dialog id="hoja-finalizar" aria-labelledby="hoja-finalizar-titulo" data-hoja class="modal hoja m-0 mt-auto w-full max-w-none rounded-t-card bg-surface p-0 text-ink shadow-float backdrop:bg-transparent sm:m-auto sm:max-w-lg sm:rounded-card">
    <form class="flex max-h-[92dvh] flex-col" data-finalizar-form novalidate>
        <div class="flex touch-none justify-center pb-1 pt-2.5 sm:hidden" data-hoja-asa aria-hidden="true">
            <span class="h-1 w-10 rounded-full bg-control"></span>
        </div>

        <header class="flex touch-none items-center justify-between gap-4 px-5 pb-3 pt-1 sm:touch-auto sm:pt-4" data-hoja-asa>
            <h2 id="hoja-finalizar-titulo" class="text-lg font-bold tracking-tight">Finalizar pedido</h2>
            <x-button variant="ghost" size="sm" class="-mr-2" data-finalizar-volver>Volver al carrito</x-button>
        </header>

        <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-4" data-finalizar-cuerpo>
            <div class="mb-4 rounded-control bg-danger-bg/50 p-4" role="alert" data-finalizar-error hidden>
                <p class="flex items-start gap-2 text-sm text-ink">
                    <x-icon name="alert" class="mt-0.5 text-danger-ink" />
                    <span data-finalizar-error-texto></span>
                </p>
                <div class="mt-3" data-finalizar-reintentar-caja hidden>
                    <x-button size="sm" icon="refresh" data-finalizar-reintentar>Reintentar</x-button>
                </div>
            </div>

            {{-- Campo trampa: invisible para las personas --}}
            <div class="absolute -left-[9999px] size-px overflow-hidden" aria-hidden="true">
                <label for="sitio-web">No llenes este campo</label>
                <input id="sitio-web" type="text" name="{{ \App\Http\Requests\PedidoRequest::CAMPO_TRAMPA }}" tabindex="-1" autocomplete="off" data-trampa>
            </div>

            <div class="flex flex-col gap-4">
                <div>
                    <label for="pedido-nombre" class="mb-2 block text-sm font-semibold">Tu nombre</label>
                    <input id="pedido-nombre" name="nombre" type="text" maxlength="120" autocomplete="name" aria-describedby="error-nombre" class="{{ $campo }}">
                    <p id="error-nombre" class="{{ $errorCampo }}" data-error="nombre" hidden></p>
                </div>

                <div>
                    <label for="pedido-telefono" class="mb-2 block text-sm font-semibold">Celular</label>
                    <input id="pedido-telefono" name="telefono" type="tel" inputmode="tel" maxlength="16" autocomplete="tel-national" placeholder="987 654 321" aria-describedby="error-telefono" class="{{ $campo }}">
                    <p id="error-telefono" class="{{ $errorCampo }}" data-error="telefono" hidden></p>
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-semibold">¿Cómo lo quieres?</legend>
                    <div @class(['grid gap-1 rounded-control bg-raised p-1', 'grid-cols-2' => count($tipos) === 2])>
                        @foreach ($tipos as $valor => $texto)
                            <label class="relative">
                                <input type="radio" name="tipo" value="{{ $valor }}" class="peer sr-only" data-tipo @checked($loop->first)>
                                <span class="press flex h-11 cursor-pointer items-center justify-center rounded-[0.5rem] text-sm text-muted peer-checked:bg-surface peer-checked:font-semibold peer-checked:text-ink peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">{{ $texto }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="{{ $errorCampo }}" data-error="tipo" hidden></p>
                </fieldset>

                <div class="flex flex-col gap-4" data-solo-delivery hidden>
                    <div>
                        <label for="pedido-direccion" class="mb-2 block text-sm font-semibold">Dirección de entrega</label>
                        <input id="pedido-direccion" name="direccion" type="text" maxlength="255" autocomplete="street-address" placeholder="Ej.: Jr. Dos de Mayo 1020" aria-describedby="error-direccion" class="{{ $campo }}">
                        <p id="error-direccion" class="{{ $errorCampo }}" data-error="direccion" hidden></p>
                    </div>
                    <div>
                        <label for="pedido-referencia" class="mb-2 block text-sm font-semibold">Referencia</label>
                        <input id="pedido-referencia" name="referencia" type="text" maxlength="255" placeholder="Ej.: casa azul con puerta negra" aria-describedby="error-referencia" class="{{ $campo }}">
                        <p id="error-referencia" class="{{ $errorCampo }}" data-error="referencia" hidden></p>
                    </div>
                </div>

                <fieldset>
                    <legend class="mb-1 text-sm font-semibold">Método de pago</legend>
                    @foreach ($metodos as $metodo)
                        <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-rule last:border-b-0">
                            <input type="radio" name="metodo_pago_id" value="{{ $metodo->id }}" class="size-[1.125rem] shrink-0 accent-brand-700" data-metodo data-por-verificar="{{ $metodo->requiere_validacion ? 1 : 0 }}">
                            <span class="text-sm">{{ $metodo->nombre }}</span>
                        </label>
                    @endforeach
                    <p class="{{ $errorCampo }}" data-error="metodo_pago_id" hidden></p>

                    @foreach ($metodos->where('requiere_validacion', true) as $metodo)
                        <div class="mt-3 rounded-control bg-brand-50 px-4 py-3 text-sm text-brand-900" data-pago-detalle="{{ $metodo->id }}" hidden>
                            <p>Paga con {{ $metodo->nombre }} al número</p>
                            <p class="num mt-0.5 text-lg font-bold">{{ $metodo->pivot->numero_cuenta }}</p>
                            @if ($metodo->pivot->titular)
                                <p>a nombre de {{ $metodo->pivot->titular }}.</p>
                            @endif
                        </div>
                    @endforeach

                    <div class="mt-3" data-pago-referencia hidden>
                        <label for="pedido-operacion" class="mb-2 block text-sm font-semibold">Código de operación <span class="font-normal text-muted">(opcional)</span></label>
                        <input id="pedido-operacion" name="referencia_pago" type="text" maxlength="50" autocomplete="off" aria-describedby="error-operacion" class="{{ $campo }}">
                        <p class="mt-1.5 text-xs text-muted">El local validará tu pago antes de preparar el pedido.</p>
                        <p id="error-operacion" class="{{ $errorCampo }}" data-error="referencia_pago" hidden></p>
                    </div>
                </fieldset>

                <div>
                    <p class="text-xs leading-relaxed text-muted">
                        Usaremos tu nombre, celular y dirección solo para que {{ $restaurante->nombre }} prepare y entregue este pedido, conforme a la Ley N.° 29733 de Protección de Datos Personales. Puedes pedirle al local acceder a tus datos, corregirlos o eliminarlos.
                    </p>
                    <label class="mt-2 flex cursor-pointer items-start gap-3 py-1">
                        <input type="checkbox" name="acepta_privacidad" class="mt-0.5 size-[1.125rem] shrink-0 accent-brand-700" data-privacidad>
                        <span class="text-sm">Acepto el uso de mis datos para este pedido.</span>
                    </label>
                    <p class="{{ $errorCampo }}" data-error="acepta_privacidad" hidden></p>
                </div>
            </div>
        </div>

        <footer class="border-t border-rule px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3">
            <dl class="flex flex-col gap-0.5 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted">Subtotal</dt><dd class="num" data-resumen-subtotal></dd></div>
                <div class="flex justify-between gap-3" data-resumen-delivery-fila><dt class="text-muted">Delivery</dt><dd class="num" data-resumen-delivery></dd></div>
                <div class="flex justify-between gap-3 text-base font-bold"><dt>Total</dt><dd class="num" data-resumen-total></dd></div>
            </dl>
            <button type="submit" data-finalizar-enviar class="press mt-3 flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-control bg-brand-700 px-4 text-[0.9375rem] font-semibold text-white hover:bg-brand-800 disabled:cursor-progress disabled:bg-brand-200 disabled:text-brand-900">
                <span data-finalizar-spinner hidden><x-spinner :size="16" label="Enviando" /></span>
                <span data-finalizar-etiqueta>Confirmar pedido</span>
            </button>
        </footer>
    </form>
</dialog>
