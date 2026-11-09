@extends('layouts.panel')

@section('title', 'Mi suscripción')

@php
    /**
     * Solo lectura para el admin del local. No muestra notas internas ni datos de FOORA.
     *
     * @var \App\Models\Restaurante $restaurante
     * @var ?\App\Models\Suscripcion $suscripcion
     * @var \Illuminate\Support\Collection<int, \App\Models\SuscripcionPago> $pagos
     */
    $navActivo = 'suscripcion';
    $columnas = [
        ['label' => 'Periodo'],
        ['label' => 'Método', 'class' => 'w-36'],
        ['label' => 'Monto', 'align' => 'right', 'class' => 'w-28'],
    ];
@endphp

@section('content')
    <div class="max-w-3xl">
        <h1 class="text-title tracking-tight text-ink">Mi suscripción</h1>
        <p class="mt-1 text-sm text-muted">Para cambiar de plan o renovar, contacta a FOORA.</p>

        @if ($suscripcion === null)
            <x-card class="mt-5" empty="Tu local todavía no tiene una suscripción registrada." />
        @else
            <x-card class="mt-5">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-[0.8125rem] text-muted">Plan</dt>
                        <dd class="mt-1 font-medium text-ink">{{ $suscripcion->plan?->nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.8125rem] text-muted">Estado</dt>
                        <dd class="mt-1"><x-badge :status="$restaurante->estadoSuscripcion()" /></dd>
                    </div>
                    <div>
                        <dt class="text-[0.8125rem] text-muted">Vence el</dt>
                        <dd class="num mt-1 font-medium text-ink">{{ $suscripcion->vence_el->format('d/m/Y') }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card class="mt-4" title="Pagos" :padded="false">
                @if ($pagos->isEmpty())
                    <p class="mx-4 mb-4 rounded-control border border-dashed border-control px-4 py-5 text-sm text-muted sm:mx-5 sm:mb-5">Todavía no hay pagos registrados.</p>
                @else
                    <x-table :columns="$columnas" caption="Pagos de la suscripción">
                        @foreach ($pagos as $pago)
                            <tr>
                                <td data-cell="lead" class="num text-ink">{{ $pago->periodo_desde->format('d/m/Y') }} al {{ $pago->periodo_hasta->format('d/m/Y') }}</td>
                                <td data-cell="meta" data-label="Método" class="text-muted">{{ $pago->metodo }}</td>
                                <td data-cell="end" class="num whitespace-nowrap text-right font-medium text-ink">S/&nbsp;{{ number_format((float) $pago->monto, 2) }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </x-card>
        @endif
    </div>
@endsection
