@php
    $seccionesCarta = [
        'productos' => ['label' => 'Productos', 'href' => route('panel.carta.productos.index'), 'activa' => request()->routeIs('panel.carta.productos.*')],
        'categorias' => ['label' => 'Categorías', 'href' => route('panel.carta.categorias.index'), 'activa' => request()->routeIs('panel.carta.categorias.*')],
        'grupos' => ['label' => 'Grupos de opciones', 'href' => route('panel.carta.grupos.index'), 'activa' => request()->routeIs('panel.carta.grupos.*')],
    ];
@endphp

<nav aria-label="Secciones de la carta" class="mt-5 border-b border-line">
    <ul class="flex gap-1 overflow-x-auto">
        @foreach ($seccionesCarta as $seccion)
            <li>
                <a
                    href="{{ $seccion['href'] }}"
                    @if ($seccion['activa']) aria-current="page" @endif
                    @class([
                        'tab-indicator -mb-px inline-flex h-11 items-center whitespace-nowrap px-3 text-sm lg:h-10',
                        'font-medium text-ink shadow-[inset_0_-2px_0_var(--color-brand-700)]' => $seccion['activa'],
                        'text-muted hover:text-ink' => ! $seccion['activa'],
                    ])
                >{{ $seccion['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
