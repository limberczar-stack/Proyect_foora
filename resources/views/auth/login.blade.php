@extends('layouts.guest')

@section('title', 'Ingresar')

@php
    $inputBase = 'h-11 w-full rounded-control border bg-surface px-3 text-sm text-ink placeholder:text-faint focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0';
    $inputOk = 'border-line focus:border-brand-600 focus-visible:outline-brand-600/30';
    $inputError = 'border-danger-dot focus:border-danger-dot focus-visible:outline-danger-dot/30';
    $errorAuth = $errors->first('auth');
@endphp

@section('content')
    <section class="rounded-card border border-line bg-surface p-5 sm:p-7" aria-labelledby="login-titulo">
        <h1 id="login-titulo" class="text-xl font-semibold tracking-tight text-ink">Ingresa a tu panel</h1>
        <p class="mt-1 text-sm text-muted">Usa el correo y la contraseña que te dio el administrador del local.</p>

        @if (session('status'))
            <div class="mt-5 flex items-start gap-2.5 rounded-control bg-warning-bg px-4 py-3 text-sm text-warning-ink" role="status">
                <x-icon name="alert" class="mt-0.5" />
                <p>{{ session('status') }}</p>
            </div>
        @endif

        @if ($errorAuth)
            <div class="mt-5 flex items-start gap-2.5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert">
                <x-icon name="alert" class="mt-0.5" />
                <p>{{ $errorAuth }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 flex flex-col gap-4" data-loading-form novalidate>
            @csrf

            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-ink">Correo</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    inputmode="email"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    @if (! $errors->has('email') && ! $errorAuth) autofocus @endif
                    placeholder="nombre@restaurante.pe"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="{{ $inputBase }} {{ $errors->has('email') ? $inputError : $inputOk }}"
                >
                @error('email')
                    <p id="email-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-ink">Contraseña</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    @if ($errorAuth) autofocus @endif
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="{{ $inputBase }} {{ $errors->has('password') ? $inputError : $inputOk }}"
                >
                @error('password')
                    <p id="password-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm text-ink">
                <input
                    type="checkbox"
                    name="remember"
                    value="1"
                    @checked(old('remember'))
                    class="size-[1.125rem] rounded-[0.25rem] border-control accent-brand-700"
                >
                Recordarme en este dispositivo
            </label>

            <x-button type="submit" variant="primary" class="w-full" data-submit data-loading-text="Ingresando…">
                <span data-submit-spinner hidden><x-spinner :size="16" label="Ingresando" /></span>
                <span data-submit-label>Ingresar</span>
            </x-button>
        </form>
    </section>
@endsection
