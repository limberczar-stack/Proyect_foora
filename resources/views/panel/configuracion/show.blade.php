@extends('layouts.panel')

@section('title', 'Configuración')

@php
    /**
     * @var string $seccion general|horario|pago|pedidos|notificaciones|usuarios
     * @var \App\Models\Restaurante $restaurante
     */
    $navActivo = 'configuracion';
    $secciones = [
        'general' => 'General',
        'horario' => 'Horario',
        'pago' => 'Pago',
        'pedidos' => 'Pedidos',
        'notificaciones' => 'Notificaciones',
        'usuarios' => 'Usuarios',
    ];
@endphp

@section('content')
    <div class="min-w-0">
        <h1 class="text-title tracking-tight text-ink">Configuración</h1>
        <p class="mt-1 text-sm text-muted">Los datos de {{ $restaurante->nombre }}, cómo atiende y quién trabaja en el panel.</p>
    </div>

    <div class="scroll-tabs -mx-4 mt-5 sm:mx-0" data-scroll-tabs>
        <nav aria-label="Secciones de la configuración" class="scroll-tabs-pista overflow-x-auto px-4 sm:px-0">
            <ul class="flex min-w-max gap-1 border-b border-line">
                @foreach ($secciones as $clave => $etiquetaSeccion)
                    <li>
                        <a
                            href="{{ route('panel.configuracion.'.$clave) }}"
                            @if ($clave === $seccion) aria-current="page" @endif
                            @class([
                                'tab-indicator -mb-px inline-flex h-11 items-center whitespace-nowrap px-3 text-sm lg:h-10',
                                'font-medium text-ink shadow-[inset_0_-2px_0_var(--color-brand-700)]' => $clave === $seccion,
                                'text-muted hover:text-ink' => $clave !== $seccion,
                            ])
                        >{{ $etiquetaSeccion }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <button type="button" class="scroll-tabs-flecha" data-lado="inicio" tabindex="-1" aria-hidden="true"><x-icon name="chevron-left" size="16" /></button>
        <button type="button" class="scroll-tabs-flecha" data-lado="fin" tabindex="-1" aria-hidden="true"><x-icon name="chevron-right" size="16" /></button>
    </div>

    <div class="mt-6 max-w-5xl">
        @include('panel.configuracion._'.$seccion)
    </div>
@endsection
