@extends('layouts.publico', ['entrada' => 'resources/js/seguimiento.js'])

@section('title', $restaurante->nombre)

@push('meta')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-10 sm:px-6">
        <p class="text-sm font-medium text-muted">{{ $restaurante->nombre }}</p>
        <h1 class="mt-1 text-title tracking-tight text-ink">Este restaurante no está disponible por ahora</h1>
        <p class="mt-2 text-sm text-muted">Por el momento no recibe pedidos por este enlace. Si ya hiciste un pedido, puedes seguirlo desde el enlace que recibiste.</p>
    </main>
@endsection
