@props([
    'id',
    'tabs' => [],
    'active' => null,
    'label' => 'Secciones',
    'loading' => false,
])

@php
    /**
     * Pestañas accesibles (patrón WAI-ARIA tablist), manejadas por resources/js/panel.js.
     *
     * $tabs = ['nuevos' => ['label' => 'Nuevos', 'count' => 3, 'disabled' => false], ...]
     * Los paneles se declaran con <x-tabs.panel :tabs-id="$id" name="nuevos">.
     * Teclado: flechas izquierda/derecha, Inicio y Fin; las pestañas deshabilitadas se saltan.
     *
     * @var array<string, array{label: string, count?: int|null, disabled?: bool}> $tabs
     */
    $active ??= array_key_first($tabs);
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col']) }} data-tabs="{{ $id }}">
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0 [scrollbar-width:none]">
        <div role="tablist" aria-label="{{ $label }}" class="flex min-w-max gap-1 border-b border-line">
            @foreach ($tabs as $key => $tab)
                @php
                    $isActive = $key === $active;
                    $isDisabled = $tab['disabled'] ?? false;
                @endphp
                <button
                    type="button"
                    role="tab"
                    id="{{ $id }}-tab-{{ $key }}"
                    aria-controls="{{ $id }}-panel-{{ $key }}"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    tabindex="{{ $isActive ? '0' : '-1' }}"
                    data-tab="{{ $key }}"
                    @if ($isDisabled) aria-disabled="true" @endif
                    @class([
                        'tab-indicator -mb-px inline-flex h-11 items-center gap-2 whitespace-nowrap px-3 text-sm lg:h-10',
                        'font-medium text-ink shadow-[inset_0_-2px_0_var(--color-brand-700)]' => $isActive,
                        'text-muted hover:text-ink' => ! $isActive && ! $isDisabled,
                        'cursor-not-allowed text-faint/70' => $isDisabled,
                    ])
                >
                    {{ $tab['label'] }}
                    @if ($loading)
                        <span class="skeleton h-4 w-5 rounded-full" aria-hidden="true"></span>
                    @elseif (array_key_exists('count', $tab) && $tab['count'] !== null)
                        <span @class([
                            'num inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium',
                            'bg-brand-100 text-brand-800' => $isActive,
                            'bg-raised text-muted' => ! $isActive,
                        ])>{{ $tab['count'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    {{ $slot }}
</div>
