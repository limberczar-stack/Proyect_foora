@props([
    'status' => null,
    'tone' => null,
    'dot' => true,
    'loading' => false,
    'disabled' => false,
])

@php
    /**
     * Badge de estado: tinte pálido con tinta del mismo tono y punto sólido.
     * El color nunca va solo: el texto siempre nombra el estado.
     *
     * Uso: <x-badge status="en_preparacion" />  o  <x-badge tone="info">Texto</x-badge>
     *
     * @var array<string, array{0: string, 1: string}> $statuses  estado => [tono, etiqueta]
     */
    $statuses = [
        // Pedidos
        'nuevo' => ['info', 'Nuevo'],
        'en_preparacion' => ['warning', 'En preparación'],
        'listo' => ['success', 'Listo'],
        'en_camino' => ['info', 'En camino'],
        'entregado' => ['success', 'Entregado'],
        'cancelado' => ['danger', 'Cancelado'],
        // Pagos
        'pendiente' => ['warning', 'Pendiente'],
        'por_verificar' => ['warning', 'Por verificar'],
        'pagado' => ['success', 'Pagado'],
        'rechazado' => ['danger', 'Rechazado'],
        // Reservas
        'confirmada' => ['success', 'Confirmada'],
        'cancelada' => ['danger', 'Cancelada'],
        'no_asistio' => ['danger', 'No asistió'],
        'completada' => ['neutral', 'Completada'],
        // Mesas
        'disponible' => ['success', 'Disponible'],
        'ocupada' => ['warning', 'Ocupada'],
        'reservada' => ['brand', 'Reservada'],
        'fuera_servicio' => ['danger', 'Fuera de servicio'],
        // Alertas
        'urgente' => ['danger', 'Urgente'],
        'media' => ['warning', 'Media'],
        'informativa' => ['info', 'Informativa'],
        // Promociones
        'activa' => ['success', 'Activa'],
        'programada' => ['info', 'Programada'],
        'finalizada' => ['neutral', 'Finalizada'],
    ];

    [$statusTone, $statusLabel] = $statuses[$status] ?? [null, null];
    $resolvedTone = $disabled ? 'neutral' : ($tone ?? $statusTone ?? 'neutral');

    $tones = [
        'success' => ['bg-success-bg text-success-ink', 'bg-success-dot'],
        'warning' => ['bg-warning-bg text-warning-ink', 'bg-warning-dot'],
        'info' => ['bg-info-bg text-info-ink', 'bg-info-dot'],
        'danger' => ['bg-danger-bg text-danger-ink', 'bg-danger-dot'],
        'brand' => ['bg-brand-100 text-brand-800', 'bg-brand-700'],
        'neutral' => ['bg-neutral-bg text-neutral-ink', 'bg-neutral-dot'],
    ];

    [$toneClasses, $dotClasses] = $tones[$resolvedTone] ?? $tones['neutral'];
    $label = trim((string) $slot) !== '' ? $slot : ($statusLabel ?? '—');
@endphp

@if ($loading)
    <span {{ $attributes->merge(['class' => 'skeleton inline-block h-6 w-20 rounded-full']) }} aria-hidden="true"></span>
@elseif ($status === null && trim((string) $slot) === '')
    <span {{ $attributes->merge(['class' => 'text-faint']) }} aria-label="Sin estado">—</span>
@else
    <span
        {{ $attributes->merge(['class' => "inline-flex h-6 flex-none items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 text-xs font-medium {$toneClasses}".($disabled ? ' opacity-80' : '')]) }}
        @if ($disabled) aria-disabled="true" @endif
    >
        @if ($dot)
            <span class="size-1.5 rounded-full {{ $dotClasses }}" aria-hidden="true"></span>
        @endif
        {{ $label }}
    </span>
@endif
