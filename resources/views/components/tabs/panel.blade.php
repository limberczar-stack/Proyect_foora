@props(['tabsId', 'name', 'active' => false])

<div
    role="tabpanel"
    id="{{ $tabsId }}-panel-{{ $name }}"
    aria-labelledby="{{ $tabsId }}-tab-{{ $name }}"
    tabindex="0"
    data-tab-panel="{{ $name }}"
    @unless ($active) hidden @endunless
    {{ $attributes->merge(['class' => 'focus-visible:outline-offset-[-2px]']) }}
>
    {{ $slot }}
</div>
