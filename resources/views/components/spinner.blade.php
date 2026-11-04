@props(['size' => 16, 'label' => 'Cargando'])

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0']) }} role="status">
    <span
        class="animate-spin-fast rounded-full border-2 border-current border-r-transparent"
        style="width: {{ $size }}px; height: {{ $size }}px"
        aria-hidden="true"
    ></span>
    <span class="sr-only">{{ $label }}</span>
</span>
