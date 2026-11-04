@props([
    'title' => null,
    'subtitle' => null,
    'headingLevel' => 2,
    'loading' => false,
    'empty' => null,
    'error' => null,
    'disabled' => null,
    'padded' => true,
    'skeletonRows' => 3,
])

@php
    /**
     * Card de FOORA: superficie blanca, borde de 1px, sin sombra en reposo.
     *
     * Estados (en orden de prioridad):
     * - loading: esqueletos al tamaño real del contenido, cabecera visible.
     * - error: una frase de qué falló + botón Reintentar, dentro de la card.
     * - empty: el marco y la cabecera se quedan, una frase en un recuadro punteado.
     * - disabled: texto con el motivo (p. ej. "No incluido en tu plan"), contenido atenuado pero legible.
     *
     * Slots: actions (derecha de la cabecera), footer, empty-action.
     */
    $headingTag = 'h'.max(2, min(6, (int) $headingLevel));
    $hasHeader = $title || isset($actions);
    $bodyPadding = $padded ? 'px-4 pb-4 sm:px-5 sm:pb-5' : '';
    $headerPadding = 'px-4 pt-4 sm:px-5 sm:pt-5';
@endphp

<section
    {{ $attributes->merge(['class' => 'flex flex-col rounded-card border border-line bg-surface']) }}
    @if ($loading) aria-busy="true" @endif
    @if ($disabled) aria-disabled="true" @endif
>
    @if ($hasHeader)
        <header class="flex items-start justify-between gap-3 {{ $headerPadding }} {{ $padded ? 'pb-3' : 'pb-3' }}">
            <div class="min-w-0">
                @if ($title)
                    <{{ $headingTag }} class="text-section font-semibold text-ink">{{ $title }}</{{ $headingTag }}>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-[0.8125rem] text-muted">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="flex-1 {{ $hasHeader ? '' : ($padded ? 'pt-4 sm:pt-5' : '') }} {{ $bodyPadding }}">
        @if ($loading)
            <div class="flex flex-col gap-4" aria-hidden="true">
                @for ($i = 0; $i < $skeletonRows; $i++)
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex flex-1 flex-col gap-2">
                            <span class="skeleton h-3.5 w-2/5"></span>
                            <span class="skeleton h-3 w-3/5"></span>
                        </div>
                        <span class="skeleton h-6 w-16 rounded-full"></span>
                    </div>
                @endfor
            </div>
            <span class="sr-only" role="status">Cargando {{ $title ? mb_strtolower($title) : 'contenido' }}…</span>
        @elseif ($error)
            <div class="flex flex-col items-start gap-3 rounded-control bg-danger-bg/50 p-4 sm:flex-row sm:items-center sm:justify-between" role="alert">
                <div class="flex items-start gap-2.5">
                    <x-icon name="alert" class="mt-0.5 text-danger-ink" />
                    <p class="text-sm text-ink">{{ $error }}</p>
                </div>
                <x-button size="sm" variant="secondary" icon="refresh" data-retry>Reintentar</x-button>
            </div>
        @elseif ($empty)
            <div class="flex flex-col items-start gap-3 rounded-control border border-dashed border-control px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted">{{ $empty }}</p>
                @isset($emptyAction)
                    {{ $emptyAction }}
                @endisset
            </div>
        @elseif ($disabled)
            <div class="flex items-center gap-2.5 rounded-control bg-raised px-4 py-4">
                <x-icon name="lock" class="text-muted" />
                <p class="text-sm text-muted">{{ $disabled }}</p>
            </div>
        @else
            {{ $slot }}
        @endif
    </div>

    @isset($footer)
        @unless ($loading || $error || $empty || $disabled)
            <footer class="border-t border-rule px-4 py-3 sm:px-5">{{ $footer }}</footer>
        @endunless
    @endisset
</section>
