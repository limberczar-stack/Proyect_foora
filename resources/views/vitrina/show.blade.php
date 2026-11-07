@extends('layouts.publico')

@php
    /**
     * @var \App\Models\Restaurante $restaurante
     * @var list<array<string, mixed>> $secciones
     * @var bool $abierto
     * @var array<string, mixed> $datosVitrina
     */
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $descripcionMeta = \Illuminate\Support\Str::limit(
        $restaurante->descripcion ?: "Mira la carta de {$restaurante->nombre} en {$restaurante->ciudad} y arma tu pedido.",
        155,
    );
    $horarioDeHoy = $restaurante->horarioDeHoyTexto();
    $metodosPago = $restaurante->metodosPago->pluck('nombre')->implode(', ');
    $ubicacion = collect([$restaurante->direccion, $restaurante->ciudad])->filter()->implode(', ');
@endphp

@section('title', $restaurante->nombre)

@push('meta')
    <meta name="description" content="{{ $descripcionMeta }}">
    <link rel="canonical" href="{{ route('vitrina.show', $restaurante->slug) }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="FOORA">
    <meta property="og:title" content="{{ $restaurante->nombre }}">
    <meta property="og:description" content="{{ $descripcionMeta }}">
    <meta property="og:url" content="{{ route('vitrina.show', $restaurante->slug) }}">
    @if ($restaurante->logoUrl())
        <meta property="og:image" content="{{ $restaurante->logoUrl() }}">
    @endif
    <meta name="twitter:card" content="summary">
@endpush

@section('content')
    <header class="bg-brand-800 text-white">
        <div class="mx-auto max-w-5xl px-4 pb-6 pt-7 sm:px-6">
            <div class="flex items-center gap-4">
                @if ($restaurante->logoUrl())
                    <img src="{{ $restaurante->logoUrl() }}" alt="" width="72" height="72" class="size-[4.5rem] shrink-0 rounded-card bg-white object-cover">
                @else
                    <span class="flex size-[4.5rem] shrink-0 items-center justify-center rounded-card bg-brand-950 text-2xl font-bold" aria-hidden="true">{{ $restaurante->iniciales() }}</span>
                @endif
                <div class="min-w-0">
                    <h1 class="text-[1.75rem] font-bold leading-tight tracking-tight sm:text-4xl">{{ $restaurante->nombre }}</h1>
                    <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-100">
                        <x-badge :tone="$abierto ? 'success' : 'danger'">{{ $abierto ? 'Abierto' : 'Cerrado' }}</x-badge>
                        @if ($horarioDeHoy)
                            <span>{{ $horarioDeHoy }}</span>
                        @endif
                    </p>
                </div>
            </div>

            @if ($restaurante->descripcion)
                <p class="mt-4 max-w-2xl text-base leading-relaxed">{{ $restaurante->descripcion }}</p>
            @endif
            @if ($ubicacion !== '')
                <p class="mt-1 text-sm text-brand-100">{{ $ubicacion }}</p>
            @endif
        </div>
    </header>

    <div class="border-b border-line bg-surface">
        <dl class="mx-auto grid max-w-5xl grid-cols-3 gap-3 px-4 py-4 sm:px-6">
            <div>
                <dt class="text-xs text-muted">Delivery</dt>
                <dd class="num mt-0.5 text-sm font-semibold text-ink">
                    @if (! $restaurante->acepta_delivery)
                        Solo recojo
                    @elseif ((float) $restaurante->costo_delivery === 0.0)
                        Gratis
                    @else
                        {{ $soles($restaurante->costo_delivery) }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted">Pedido mínimo</dt>
                <dd class="num mt-0.5 text-sm font-semibold text-ink">{{ (float) $restaurante->pedido_minimo > 0 ? $soles($restaurante->pedido_minimo) : 'Sin mínimo' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted">Tiempo estimado</dt>
                <dd class="num mt-0.5 text-sm font-semibold text-ink">{{ $restaurante->tiempo_prep_min ? "{$restaurante->tiempo_prep_min} min" : 'Por confirmar' }}</dd>
            </div>
            @if ($metodosPago !== '')
                <div class="col-span-3">
                    <dt class="text-xs text-muted">Métodos de pago</dt>
                    <dd class="mt-0.5 text-sm font-medium text-ink">{{ $metodosPago }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <main class="mx-auto max-w-5xl px-4 pb-32 sm:px-6">
        @unless ($abierto)
            <div class="mt-4 flex items-start gap-2.5 rounded-control bg-warning-bg px-4 py-3 text-sm text-warning-ink" role="status">
                <x-icon name="clock" class="mt-0.5" />
                <p><strong class="font-semibold">Este local está cerrado ahora.</strong> Puedes ver la carta y preparar tu carrito.</p>
            </div>
        @endunless

        @if ($secciones === [])
            <x-card class="mt-6" empty="{{ $restaurante->nombre }} todavía no publica su carta. Vuelve pronto." />
        @else
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
        @endif

        <footer class="mt-12 text-center text-xs text-muted">Carta digital con FOORA · Reserva, ordena y disfruta</footer>
    </main>

    @include('vitrina._carrito_barra')

    <p class="sr-only" aria-live="polite" data-anuncio></p>

    <script>window.FOORA_VITRINA = {{ \Illuminate\Support\Js::from($datosVitrina) }};</script>
@endsection

@push('modals')
    @include('vitrina._hoja_producto')
    @include('vitrina._hoja_carrito', ['abierto' => $abierto])
@endpush
