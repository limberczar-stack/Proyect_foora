@php
    /**
     * Carta navegable: búsqueda, chips de categorías y secciones con sus productos. La usan la vitrina
     * pública y la toma de pedidos del salón; resources/js/carta/ le da comportamiento.
     *
     * @var list<array{categoria: \App\Models\CategoriaProducto, productos: list<\App\Models\Producto>, hijas: list<array{categoria: \App\Models\CategoriaProducto, productos: list<\App\Models\Producto>}>}> $secciones
     * @var \Closure(mixed): string $soles
     */
@endphp

<div class="mt-4" role="search">
    <label for="vitrina-buscar" class="sr-only">Buscar en la carta</label>
    <input id="vitrina-buscar" type="search" placeholder="Buscar en la carta" autocomplete="off" data-buscar
        class="h-12 w-full rounded-control border border-control bg-surface px-4 text-base text-ink placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 sm:max-w-sm">
</div>

<nav aria-label="Categorías de la carta" class="sticky top-0 z-20 -mx-4 mt-2 bg-ground px-4 pb-2 pt-2 sm:-mx-6 sm:px-6" data-chips>
    <ul class="vitrina-chips flex gap-2 overflow-x-auto">
        @foreach ($secciones as $seccion)
            <li class="shrink-0">
                <a href="#categoria-{{ $seccion['categoria']->id }}" data-chip="{{ $seccion['categoria']->id }}" class="vitrina-chip press">{{ $seccion['categoria']->nombre }}</a>
            </li>
        @endforeach
    </ul>
    @foreach ($secciones as $seccion)
        @if ($seccion['hijas'] !== [])
            <ul class="vitrina-chips mt-2 flex gap-2 overflow-x-auto" data-subchips="{{ $seccion['categoria']->id }}" hidden>
                @foreach ($seccion['hijas'] as $hija)
                    <li class="shrink-0">
                        <a href="#categoria-{{ $hija['categoria']->id }}" class="press inline-flex h-9 items-center rounded-full bg-brand-50 px-3 text-[0.8125rem] font-medium text-brand-800">{{ $hija['categoria']->nombre }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach
</nav>

<p class="mt-6 rounded-control border border-dashed border-control px-4 py-4 text-sm text-muted" data-sin-resultados hidden>
    Ningún producto coincide con «<span data-sin-resultados-texto></span>».
</p>

@foreach ($secciones as $seccion)
    <section id="categoria-{{ $seccion['categoria']->id }}" class="mt-7 scroll-mt-32" data-seccion="{{ $seccion['categoria']->id }}">
        <h2 class="text-xl font-bold tracking-tight text-ink">{{ $seccion['categoria']->nombre }}</h2>
        @if ($seccion['categoria']->descripcion)
            <p class="mt-0.5 text-sm text-muted">{{ $seccion['categoria']->descripcion }}</p>
        @endif

        @if ($seccion['productos'] !== [])
            @include('vitrina._productos', ['productos' => $seccion['productos']])
        @endif

        @foreach ($seccion['hijas'] as $hija)
            <section id="categoria-{{ $hija['categoria']->id }}" class="mt-5 scroll-mt-32" data-subseccion>
                <h3 class="text-base font-semibold text-ink">{{ $hija['categoria']->nombre }}</h3>
                @include('vitrina._productos', ['productos' => $hija['productos']])
            </section>
        @endforeach
    </section>
@endforeach
