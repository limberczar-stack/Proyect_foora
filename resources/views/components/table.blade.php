@props([
    'columns' => [],
    'caption' => null,
    'loading' => false,
    'empty' => null,
    'error' => null,
    'skeletonRows' => 4,
    'stack' => true,
])

@php
    /**
     * Tabla de FOORA: cabecera gris en minúscula inicial, líneas de 1px, cifras a la derecha.
     *
     * $columns = [['label' => 'Total', 'align' => 'right', 'class' => 'w-28'], ...]
     * Las filas van en el slot como <tr>. Para el modo lista en celular (stack), marca cada <td>
     * con data-cell="lead" (línea 1), "meta" (línea 2, con data-label), "end" (cifra o acción a la derecha)
     * o "hide-sm" (se oculta en celular porque ya aparece en otra línea).
     *
     * Estados: loading (filas esqueleto con la misma altura), empty (una frase a todo el ancho),
     * error (una frase + Reintentar). La cabecera se mantiene en todos.
     *
     * @var list<array{label: string, align?: string, class?: string}> $columns
     */
    $colspan = max(1, count($columns));
    $showRows = ! $loading && ! $error && ! $empty;
@endphp

<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }} @if ($loading) aria-busy="true" @endif>
    <table @class(['w-full border-collapse text-left text-sm', 'table-stack' => $stack && $showRows])>
        @if ($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        <thead>
            <tr class="border-b border-line">
                @foreach ($columns as $column)
                    <th
                        scope="col"
                        @class([
                            'h-10 whitespace-nowrap px-4 text-xs font-normal text-muted first:pl-4 last:pr-4 sm:first:pl-5 sm:last:pr-5',
                            'text-right' => ($column['align'] ?? 'left') === 'right',
                            'text-center' => ($column['align'] ?? 'left') === 'center',
                            $column['class'] ?? '',
                        ])
                    >{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="[&>tr]:border-b [&>tr]:border-rule [&>tr:last-child]:border-0 [&_td]:px-4 [&_td]:py-3 [&_td:first-child]:pl-4 [&_td:last-child]:pr-4 sm:[&_td:first-child]:pl-5 sm:[&_td:last-child]:pr-5">
            @if ($loading)
                @for ($i = 0; $i < $skeletonRows; $i++)
                    <tr aria-hidden="true">
                        @foreach ($columns as $column)
                            <td @class(['text-right' => ($column['align'] ?? 'left') === 'right'])>
                                <span @class(['skeleton h-3.5', 'ml-auto w-14' => ($column['align'] ?? 'left') === 'right', 'w-3/4' => ($column['align'] ?? 'left') !== 'right'])></span>
                            </td>
                        @endforeach
                    </tr>
                @endfor
                <tr class="sr-only"><td colspan="{{ $colspan }}" role="status">Cargando filas…</td></tr>
            @elseif ($error)
                <tr>
                    <td colspan="{{ $colspan }}">
                        <div class="flex flex-col items-start gap-3 py-2 sm:flex-row sm:items-center sm:justify-between" role="alert">
                            <p class="flex items-center gap-2 text-sm text-ink">
                                <x-icon name="alert" class="text-danger-ink" />
                                {{ $error }}
                            </p>
                            <x-button size="sm" icon="refresh" data-retry>Reintentar</x-button>
                        </div>
                    </td>
                </tr>
            @elseif ($empty)
                <tr>
                    <td colspan="{{ $colspan }}">
                        <p class="rounded-control border border-dashed border-control px-4 py-3 text-sm text-muted">{{ $empty }}</p>
                    </td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
</div>
