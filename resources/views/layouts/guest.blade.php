<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#faf8fd">
    <title>@yield('title', 'Ingresar') · FOORA</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh">
    <main class="flex min-h-dvh flex-col items-center justify-center px-4 py-10 sm:px-6">
        <div class="w-full max-w-[26rem]">
            <div class="mb-8 text-center">
                <p class="text-3xl font-bold tracking-tight text-brand-700">FOORA</p>
                <p class="mt-1 text-sm text-muted">Reserva, ordena y disfruta</p>
            </div>

            @yield('content')

            <p class="mt-8 text-center text-xs text-muted">Panel para restaurantes · Huánuco, Perú</p>
        </div>
    </main>
</body>
</html>
