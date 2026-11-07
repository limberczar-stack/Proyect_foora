@extends('layouts.panel')

@section('title', 'Pedidos')

@php
    /**
     * @var ?\App\Models\Pedido $seleccionado
     * @var int $ultimoId
     * @var bool $sonido
     */
    $navActivo = 'pedidos';
@endphp

@section('content')
    <div
        data-pedidos
        data-url-novedades="{{ route('panel.pedidos.novedades') }}"
        data-ultimo-id="{{ $ultimoId }}"
        data-tipo="{{ $tipo }}"
        data-sonido="{{ $sonido ? 1 : 0 }}"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-title tracking-tight text-ink">Pedidos</h1>
                <p class="mt-1 text-sm text-muted">Se actualiza solo cada 10 segundos.</p>
            </div>
            @if ($sonido)
                <x-button icon="bell" data-activar-sonido>Activar sonido</x-button>
                <p class="flex items-center gap-2 text-sm text-success-ink" data-sonido-activo hidden><x-icon name="check" /> Sonido activado</p>
            @endif
        </div>

        <div class="mt-4 hidden items-start gap-2.5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert" data-error-vivo>
            <x-icon name="alert" class="mt-0.5" />
            <p class="flex-1">Sin conexión: no pudimos actualizar los pedidos.</p>
            <x-button size="sm" variant="secondary" icon="refresh" data-reintentar-vivo>Reintentar</x-button>
        </div>

        <p class="sr-only" aria-live="assertive" data-anuncio-pedidos></p>

        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_26rem] lg:items-start">
            <div class="min-w-0" data-lista>
                @include('panel.pedidos._lista')
            </div>

            <aside class="hidden lg:sticky lg:top-24 lg:block" aria-label="Detalle del pedido">
                <section class="rounded-card border border-line bg-surface" data-detalle-panel>
                    @if ($seleccionado)
                        @include('panel.pedidos._detalle', ['pedido' => $seleccionado])
                    @else
                        <div class="px-5 py-10 text-center text-sm text-muted" data-detalle-vacio>Elige un pedido para ver su detalle.</div>
                    @endif
                </section>
            </aside>
        </div>

        {{-- Aviso de pedido nuevo --}}
        <div class="aviso-pedido fixed inset-x-4 bottom-4 z-40 mx-auto flex max-w-md items-center gap-3 rounded-control bg-ink px-4 py-3 text-sm text-white shadow-float sm:inset-x-auto sm:right-6" role="status" data-aviso-nuevo hidden>
            <x-icon name="bell" class="shrink-0 text-brand-200" />
            <p class="min-w-0 flex-1" data-aviso-nuevo-texto></p>
            <button type="button" class="press -mr-1 rounded-chip px-2 py-1 text-brand-200 hover:text-white" data-aviso-cerrar>Cerrar</button>
        </div>
    </div>
@endsection

@push('modals')
    <dialog id="hoja-pedido" aria-label="Detalle del pedido" data-modal class="modal hoja m-0 mt-auto w-full max-w-none rounded-t-card bg-surface p-0 text-ink shadow-float backdrop:bg-transparent sm:m-auto sm:max-w-lg sm:rounded-card lg:hidden">
        <div class="max-h-[90dvh] overflow-y-auto" data-detalle-hoja></div>
    </dialog>
@endpush
