@extends('layouts.panel')

@section('title', 'Tomar pedido · Cuenta #'.$cuenta->numero)

@php
    /**
     * Toma de pedido del mesero para una cuenta abierta. La carta es la misma de la vitrina
     * (vitrina._carta y vitrina._hoja_producto); el comportamiento lo dan resources/js/carta/ y
     * resources/js/salon-pedido.js, dentro del JS del panel (no se carga la entrada pública).
     *
     * El borrador vive en el navegador, por cuenta, solo con ids, cantidades, opciones y la nota de
     * cada línea. Los precios y el total siempre vienen del servidor.
     *
     * @var \App\Models\Cuenta $cuenta  con ocupacionesAbiertas.mesa
     * @var list<array<string, mixed>> $secciones
     * @var array<string, mixed> $datosSalon
     */
    $navActivo = 'mesas';
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
@endphp

@section('content')
    <div class="pb-28" data-toma-pedido>
        <a href="{{ $datosSalon['urlCuenta'] }}" class="press mb-1 inline-flex items-center gap-1 rounded-chip text-sm text-muted hover:text-ink"><x-icon name="chevron-left" /> Volver a la cuenta</a>
        <h1 class="text-title tracking-tight text-ink">{{ $cuenta->mesasTexto() ?: 'Cuenta #'.$cuenta->numero }}</h1>
        <p class="mt-1 text-sm text-muted">Cuenta #{{ $cuenta->numero }} · {{ $cuenta->comensales }} {{ $cuenta->comensales === 1 ? 'comensal' : 'comensales' }}. Arma la ronda y envíala a cocina.</p>

        @if ($secciones === [])
            <x-card class="mt-6" empty="La carta todavía no tiene productos activos. El administrador puede agregarlos en Carta." />
        @else
            @include('vitrina._carta')
        @endif
    </div>

    {{-- Barra fija con el resumen de la ronda; abre el borrador --}}
    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur lg:left-[17rem]" data-borrador-barra hidden>
        <button type="button" class="press flex h-12 w-full cursor-pointer items-center justify-between gap-3 rounded-control bg-brand-700 px-4 text-[0.9375rem] font-semibold text-white hover:bg-brand-800" data-borrador-abrir>
            <span>Ver ronda · <span class="num" data-borrador-cantidad>0</span></span>
            <span class="num" data-borrador-total>Calculando…</span>
        </button>
    </div>

    <p class="sr-only" aria-live="polite" data-anuncio></p>
    <script>window.FOORA_SALON = {{ \Illuminate\Support\Js::from($datosSalon) }};</script>
@endsection

@push('modals')
    @include('vitrina._hoja_producto')

    {{-- Borrador de la ronda --}}
    <dialog id="hoja-borrador" aria-labelledby="hoja-borrador-titulo" data-hoja class="modal hoja m-0 mt-auto w-full max-w-none rounded-t-card bg-surface p-0 text-ink shadow-float backdrop:bg-transparent sm:m-auto sm:max-w-lg sm:rounded-card">
        <div class="flex max-h-[90dvh] flex-col">
            <div class="flex touch-none justify-center pb-1 pt-2.5 sm:hidden" data-hoja-asa aria-hidden="true">
                <span class="h-1 w-10 rounded-full bg-control"></span>
            </div>
            <header class="flex touch-none items-start justify-between gap-4 px-5 pb-3 pt-1 sm:touch-auto sm:pt-4" data-hoja-asa>
                <h2 id="hoja-borrador-titulo" class="text-lg font-bold tracking-tight">Ronda para {{ $cuenta->mesasTexto() ?: 'la cuenta #'.$cuenta->numero }}</h2>
                <x-button variant="ghost" size="icon" data-hoja-cerrar aria-label="Cerrar" class="-mr-2 -mt-1.5"><x-icon name="x" /></x-button>
            </header>

            <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-4">
                <p class="rounded-control bg-danger-bg px-3 py-2.5 text-sm text-danger-ink" role="alert" data-borrador-error hidden></p>
                <p class="py-6 text-center text-sm text-muted" data-borrador-vacio>La ronda está vacía. Toca un producto de la carta para agregarlo.</p>
                <ul class="divide-y divide-rule" data-borrador-lineas aria-label="Productos de la ronda"></ul>
            </div>

            <footer class="border-t border-rule px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3" data-borrador-pie hidden>
                <div class="mb-3 flex items-baseline justify-between gap-3 text-base font-bold">
                    <span>Total de la ronda</span>
                    <span class="num" data-borrador-subtotal>Calculando…</span>
                </div>
                <button type="button" class="press flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-control bg-success-fill px-4 text-[0.9375rem] font-semibold text-white hover:bg-success-ink disabled:cursor-not-allowed disabled:opacity-60" data-borrador-enviar>
                    <span data-borrador-enviar-texto>Enviar a cocina</span>
                </button>
                <p class="mt-2 text-center text-xs text-muted" data-borrador-nota hidden></p>
            </footer>
        </div>
    </dialog>
@endpush
