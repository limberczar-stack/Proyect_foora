@props([
    'id',
    'title',
    'description' => null,
    'size' => 'md',
    'loading' => false,
    'error' => null,
])

@php
    /**
     * Modal sobre <dialog> nativo: fondo inerte, Esc cierra, el foco vuelve al disparador.
     * Se abre con cualquier elemento que tenga data-modal-open="{id}" (resources/js/panel.js).
     * Se cierra con data-modal-close, Esc o clic en el fondo.
     *
     * En celular se apoya abajo como hoja; desde sm queda centrado.
     * Estados: loading (cuerpo con spinner, el pie se desactiva) y error (frase + Reintentar).
     * Slot opcional: footer.
     */
    $sizes = [
        'sm' => 'sm:max-w-md',
        'md' => 'sm:max-w-lg',
        'lg' => 'sm:max-w-2xl',
    ];
@endphp

<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-title"
    @if ($description) aria-describedby="{{ $id }}-description" @endif
    data-modal
    {{ $attributes->merge(['class' => 'modal m-0 mt-auto w-full max-w-none rounded-t-card bg-surface p-0 text-ink shadow-float backdrop:bg-transparent sm:m-auto sm:rounded-card '.($sizes[$size] ?? $sizes['md'])]) }}
>
    <div class="flex max-h-[85dvh] flex-col">
        <header class="flex items-start justify-between gap-4 border-b border-rule px-5 py-4">
            <div class="min-w-0">
                <h2 id="{{ $id }}-title" class="text-section font-semibold">{{ $title }}</h2>
                @if ($description)
                    <p id="{{ $id }}-description" class="mt-0.5 text-[0.8125rem] text-muted">{{ $description }}</p>
                @endif
            </div>
            <x-button variant="ghost" size="icon" data-modal-close aria-label="Cerrar" class="-mr-2 -mt-1.5">
                <x-icon name="x" />
            </x-button>
        </header>

        <div class="flex-1 overflow-y-auto px-5 py-5" @if ($loading) aria-busy="true" @endif>
            @if ($loading)
                <div class="flex items-center gap-3 py-6 text-muted">
                    <x-spinner :size="18" label="Cargando contenido" class="text-brand-700" />
                    <span>Cargando…</span>
                </div>
            @elseif ($error)
                <div class="flex flex-col items-start gap-3 rounded-control bg-danger-bg/50 p-4" role="alert">
                    <p class="flex items-start gap-2 text-sm">
                        <x-icon name="alert" class="mt-0.5 text-danger-ink" />
                        {{ $error }}
                    </p>
                    <x-button size="sm" icon="refresh" data-retry>Reintentar</x-button>
                </div>
            @else
                {{ $slot }}
            @endif
        </div>

        @isset($footer)
            <footer class="flex flex-col-reverse gap-2 border-t border-rule px-5 py-4 sm:flex-row sm:justify-end">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</dialog>
