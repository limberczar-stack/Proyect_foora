@extends('layouts.panel')

@section('title', 'Resumen')

@php
    /**
     * @var int $activos
     * @var int $enPrueba
     * @var int $porVencer
     * @var int $vencidos
     * @var int $sinSuscripcion
     * @var int $suspendidos
     * @var float $ingresoMensual
     * @var \Illuminate\Support\Collection<int, \App\Models\SuscripcionPago> $ultimosPagos
     */
    $navActivo = 'superadmin-resumen';
    $lista = fn (string $estado): string => route('panel.superadmin.restaurantes.index', ['estado' => $estado]);
    $zona = \App\Models\Restaurante::ZONA_POR_DEFECTO;
    $columnasPagos = [
        ['label' => 'Local'],
        ['label' => 'Fecha', 'class' => 'w-28'],
        ['label' => 'Método', 'class' => 'w-36'],
        ['label' => 'Monto', 'align' => 'right', 'class' => 'w-28'],
    ];
@endphp

@section('content')
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Resumen</h1>
            <p class="mt-1 text-sm text-muted">Cómo están hoy los locales de FOORA.</p>
        </div>
        <x-button variant="primary" icon="plus" :href="route('panel.superadmin.restaurantes.create')">Nuevo restaurante</x-button>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
        <x-stat label="Ingreso mensual estimado" prefix="S/" :value="number_format($ingresoMensual, 2)" caption="Suscripciones activas, sin suspendidos" class="col-span-2 lg:col-span-1" />
        <x-stat label="Restaurantes activos" :value="$activos" caption="Con suscripción activa" :href="$lista('activa')" />
        <x-stat label="En prueba" :value="$enPrueba" caption="Sin pagar todavía" :href="$lista('prueba')" />
        <x-stat label="Por vencer en 7 días" :value="$porVencer" caption="Prueba o suscripción" :caption-tone="$porVencer > 0 ? 'negative' : 'neutral'" />
        <x-stat label="Vencidos" :value="$vencidos" caption="En gracia o fuera de operación" :caption-tone="$vencidos > 0 ? 'negative' : 'neutral'" :href="$lista('vencida')" />
        <x-stat label="Locales sin suscripción" :value="$sinSuscripcion" caption="Asígnales un plan" :caption-tone="$sinSuscripcion > 0 ? 'negative' : 'neutral'" :href="$lista('sin_suscripcion')" />
    </div>

    @if ($suspendidos > 0)
        <p class="mt-3 text-sm text-muted">
            Además hay <a href="{{ $lista('suspendidos') }}" class="num font-medium text-brand-700 underline-offset-2 hover:underline">{{ $suspendidos }} {{ $suspendidos === 1 ? 'local suspendido' : 'locales suspendidos' }}</a>, que no se cuentan arriba salvo en «sin suscripción».
        </p>
    @endif

    <x-card class="mt-5" title="Últimos pagos registrados" :padded="false">
        @if ($ultimosPagos->isEmpty())
            <p class="mx-4 mb-4 rounded-control border border-dashed border-control px-4 py-5 text-sm text-muted sm:mx-5 sm:mb-5">Todavía no se ha registrado ningún pago.</p>
        @else
            <x-table :columns="$columnasPagos" caption="Últimos pagos registrados">
                @foreach ($ultimosPagos as $pago)
                    @php $local = $pago->suscripcion?->restaurante; @endphp
                    <tr>
                        <td data-cell="lead">
                            @if ($local)
                                <a href="{{ route('panel.superadmin.restaurantes.show', $local) }}" class="font-medium text-ink underline-offset-2 hover:underline">{{ $local->nombre }}</a>
                            @else
                                <span class="text-muted">Local eliminado</span>
                            @endif
                            @if ($pago->estaAnulado())
                                <x-badge status="anulado" class="ml-1" />
                            @endif
                        </td>
                        <td data-cell="meta" data-label="Fecha" class="num text-muted">{{ $pago->pagado_at?->timezone($zona)->format('d/m/Y') }}</td>
                        <td data-cell="meta" data-label="Método" class="text-muted">{{ $pago->metodo }}</td>
                        <td data-cell="end" @class(['num whitespace-nowrap text-right font-medium', 'text-ink' => ! $pago->estaAnulado(), 'text-faint line-through' => $pago->estaAnulado()])>S/&nbsp;{{ number_format((float) $pago->monto, 2) }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
@endsection
