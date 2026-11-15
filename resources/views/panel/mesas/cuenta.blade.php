@extends('layouts.panel')

@section('title', 'Cuenta #'.$cuenta->numero)

@php
    /**
     * Detalle de una cuenta como página (enlace directo o sin JavaScript). En el plano, el mismo
     * contenido se abre en la hoja inferior.
     *
     * @var \App\Models\Cuenta $cuenta
     */
    $navActivo = 'mesas';
@endphp

@section('content')
    <div class="max-w-xl" data-cuenta-pagina data-url-plano="{{ route('panel.mesas.index') }}">
        <a href="{{ route('panel.mesas.index') }}" class="press mb-1 inline-flex items-center gap-1 rounded-chip text-sm text-muted hover:text-ink"><x-icon name="chevron-left" /> Volver al plano</a>
        <h1 class="text-title tracking-tight text-ink">Cuenta #{{ $cuenta->numero }}</h1>

        <x-card class="mt-5">
            @include('panel.mesas._cuenta')
        </x-card>
    </div>
@endsection
