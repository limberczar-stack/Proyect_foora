@extends('layouts.panel')

@section('title', 'Inicio')

@section('content')
    <div class="max-w-2xl">
        <h1 class="text-title tracking-tight text-ink">Tu cuenta aún no tiene módulos habilitados</h1>
        <p class="mt-2 text-sm text-ink">Pide al administrador de tu local que te asigne uno. Mientras tanto, puedes revisar tu cuenta o cerrar sesión.</p>

        <div class="mt-5 flex flex-wrap gap-2">
            <x-button variant="primary" icon="user" :href="route('panel.mi-cuenta')">Mi cuenta</x-button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-button type="submit" variant="secondary" icon="logout">Cerrar sesión</x-button>
            </form>
        </div>
    </div>
@endsection
