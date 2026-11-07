@extends('layouts.publico', ['entrada' => 'resources/js/seguimiento.js'])

@section('title', 'Pedido no encontrado')

@push('meta')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-10 sm:px-6">
        <h1 class="text-title tracking-tight text-ink">No encontramos este pedido</h1>
        <p class="mt-2 text-sm text-muted">Revisa que el enlace esté completo. Si lo copiaste de un mensaje, vuelve a abrirlo desde ahí.</p>
        <x-button href="{{ route('vitrina.show', $restaurante->slug) }}" variant="primary" class="mt-5 self-start">Ver la carta de {{ $restaurante->nombre }}</x-button>
    </main>
@endsection
