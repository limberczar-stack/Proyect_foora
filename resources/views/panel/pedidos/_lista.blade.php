@php
    /**
     * Pestañas, filtros y tabla. Se reemplaza entero por AJAX al actualizar.
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\Pedido> $pedidos
     * @var array<string, string> $pestanas
     * @var array<string, int> $contadores
     * @var string $estado
     * @var ?string $tipo
     * @var string $busqueda
     * @var string $zonaHoraria
     */
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $enlace = fn (array $cambios): string => route('panel.pedidos.index', array_filter([
        'estado' => $estado,
        'tipo' => $tipo,
        'q' => $busqueda !== '' ? $busqueda : null,
        ...$cambios,
    ]));
    $columnas = [
        ['label' => 'Pedido'],
        ['label' => 'Tipo', 'class' => 'w-28'],
        ['label' => 'Pago', 'class' => 'w-32'],
        ['label' => 'Total', 'align' => 'right', 'class' => 'w-24'],
    ];
    $vacios = [
        'nuevo' => 'No hay pedidos nuevos. Cuando llegue uno, aparecerá aquí con un aviso.',
        'en_preparacion' => 'No hay pedidos en preparación.',
        'listo' => 'No hay pedidos listos.',
        'en_camino' => 'No hay pedidos en camino.',
        'entregado' => 'Todavía no hay pedidos entregados hoy.',
        'cancelado' => 'No hay pedidos cancelados hoy.',
    ];
@endphp

{{-- Compactas desde lg; si aun así no caben (p. ej. 1366 px con el detalle abierto), el borde se desvanece y aparece una flecha --}}
<div class="scroll-tabs -mx-4 sm:mx-0" data-scroll-tabs>
    <nav aria-label="Estados de los pedidos" class="scroll-tabs-pista overflow-x-auto px-4 sm:px-0">
        <ul class="flex min-w-max gap-1 border-b border-line lg:gap-0.5">
            @foreach ($pestanas as $clave => $texto)
                @php $activa = $clave === $estado; @endphp
                <li>
                    <a href="{{ $enlace(['estado' => $clave, 'page' => null]) }}" @if ($activa) aria-current="page" @endif
                        @class([
                            'tab-indicator -mb-px inline-flex h-11 items-center gap-2 whitespace-nowrap px-3 text-sm lg:h-10 lg:gap-1.5 lg:px-2 lg:text-[0.8125rem]',
                            'font-medium text-ink shadow-[inset_0_-2px_0_var(--color-brand-700)]' => $activa,
                            'text-muted hover:text-ink' => ! $activa,
                        ])>
                        {{ $texto }}
                        <span data-contador="{{ $clave }}" @class([
                            'num inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium lg:px-1',
                            'bg-brand-100 text-brand-800' => $activa,
                            'bg-raised text-muted' => ! $activa,
                        ])>{{ $contadores[$clave] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
    <button type="button" class="scroll-tabs-flecha" data-lado="inicio" tabindex="-1" aria-hidden="true"><x-icon name="chevron-left" size="16" /></button>
    <button type="button" class="scroll-tabs-flecha" data-lado="fin" tabindex="-1" aria-hidden="true"><x-icon name="chevron-right" size="16" /></button>
</div>

<div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
    <div class="flex gap-1" role="group" aria-label="Tipo de pedido">
        @foreach ([null => 'Todos', 'delivery' => 'Delivery', 'recojo' => 'Recojo'] as $valor => $texto)
            @php $activo = ($tipo ?? '') === (string) $valor; @endphp
            <a href="{{ $enlace(['tipo' => $valor ?: null, 'page' => null]) }}" @if ($activo) aria-current="true" @endif
                @class([
                    'press inline-flex h-10 items-center rounded-full border px-3.5 text-sm lg:h-9',
                    'border-brand-700 bg-brand-700 font-medium text-white' => $activo,
                    'border-line bg-surface text-ink hover:bg-raised' => ! $activo,
                ])>{{ $texto }}</a>
        @endforeach
    </div>
    <form method="GET" action="{{ route('panel.pedidos.index') }}" role="search" class="sm:ml-auto sm:w-72">
        <input type="hidden" name="estado" value="{{ $estado }}">
        @if ($tipo)
            <input type="hidden" name="tipo" value="{{ $tipo }}">
        @endif
        <label for="buscar-pedido" class="sr-only">Buscar por número, cliente o teléfono</label>
        <input id="buscar-pedido" name="q" type="search" value="{{ $busqueda }}" maxlength="60" placeholder="Buscar n.°, cliente o teléfono" autocomplete="off"
            class="h-11 w-full rounded-control border border-control bg-surface px-3 text-sm placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 lg:h-10">
    </form>
</div>

<x-card class="mt-3" :padded="false">
    @if ($pedidos->isEmpty())
        <p class="m-4 rounded-control border border-dashed border-control px-4 py-5 text-sm text-muted sm:m-5">
            @if ($busqueda !== '')
                Ningún pedido coincide con «{{ $busqueda }}».
                <a href="{{ $enlace(['q' => null]) }}" class="font-medium text-brand-700 underline-offset-2 hover:underline">Quitar búsqueda</a>
            @else
                {{ $vacios[$estado] }}
            @endif
        </p>
    @else
        <x-table :columns="$columnas" caption="Pedidos: {{ $pestanas[$estado] }}">
            @foreach ($pedidos as $pedido)
                <tr class="cursor-pointer hover:bg-raised/60 aria-selected:bg-active" data-fila-pedido="{{ $pedido->id }}">
                    <td data-cell="lead">
                        <a href="{{ route('panel.pedidos.index', ['estado' => $pedido->estado, 'pedido' => $pedido->id]) }}"
                            data-abrir-pedido="{{ route('panel.pedidos.show', $pedido) }}"
                            class="flex flex-col rounded-chip focus-visible:outline-offset-4">
                            <span class="flex items-center gap-2">
                                <span class="num font-semibold text-ink">#{{ $pedido->numero }}</span>
                                <span class="num text-[0.8125rem] text-muted">{{ $pedido->created_at->timezone($zonaHoraria)->format('H:i') }}</span>
                            </span>
                            <span class="mt-0.5 truncate text-[0.8125rem] text-muted">{{ $pedido->nombre_cliente }}</span>
                        </a>
                    </td>
                    <td data-cell="meta" data-label="Tipo" class="text-muted">{{ $pedido->tipo === 'delivery' ? 'Delivery' : 'Recojo' }}</td>
                    <td data-cell="meta" data-label="Pago">
                        <x-badge :status="$pedido->estado_pago" />
                    </td>
                    <td data-cell="end" class="num whitespace-nowrap text-right font-medium text-ink">{{ $soles($pedido->total) }}</td>
                </tr>
            @endforeach
        </x-table>

        <div class="flex flex-col items-start gap-3 border-t border-rule px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <p class="num text-[0.8125rem] text-muted">{{ $pedidos->firstItem() }}–{{ $pedidos->lastItem() }} de {{ $pedidos->total() }}</p>
            @if ($pedidos->hasPages())
                <div class="flex gap-2">
                    <x-button :href="$pedidos->previousPageUrl()" size="sm" :disabled="$pedidos->onFirstPage()">Anterior</x-button>
                    <x-button :href="$pedidos->nextPageUrl()" size="sm" :disabled="! $pedidos->hasMorePages()">Siguiente</x-button>
                </div>
            @endif
        </div>
    @endif
</x-card>
