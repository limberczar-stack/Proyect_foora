@php
    /** @var list<\App\Models\Producto> $productos */
@endphp

<ul class="mt-3 grid grid-cols-1 gap-2.5 md:grid-cols-2">
    @foreach ($productos as $producto)
        @php
            $etiquetas = collect($producto->etiquetas ?? [])
                ->map(fn (string $etiqueta): ?string => \App\Models\Producto::ETIQUETAS[$etiqueta] ?? null)
                ->filter()
                ->implode(' · ');
        @endphp
        <li data-producto-item data-nombre="{{ $producto->nombre }}">
            <button
                type="button"
                data-producto="{{ $producto->id }}"
                @disabled(! $producto->disponible)
                @class([
                    'flex min-h-24 w-full items-stretch gap-3 rounded-card border border-line bg-surface p-3 text-left',
                    'press vitrina-tarjeta cursor-pointer' => $producto->disponible,
                    'cursor-not-allowed' => ! $producto->disponible,
                ])
            >
                <span class="flex min-w-0 flex-1 flex-col">
                    <span @class(['text-[0.9375rem] font-semibold leading-snug', 'text-ink' => $producto->disponible, 'text-muted' => ! $producto->disponible])>{{ $producto->nombre }}</span>
                    @if ($producto->descripcion)
                        <span class="mt-0.5 line-clamp-2 text-[0.8125rem] leading-snug text-muted">{{ $producto->descripcion }}</span>
                    @endif
                    @if ($etiquetas !== '')
                        <span class="mt-1 text-xs text-brand-700">{{ $etiquetas }}</span>
                    @endif
                    <span class="mt-auto flex flex-wrap items-center gap-x-2 pt-1.5">
                        @if ($producto->disponible)
                            <span class="num text-[0.9375rem] font-semibold text-ink">
                                @if ($producto->tieneVariantes())
                                    <span class="font-normal text-muted">desde</span>
                                @endif
                                {{ $soles($producto->precio) }}
                            </span>
                        @else
                            <x-badge tone="neutral" :dot="false">Agotado</x-badge>
                        @endif
                        @if ($producto->esCombo())
                            <span class="text-[0.8125rem] text-muted">Para {{ $producto->personas }} {{ $producto->personas === 1 ? 'persona' : 'personas' }}</span>
                        @endif
                    </span>
                </span>
                @if ($producto->imagenUrl())
                    <img src="{{ $producto->imagenUrl() }}" alt="" loading="lazy" decoding="async" width="96" height="96"
                        @class(['size-24 shrink-0 self-center rounded-control object-cover', 'opacity-50 grayscale' => ! $producto->disponible])>
                @endif
            </button>
        </li>
    @endforeach
</ul>
