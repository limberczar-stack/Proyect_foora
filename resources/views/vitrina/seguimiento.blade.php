@extends('layouts.publico', ['entrada' => 'resources/js/seguimiento.js'])

@php
    /**
     * @var \App\Models\Restaurante $restaurante
     * @var \App\Models\Pedido $pedido
     * @var array<string, mixed> $seguimiento
     */
    $soles = fn ($monto): string => "S/\u{00A0}".number_format((float) $monto, 2);
    $whatsapp = $restaurante->whatsappUrl("Hola, te escribo por mi pedido #{$pedido->numero} en {$restaurante->nombre}.");
@endphp

@section('title', "Pedido #{$pedido->numero}")

@push('meta')
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
@endpush

@section('content')
    <header class="bg-brand-800 text-white">
        <div class="mx-auto max-w-xl px-4 pb-6 pt-5 sm:px-6">
            <a href="{{ route('vitrina.show', $restaurante->slug) }}" class="inline-flex min-h-10 items-center gap-1 text-sm text-brand-100">
                <x-icon name="chevron-right" size="14" class="rotate-180" /> {{ $restaurante->nombre }}
            </a>
            <h1 class="mt-2 text-[1.75rem] font-bold leading-tight tracking-tight">Pedido #{{ $pedido->numero }}</h1>
            <p class="mt-1 text-base">Hola, {{ $pedido->primerNombre() }}. {{ $pedido->tipoTexto() }}.</p>
        </div>
    </header>

    <main class="mx-auto flex max-w-xl flex-col gap-4 px-4 py-5 sm:px-6">
        <p class="sr-only" aria-live="polite" data-seguimiento-anuncio></p>

        <section aria-labelledby="estado-titulo" class="rounded-card border border-line bg-surface p-5">
            <h2 id="estado-titulo" class="text-xl font-bold tracking-tight" data-seguimiento-titulo>{{ $seguimiento['titulo'] }}</h2>
            <p class="mt-0.5 text-sm text-muted" data-seguimiento-detalle>{{ $seguimiento['detalle'] }}</p>

            <p class="mt-3 rounded-control bg-danger-bg px-4 py-3 text-sm font-medium text-danger-ink" data-seguimiento-motivo @unless ($seguimiento['cancelado']) hidden @endunless>{{ $seguimiento['motivo'] }}</p>

            <ol class="mt-4 flex flex-col" data-seguimiento-pasos @if ($seguimiento['cancelado']) hidden @endif>
                @foreach ($seguimiento['pasos'] as $paso)
                    <li class="seguimiento-paso" data-paso="{{ $paso['clave'] }}" data-estado="{{ $paso['estado'] }}">
                        <span class="seguimiento-punto" aria-hidden="true"></span>
                        <span class="seguimiento-texto">{{ $paso['texto'] }}</span>
                    </li>
                @endforeach
            </ol>

            <p class="mt-3 hidden text-xs text-warning-ink" role="status" data-seguimiento-desconectado>No pudimos actualizar el estado. Lo seguimos intentando.</p>
        </section>

        <section aria-labelledby="resumen-titulo" class="rounded-card border border-line bg-surface p-5">
            <h2 id="resumen-titulo" class="text-base font-semibold">Tu pedido</h2>
            <ul class="mt-2 flex flex-col divide-y divide-rule">
                @foreach ($pedido->items as $item)
                    <li class="py-2.5">
                        <div class="flex items-start justify-between gap-3 text-sm font-medium">
                            <span class="min-w-0"><span class="num">{{ $item->cantidad }}</span> × {{ $item->nombre }}</span>
                            <span class="num shrink-0">{{ $soles($item->subtotal) }}</span>
                        </div>
                        @php
                            $detalle = collect([$item->variante_nombre, ...$item->opciones->pluck('nombre')])->filter()->implode(' · ');
                        @endphp
                        @if ($detalle !== '')
                            <p class="mt-0.5 text-[0.8125rem] text-muted">{{ $detalle }}</p>
                        @endif
                        @if ($item->observacion)
                            <p class="mt-0.5 text-[0.8125rem] text-muted">Nota: {{ $item->observacion }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <dl class="mt-2 flex flex-col gap-0.5 border-t border-rule pt-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted">Subtotal</dt><dd class="num">{{ $soles($pedido->subtotal) }}</dd></div>
                @if ($pedido->tipo === 'delivery')
                    <div class="flex justify-between gap-3"><dt class="text-muted">Delivery</dt><dd class="num">{{ $soles($pedido->costo_delivery) }}</dd></div>
                @endif
                <div class="flex justify-between gap-3 text-base font-bold"><dt>Total</dt><dd class="num">{{ $soles($pedido->total) }}</dd></div>
            </dl>

            <p class="mt-3 text-[0.8125rem] text-muted">
                {{ $pedido->metodoPago->nombre }}. <span data-seguimiento-pago>{{ $seguimiento['pago'] }}</span>
            </p>
        </section>

        @if ($whatsapp)
            <x-button href="{{ $whatsapp }}" variant="success" target="_blank" rel="noopener noreferrer">Escribir al local por WhatsApp</x-button>
        @endif

        <p class="text-center text-xs text-muted">Guarda este enlace: es la forma de volver a ver tu pedido.</p>
    </main>

    <script>window.FOORA_SEGUIMIENTO = {{ \Illuminate\Support\Js::from([
        'slug' => $restaurante->slug,
        'codigo' => $pedido->codigo_seguimiento,
        'final' => $seguimiento['final'],
        'urlEstado' => route('vitrina.pedido.estado', [$restaurante->slug, $pedido->codigo_seguimiento]),
    ]) }};</script>
@endsection
