@extends('layouts.panel')

@section('title', 'Nuevo restaurante')

@php
    /**
     * @var \Illuminate\Support\Collection<int, \App\Models\Rubro> $rubros
     * @var \Illuminate\Support\Collection<int, \App\Models\Plan> $planes
     */
    $navActivo = 'superadmin-restaurantes';
    $diasPrueba = \App\Models\Suscripcion::DIAS_PRUEBA;
@endphp

@section('content')
    <div class="max-w-5xl" data-alta-restaurante>
        <a href="{{ route('panel.superadmin.restaurantes.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-icon name="chevron-left" size="14" /> Restaurantes</a>
        <h1 class="mt-2 text-title tracking-tight text-ink">Nuevo restaurante</h1>
        <p class="mt-1 text-sm text-muted">Empieza con {{ $diasPrueba }} días de prueba gratis, horario de 12:00 a 22:00, pago en efectivo y el área «Salón principal». El local queda cerrado hasta que su admin lo abra.</p>

        <form method="POST" action="{{ route('panel.superadmin.restaurantes.store') }}" data-ajax-form novalidate class="mt-6">
            @csrf
            @include('panel.configuracion._resumen', ['claves' => []])

            <div class="flex flex-col gap-8" data-form-campos>
                <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-local">
                    <div>
                        <h2 id="titulo-local" class="text-section font-semibold">El local</h2>
                        <p class="mt-1 text-sm text-muted">La dirección es el enlace de su vitrina y no se puede cambiar después.</p>
                    </div>
                    <div class="grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 sm:p-5">
                        <x-campo label="Nombre del local" for="alta-nombre" error="nombre" class="sm:col-span-2">
                            <x-input name="nombre" id="alta-nombre" maxlength="120" required autocomplete="off" data-alta-nombre />
                        </x-campo>
                        <x-campo label="Dirección de la vitrina" for="alta-slug" error="slug" hint="Minúsculas, números y guiones." class="sm:col-span-2">
                            <div class="flex items-center gap-2">
                                <span class="shrink-0 text-sm text-muted">{{ preg_replace('#^https?://#', '', url('/')) }}/</span>
                                <x-input name="slug" id="alta-slug" maxlength="140" required autocomplete="off" spellcheck="false" :hint="true" data-alta-slug />
                            </div>
                        </x-campo>
                        <x-campo label="Rubro" for="alta-rubro" error="rubro_id" optional>
                            <x-input as="select" name="rubro_id" id="alta-rubro">
                                <option value="">Sin rubro</option>
                                @foreach ($rubros as $rubro)
                                    <option value="{{ $rubro->id }}">{{ $rubro->nombre }}</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                        <x-campo label="Ciudad" for="alta-ciudad" error="ciudad">
                            <x-input name="ciudad" id="alta-ciudad" value="Huánuco" maxlength="80" required autocomplete="off" />
                        </x-campo>
                        <x-campo label="Celular" for="alta-telefono" error="telefono" optional>
                            <x-input name="telefono" id="alta-telefono" type="tel" inputmode="numeric" maxlength="15" autocomplete="off" placeholder="987654321" />
                        </x-campo>
                        <x-campo label="Dirección física" for="alta-direccion" error="direccion" optional>
                            <x-input name="direccion" id="alta-direccion" maxlength="255" autocomplete="off" placeholder="Ej.: Jr. Huallayco 845" />
                        </x-campo>
                    </div>
                </section>

                <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-plan">
                    <div>
                        <h2 id="titulo-plan" class="text-section font-semibold">Plan</h2>
                        <p class="mt-1 text-sm text-muted">No paga durante la prueba. Al registrar su primer pago empieza a pagar este precio.</p>
                    </div>
                    <div class="rounded-card border border-line bg-surface p-4 sm:p-5">
                        <x-campo label="Plan" for="alta-plan" error="plan_id" class="sm:max-w-xs">
                            <x-input as="select" name="plan_id" id="alta-plan" required>
                                @foreach ($planes as $plan)
                                    <option value="{{ $plan->id }}" @selected($plan->nombre === 'Básico')>{{ $plan->nombre }}: S/ {{ number_format((float) $plan->precio_mensual, 2) }} al mes</option>
                                @endforeach
                            </x-input>
                        </x-campo>
                    </div>
                </section>

                <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-admin">
                    <div>
                        <h2 id="titulo-admin" class="text-section font-semibold">Su administrador</h2>
                        <p class="mt-1 text-sm text-muted">Le crearemos una contraseña temporal, que se muestra una sola vez.</p>
                    </div>
                    <div class="grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 sm:p-5">
                        <x-campo label="Nombre" for="alta-admin-nombre" error="admin_nombre">
                            <x-input name="admin_nombre" id="alta-admin-nombre" maxlength="120" required autocomplete="off" />
                        </x-campo>
                        <x-campo label="Correo" for="alta-admin-email" error="admin_email">
                            <x-input name="admin_email" id="alta-admin-email" type="email" maxlength="150" required autocomplete="off" />
                        </x-campo>
                    </div>
                </section>
            </div>

            <div class="mt-6">
                @include('panel.superadmin._enviar', ['texto' => 'Dar de alta', 'ocupado' => 'Dando de alta…'])
            </div>
        </form>
    </div>
@endsection

@push('modals')
    {{-- La contraseña llega en la respuesta JSON y se borra del DOM al cerrar --}}
    <x-modal id="modal-clave-alta" title="Contraseña temporal" size="sm">
        <div class="flex flex-col gap-4 text-sm">
            <p data-clave-mensaje></p>
            <div class="rounded-card bg-brand-50 p-4">
                <p class="text-xs text-muted">Para <span class="font-medium text-ink" data-clave-nombre></span> · <span data-clave-email></span></p>
                <p id="clave-alta-valor" class="clave-temporal mt-2 break-all text-2xl font-semibold text-brand-900" data-clave-valor></p>
            </div>
            <p class="flex items-start gap-2 rounded-control bg-warning-bg px-3 py-2.5 text-warning-ink">
                <x-icon name="alert" class="mt-0.5" />
                <span>Cópiala y entrégala ahora: <strong class="font-semibold">no se vuelve a mostrar</strong>. Al entrar, le pediremos cambiarla.</span>
            </p>
        </div>
        <x-slot:footer>
            <button type="button" class="press inline-flex h-11 items-center justify-center gap-2 rounded-control border border-control bg-surface px-4 text-sm font-medium text-ink hover:bg-raised lg:h-10" data-copiar="" data-copiar-respaldo="clave-alta-valor">
                <x-icon name="copy" /><span data-copiar-texto>Copiar contraseña</span>
            </button>
            <x-button variant="primary" data-modal-close>Listo, ver el local</x-button>
        </x-slot:footer>
    </x-modal>
@endpush
