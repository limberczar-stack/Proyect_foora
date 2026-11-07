<!DOCTYPE html>
<html lang="es-PE" class="scroll-smooth motion-reduce:scroll-auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4c1d95">
    <title>@yield('title') · FOORA</title>
    @stack('meta')
    @fonts
    @vite(['resources/css/app.css', $entrada ?? 'resources/js/vitrina.js'])
</head>
<body class="min-h-dvh">
    @yield('content')
    @stack('modals')
</body>
</html>
