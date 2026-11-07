@php
    /** @var array{nuevos_pedidos: bool, reservas: bool, alertas: bool} $notificaciones */
@endphp

<div data-config-notificaciones>
    <form method="POST" action="{{ route('panel.configuracion.notificaciones.update') }}" data-ajax-form novalidate>
        @csrf
        @method('PUT')

        @include('panel.configuracion._resumen', ['claves' => []])

        <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-avisos" data-form-campos>
            <div>
                <h2 id="titulo-avisos" class="text-section font-semibold">Avisos del panel</h2>
                <p class="mt-1 text-sm text-muted">Elige de qué quieres enterarte mientras tienes el panel abierto.</p>
            </div>
            <div class="rounded-card border border-line bg-surface px-4 py-2 sm:px-5">
                <x-toggle name="nuevos_pedidos" id="aviso-pedidos" label="Pedidos nuevos" description="Un aviso cada vez que entra un pedido." :checked="(bool) old('nuevos_pedidos', $notificaciones['nuevos_pedidos'])" />
                <x-toggle name="reservas" id="aviso-reservas" label="Reservas" description="Reservas nuevas, cambios de hora y cancelaciones." :checked="(bool) old('reservas', $notificaciones['reservas'])" class="border-t border-rule" />
                <x-toggle name="alertas" id="aviso-alertas" label="Alertas" description="Pagos por validar, pedidos sin respuesta y ocupación alta." :checked="(bool) old('alertas', $notificaciones['alertas'])" class="border-t border-rule" />
            </div>
        </section>

        @include('panel.configuracion._guardar')
    </form>
</div>
