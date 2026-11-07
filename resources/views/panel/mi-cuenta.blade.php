@extends('layouts.panel')

@section('title', 'Mi cuenta')

@php
    /**
     * @var \App\Models\User $usuario
     * @var int $otrasSesiones
     */
    $temporal = $usuario->debe_cambiar_password;
@endphp

@section('content')
    <div class="max-w-3xl" data-mi-cuenta>
        <h1 class="text-title tracking-tight text-ink">Mi cuenta</h1>
        <p class="mt-1 text-sm text-muted">{{ $usuario->name }} · {{ $usuario->email }} · {{ $usuario->rolEtiqueta() }}</p>

        @if ($temporal)
            <div class="mt-5 flex items-start gap-3 rounded-card bg-warning-bg px-4 py-3.5 text-sm text-warning-ink" role="note">
                <x-icon name="key" class="mt-0.5" />
                <p><span class="font-semibold">Estás usando una contraseña temporal.</span> Crea una propia para seguir usando el panel.</p>
            </div>
        @endif

        <section class="mt-6 rounded-card border border-line bg-surface p-4 sm:p-5" aria-labelledby="titulo-clave">
            <h2 id="titulo-clave" class="text-section font-semibold">Cambiar contraseña</h2>
            <p class="mt-0.5 text-[0.8125rem] text-muted">Usa al menos 8 caracteres. Mejor una frase que solo tú recuerdes.</p>

            <form method="POST" action="{{ route('panel.mi-cuenta.password') }}" data-ajax-form novalidate class="mt-4">
                @csrf
                @method('PUT')

                @include('panel.configuracion._resumen', ['claves' => []])

                <div class="grid gap-4 sm:grid-cols-2" data-form-campos>
                    @foreach ([
                        ['password_actual', 'clave-actual', $temporal ? 'Contraseña temporal' : 'Contraseña actual', 'current-password', 'sm:col-span-2 sm:max-w-sm'],
                        ['password', 'clave-nueva', 'Nueva contraseña', 'new-password', ''],
                        ['password_confirmation', 'clave-repetir', 'Repite la nueva contraseña', 'new-password', ''],
                    ] as [$nombre, $id, $etiqueta, $autocompletar, $clases])
                        <x-campo :label="$etiqueta" :for="$id" :error="$nombre === 'password_confirmation' ? null : $nombre" :class="$clases">
                            <div class="relative">
                                <x-input :name="$nombre" :id="$id" type="password" :autocomplete="$autocompletar" maxlength="72" class="pr-20" required />
                                <button type="button" class="absolute inset-y-0 right-1 my-auto inline-flex h-9 items-center rounded-chip px-2.5 text-xs font-medium text-brand-700 hover:bg-brand-50" data-ver-clave="{{ $id }}" aria-pressed="false" aria-label="Mostrar {{ mb_strtolower($etiqueta) }}">
                                    <span data-ver-clave-texto>Mostrar</span>
                                </button>
                            </div>
                        </x-campo>
                    @endforeach
                </div>

                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <div class="guardado-estado flex min-w-0 items-center gap-2 text-sm" role="status" aria-live="polite" data-form-estado hidden></div>
                    <button type="submit" class="press inline-flex h-11 items-center justify-center gap-2 rounded-control bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800 disabled:bg-brand-200 disabled:text-brand-900 lg:h-10" data-guardar data-texto-ocupado="Cambiando…">
                        <span class="size-4 animate-spin-fast rounded-full border-2 border-brand-300 border-t-white" data-guardar-spinner aria-hidden="true" hidden></span>
                        <span data-guardar-texto>Cambiar contraseña</span>
                    </button>
                </div>
            </form>
        </section>

        @unless ($temporal)
            <section class="mt-5 rounded-card border border-line bg-surface p-4 sm:p-5" aria-labelledby="titulo-sesiones">
                <h2 id="titulo-sesiones" class="text-section font-semibold">Sesiones en otros dispositivos</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted" data-otras-sesiones>
                    @if ($otrasSesiones === 0)
                        No hay sesiones abiertas en otros dispositivos.
                    @elseif ($otrasSesiones === 1)
                        Tienes una sesión abierta en otro dispositivo.
                    @else
                        Tienes {{ $otrasSesiones }} sesiones abiertas en otros dispositivos.
                    @endif
                    Si usaste un celular o una PC que no es tuya, ciérralas.
                </p>

                <form method="POST" action="{{ route('panel.mi-cuenta.sesiones') }}" data-ajax-form novalidate class="mt-4" data-texto-ocupado="Cerrando…">
                    @csrf
                    @method('DELETE')

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start" data-form-campos>
                        <x-campo label="Tu contraseña, para confirmar" for="clave-sesiones" error="password_actual" class="sm:w-80">
                            <x-input name="password_actual" id="clave-sesiones" type="password" autocomplete="current-password" maxlength="72" required />
                        </x-campo>
                        <button type="submit" class="press inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-control border border-control bg-surface px-4 text-sm font-medium text-ink hover:bg-raised disabled:text-faint sm:mt-7 lg:h-10" data-guardar data-texto-ocupado="Cerrando…">
                            <span class="size-4 animate-spin-fast rounded-full border-2 border-brand-200 border-t-brand-700" data-guardar-spinner aria-hidden="true" hidden></span>
                            <x-icon name="devices" data-guardar-icono />
                            <span data-guardar-texto>Cerrar las demás sesiones</span>
                        </button>
                    </div>
                    <div class="guardado-estado mt-3 flex min-w-0 items-center gap-2 text-sm" role="status" aria-live="polite" data-form-estado hidden></div>
                </form>
            </section>
        @endunless
    </div>
@endsection
