@php
    /**
     * Errores del formulario que no pertenecen a un solo campo (p. ej. "deja al menos un método activo").
     *
     * @var list<string> $claves  claves de error que se muestran aquí cuando la página vuelve sin JS
     */
    $claves ??= [];
    $mensajes = collect($claves)->flatMap(fn (string $clave): array => $errors->get($clave))->all();
@endphp

<div class="mb-5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert" data-form-resumen @if ($mensajes === []) hidden @endif>
    <p class="flex items-center gap-2 font-medium"><x-icon name="alert" /> No se pudo guardar</p>
    <ul class="mt-1 list-disc pl-10">
        @foreach ($mensajes as $mensaje)
            <li>{{ $mensaje }}</li>
        @endforeach
    </ul>
</div>
