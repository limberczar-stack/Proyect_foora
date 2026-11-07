@php
    /**
     * Páginas de error de FOORA. Autónomas a propósito: estilos en línea, sin Vite, sin sesión ni consultas,
     * para que se vean bien incluso cuando lo que falló es la aplicación misma.
     *
     * Secciones: code, title, message, actions.
     */
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#faf8fd">
    <title>@yield('title') · FOORA</title>
    <style>
        :root {
            --brand-50: #f6f2fd;
            --brand-100: #ede5fb;
            --brand-700: #5b21b6;
            --brand-800: #4c1d95;
            --ground: #faf8fd;
            --surface: #ffffff;
            --line: #e8e5ee;
            --control: #d3cfdb;
            --ink: #17151c;
            --muted: #5c5966;
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            background: var(--ground);
            color: var(--ink);
            font-family: 'DM Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 1rem;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        .marca {
            padding: 1.25rem 1.5rem;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--brand-700);
            text-decoration: none;
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 1.5rem 1.5rem 4rem;
        }

        .caja {
            width: 100%;
            max-width: 34rem;
            margin: 0 auto;
        }

        .codigo {
            margin: 0;
            font-size: clamp(4.5rem, 18vw, 8rem);
            font-weight: 700;
            line-height: 0.9;
            letter-spacing: -0.05em;
            color: var(--brand-100);
            font-variant-numeric: tabular-nums;
        }

        h1 {
            margin: 1.25rem 0 0;
            font-size: clamp(1.5rem, 5vw, 2rem);
            line-height: 1.15;
            letter-spacing: -0.02em;
        }

        .mensaje {
            margin: 0.75rem 0 0;
            max-width: 30rem;
            color: var(--ink);
        }

        .acciones {
            margin-top: 2rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .boton {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            padding: 0 1.125rem;
            border-radius: 0.625rem;
            border: 1px solid transparent;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: transform 150ms var(--ease-out), background-color 150ms ease;
        }

        .boton:active { transform: scale(0.97); }

        .boton-principal { background: var(--brand-700); color: #fff; }
        .boton-principal:hover { background: var(--brand-800); }

        .boton-secundario { background: var(--surface); color: var(--ink); border-color: var(--control); }
        .boton-secundario:hover { background: var(--brand-50); }

        :focus-visible { outline: 2px solid var(--brand-700); outline-offset: 2px; }

        @media (prefers-reduced-motion: reduce) {
            .boton { transition: none; }
        }
    </style>
</head>
<body>
    <a class="marca" href="{{ $inicioUrl }}" aria-label="FOORA, {{ mb_strtolower($inicioTexto) }}">FOORA</a>
    <main>
        <div class="caja">
            <p class="codigo" aria-hidden="true">@yield('code')</p>
            <h1>@yield('title')</h1>
            <p class="mensaje">@yield('message')</p>
            <div class="acciones">
                @yield('actions')
            </div>
        </div>
    </main>
</body>
</html>
