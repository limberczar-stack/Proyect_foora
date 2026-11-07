@php
    /**
     * @var list<array{codigo: string, nombre: string, activo: bool, numero_cuenta: ?string, titular: ?string, con_cuenta: bool}> $metodos
     */
    $descripciones = [
        'efectivo' => 'El cliente paga al recibir o al recoger.',
        'yape' => 'El cliente paga a tu celular y tú validas el pago en el panel.',
        'plin' => 'El cliente paga a tu celular y tú validas el pago en el panel.',
        'tarjeta' => 'Cobras con tu POS al entregar o en caja.',
    ];
@endphp

<div data-config-pago>
    <form method="POST" action="{{ route('panel.configuracion.pago.update') }}" data-ajax-form novalidate>
        @csrf
        @method('PUT')

        @include('panel.configuracion._resumen', ['claves' => ['metodos']])

        <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-pago" data-form-campos>
            <div>
                <h2 id="titulo-pago" class="text-section font-semibold">Métodos de pago</h2>
                <p class="mt-1 text-sm text-muted">Tus clientes solo ven los que actives. Deja al menos uno.</p>
            </div>

            <ul class="divide-y divide-rule rounded-card border border-line bg-surface">
                @foreach ($metodos as $metodo)
                    @php
                        $codigo = $metodo['codigo'];
                        $activo = (bool) old("metodos.{$codigo}.activo", $metodo['activo']);
                    @endphp
                    <li class="px-4 py-3 sm:px-5" data-metodo="{{ $codigo }}">
                        <label for="metodo-{{ $codigo }}" class="flex min-h-11 cursor-pointer items-center justify-between gap-4">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink">{{ $metodo['nombre'] }}</span>
                                <span class="block text-xs text-muted">{{ $descripciones[$codigo] ?? '' }}</span>
                            </span>
                            <span class="relative inline-flex shrink-0">
                                <input type="hidden" name="metodos[{{ $codigo }}][activo]" value="0">
                                <input id="metodo-{{ $codigo }}" type="checkbox" role="switch" name="metodos[{{ $codigo }}][activo]" value="1" class="peer sr-only" data-metodo-activo @checked($activo)>
                                <span class="h-6 w-11 rounded-full bg-control transition-colors duration-200 peer-checked:bg-success-fill peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600"></span>
                                <span class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-out peer-checked:translate-x-5"></span>
                            </span>
                        </label>

                        @if ($metodo['con_cuenta'])
                            <div class="mt-3 grid gap-4 pb-1 sm:grid-cols-2" data-metodo-cuenta @unless ($activo) hidden @endunless>
                                <x-campo label="Celular de {{ $metodo['nombre'] }}" for="metodo-{{ $codigo }}-numero" error="metodos.{{ $codigo }}.numero_cuenta">
                                    <x-input name="metodos[{{ $codigo }}][numero_cuenta]" id="metodo-{{ $codigo }}-numero" type="tel" inputmode="numeric" maxlength="15"
                                        :value="old('metodos.'.$codigo.'.numero_cuenta', $metodo['numero_cuenta'])" placeholder="987654321" autocomplete="off" />
                                </x-campo>
                                <x-campo label="Titular" for="metodo-{{ $codigo }}-titular" error="metodos.{{ $codigo }}.titular" hint="Como aparece en {{ $metodo['nombre'] }}: así el cliente sabe que paga al local correcto.">
                                    <x-input name="metodos[{{ $codigo }}][titular]" id="metodo-{{ $codigo }}-titular" maxlength="120"
                                        :value="old('metodos.'.$codigo.'.titular', $metodo['titular'])" placeholder="Ej.: María Rojas" autocomplete="off" :hint="true" />
                                </x-campo>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        @include('panel.configuracion._guardar', ['textoBoton' => 'Guardar métodos de pago'])
    </form>
</div>
