@extends('layouts.panel')

@section('title', 'Restaurantes')

@php
    /**
     * @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\Restaurante> $restaurantes
     * @var array{q: string, estado?: ?string, plan?: ?int, ciudad?: ?string} $filtros
     * @var \Illuminate\Support\Collection<int, \App\Models\Plan> $planes
     * @var \Illuminate\Support\Collection<int, string> $ciudades
     * @var array<string, string> $estados
     */
    $navActivo = 'superadmin-restaurantes';
    $hayFiltros = $filtros['q'] !== '' || filled($filtros['estado'] ?? null) || filled($filtros['plan'] ?? null) || filled($filtros['ciudad'] ?? null);
    $columnas = [
        ['label' => 'Restaurante'],
        ['label' => 'Plan', 'class' => 'w-24'],
        ['label' => 'Suscripción', 'class' => 'w-36'],
        ['label' => 'Vence', 'class' => 'w-28'],
        ['label' => 'Pedidos del mes', 'align' => 'right', 'class' => 'w-32'],
        ['label' => 'Estado', 'class' => 'w-32'],
    ];
    $control = 'h-11 w-full rounded-control border border-line bg-surface px-3 text-sm text-ink focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 lg:h-10';
@endphp

@section('content')
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-title tracking-tight text-ink">Restaurantes</h1>
            <p class="mt-1 text-sm text-muted">Todos los locales de FOORA, con su plan y su suscripción.</p>
        </div>
        <x-button variant="primary" icon="plus" :href="route('panel.superadmin.restaurantes.create')">Nuevo restaurante</x-button>
    </div>

    <form method="GET" action="{{ route('panel.superadmin.restaurantes.index') }}" role="search" data-loading-form class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_12rem_10rem_11rem_auto]">
        <div class="sm:col-span-2 lg:col-span-1">
            <label for="filtro-q" class="sr-only">Buscar por nombre, dirección o ciudad</label>
            <input id="filtro-q" name="q" type="search" value="{{ $filtros['q'] }}" maxlength="80" placeholder="Buscar por nombre, dirección o ciudad" autocomplete="off" class="{{ $control }} placeholder:text-faint">
        </div>
        <div>
            <label for="filtro-estado" class="sr-only">Estado</label>
            <select id="filtro-estado" name="estado" class="{{ $control }}">
                <option value="">Todos los estados</option>
                @foreach ($estados as $clave => $texto)
                    <option value="{{ $clave }}" @selected(($filtros['estado'] ?? null) === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtro-plan" class="sr-only">Plan</label>
            <select id="filtro-plan" name="plan" class="{{ $control }}">
                <option value="">Todos los planes</option>
                @foreach ($planes as $plan)
                    <option value="{{ $plan->id }}" @selected((int) ($filtros['plan'] ?? 0) === $plan->id)>{{ $plan->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtro-ciudad" class="sr-only">Ciudad</label>
            <select id="filtro-ciudad" name="ciudad" class="{{ $control }}">
                <option value="">Todas las ciudades</option>
                @foreach ($ciudades as $ciudad)
                    <option value="{{ $ciudad }}" @selected(($filtros['ciudad'] ?? null) === $ciudad)>{{ $ciudad }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
            <button type="submit" class="press inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-control border border-control bg-surface px-4 text-sm font-medium text-ink hover:bg-raised lg:h-10 lg:flex-none" data-submit data-loading-text="Filtrando…">
                <span class="size-4 animate-spin-fast rounded-full border-2 border-brand-200 border-t-brand-700" data-submit-spinner aria-hidden="true" hidden></span>
                <span data-submit-label>Filtrar</span>
            </button>
            @if ($hayFiltros)
                <x-button variant="ghost" :href="route('panel.superadmin.restaurantes.index')">Limpiar</x-button>
            @endif
        </div>
    </form>

    <x-card class="mt-3" :padded="false">
        @if ($restaurantes->isEmpty())
            <div class="m-4 flex flex-col items-start gap-3 rounded-control border border-dashed border-control px-4 py-5 sm:m-5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted">{{ $hayFiltros ? 'Ningún restaurante coincide con estos filtros.' : 'Todavía no hay restaurantes. Da de alta el primero.' }}</p>
                @unless ($hayFiltros)
                    <x-button size="sm" icon="plus" :href="route('panel.superadmin.restaurantes.create')">Nuevo restaurante</x-button>
                @endunless
            </div>
        @else
            <x-table :columns="$columnas" caption="Restaurantes">
                @foreach ($restaurantes as $restaurante)
                    @php $estado = $restaurante->estadoSuscripcion(); @endphp
                    <tr class="hover:bg-raised/60">
                        <td data-cell="lead">
                            <a href="{{ route('panel.superadmin.restaurantes.show', $restaurante) }}" class="block rounded-chip focus-visible:outline-offset-4">
                                <span class="block truncate font-medium text-ink">{{ $restaurante->nombre }}</span>
                                <span class="block truncate text-[0.8125rem] text-muted">{{ $restaurante->ciudad }}@if ($restaurante->rubro) · {{ $restaurante->rubro->nombre }}@endif</span>
                            </a>
                        </td>
                        <td data-cell="meta" data-label="Plan" class="text-muted">{{ $restaurante->plan?->nombre ?? '—' }}</td>
                        <td data-cell="meta" data-label="Suscripción"><x-badge :status="$estado ?? 'sin_suscripcion'" /></td>
                        <td data-cell="meta" data-label="Vence" class="num text-muted">{{ $restaurante->suscripcionActual?->vence_el->format('d/m/Y') ?? '—' }}</td>
                        <td data-cell="meta" data-label="Pedidos del mes" class="num text-right text-ink">{{ $restaurante->pedidos_mes }}</td>
                        <td data-cell="meta" data-label="Estado"><x-badge :status="$restaurante->estaSuspendido() ? 'suspendido' : 'operando'" /></td>
                    </tr>
                @endforeach
            </x-table>

            <div class="flex flex-col items-start gap-3 border-t border-rule px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <p class="num text-[0.8125rem] text-muted">{{ $restaurantes->firstItem() }}–{{ $restaurantes->lastItem() }} de {{ $restaurantes->total() }}</p>
                @if ($restaurantes->hasPages())
                    <div class="flex gap-2">
                        <x-button :href="$restaurantes->previousPageUrl()" size="sm" :disabled="$restaurantes->onFirstPage()">Anterior</x-button>
                        <x-button :href="$restaurantes->nextPageUrl()" size="sm" :disabled="! $restaurantes->hasMorePages()">Siguiente</x-button>
                    </div>
                @endif
            </div>
        @endif
    </x-card>
@endsection
