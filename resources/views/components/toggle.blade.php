@props([
    'name',
    'label',
    'checked' => false,
    'description' => null,
    'value' => '1',
    'disabled' => false,
    'id' => null,
])

@php
    /**
     * Interruptor de formulario: un checkbox nativo con forma de switch (accesible y sin JS).
     * Envía un 0 oculto cuando está apagado, para que el campo siempre llegue al servidor.
     */
    $id ??= 'toggle-'.str_replace(['[', ']', '.'], '-', $name).'-'.uniqid();
@endphp

<label for="{{ $id }}" {{ $attributes->merge(['class' => 'flex min-h-11 items-center justify-between gap-4 '.($disabled ? 'cursor-not-allowed opacity-70' : 'cursor-pointer')]) }}>
    <span class="min-w-0">
        <span class="block text-sm font-medium text-ink">{{ $label }}</span>
        @if ($description)
            <span class="block text-xs text-muted">{{ $description }}</span>
        @endif
    </span>
    <span class="relative inline-flex shrink-0">
        <input type="hidden" name="{{ $name }}" value="0">
        <input
            id="{{ $id }}"
            type="checkbox"
            name="{{ $name }}"
            value="{{ $value }}"
            role="switch"
            @checked($checked)
            @disabled($disabled)
            class="peer sr-only"
        >
        <span class="h-6 w-11 rounded-full bg-control transition-colors duration-200 peer-checked:bg-success-fill peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600"></span>
        <span class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-out peer-checked:translate-x-5"></span>
    </span>
</label>
