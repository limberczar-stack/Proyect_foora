@props([
    'codigo',
    'capacidad',
    'estado' => 'disponible',
    'inactiva' => false,
    'alerta' => null,
    'as' => 'div',
])

@php
    /**
     * Mesa del mapa: código, capacidad y estado por color y texto (el color nunca va solo).
     *
     * Estados: disponible (verde), ocupada (naranja), reservada (morado), fuera_servicio (rojo).
     * El slot lleva el detalle de la mesa ocupada (tiempo, comensales, mesero, grupo) o, más adelante,
     * de la reservada («20:30 · Pérez»).
     *
     * - inactiva: mesa o área desactivada (solo la ve el admin al editar), atenuada y con su etiqueta.
     * - alerta: texto que pide atención (p. ej. «Más de 6 h abierta»); resalta la mesa con un borde rojo.
     * - as="button" o as="a" (con href): la mesa se puede tocar.
     *
     * Uso: <x-mesa codigo="4" :capacidad="4" estado="ocupada">45 min · 3 comensales</x-mesa>
     */
    $tonos = [
        'disponible' => ['Disponible', 'border-success-dot/50 bg-success-bg/50', 'text-success-ink', 'bg-success-dot'],
        'ocupada' => ['Ocupada', 'border-warning-dot/60 bg-warning-bg/70', 'text-warning-ink', 'bg-warning-dot'],
        'reservada' => ['Reservada', 'border-brand-300 bg-brand-100/60', 'text-brand-800', 'bg-brand-700'],
        'fuera_servicio' => ['Fuera de servicio', 'border-danger-dot/50 bg-danger-bg/60', 'text-danger-ink', 'bg-danger-dot'],
    ];

    [$etiqueta, $caja, $tinta, $punto] = $tonos[$estado] ?? $tonos['disponible'];
    $tag = in_array($as, ['button', 'a'], true) ? $as : 'div';
    $tocable = $tag !== 'div';
@endphp

<{{ $tag }}
    @if ($tag === 'button') type="button" @endif
    {{ $attributes->class([
        'relative flex min-h-24 w-full flex-col justify-between gap-2 rounded-card border p-3 text-left',
        $caja,
        'opacity-55' => $inactiva,
        'outline-2 -outline-offset-2 outline-danger-dot' => $alerta,
        'press transition-shadow hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600' => $tocable,
    ]) }}
    data-mesa-estado="{{ $estado }}"
>
    <span class="flex items-start justify-between gap-2">
        <span class="min-w-0 break-words text-base font-semibold leading-tight text-ink">{{ $codigo }}</span>
        <span class="mt-1 size-2.5 shrink-0 rounded-full {{ $punto }}" aria-hidden="true"></span>
    </span>
    <span class="flex flex-col gap-0.5">
        <span class="num flex items-center gap-1 text-xs text-muted">
            <x-icon name="users" :size="14" />
            {{ $capacidad }} {{ $capacidad === 1 ? 'persona' : 'personas' }}
        </span>
        <span class="text-xs font-medium {{ $tinta }}">{{ $inactiva ? 'Desactivada' : $etiqueta }}</span>
        @if (trim((string) $slot) !== '')
            <span class="num flex flex-col gap-0.5 text-xs text-ink">{{ $slot }}</span>
        @endif
        @if ($alerta)
            <span class="flex items-center gap-1 text-xs font-medium text-danger-ink"><x-icon name="clock" :size="14" /> {{ $alerta }}</span>
        @endif
    </span>
</{{ $tag }}>
