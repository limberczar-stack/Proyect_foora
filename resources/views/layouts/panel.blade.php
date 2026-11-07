@php
    /**
     * Layout del panel del restaurante.
     *
     * Datos reales (view composer en AppServiceProvider):
     * - $panelUsuario: nombre, rol, iniciales
     * - $panelRestaurante: nombre, ciudad, plan, abierto, iniciales, logo (null para el superadmin)
     *
     * Variables opcionales que define la vista hija:
     * - $navCounts: conteos de colas que requieren acción (pedidos nuevos, alertas, reservas por confirmar)
     * - $alertasRecientes: lista corta para el menú de la campana
     * - $navActivo: clave del ítem activo del menú
     *
     * @var array{nombre: string, rol: string, iniciales: string}|null $panelUsuario
     * @var array{nombre: string, ciudad: string, plan: ?string, abierto: bool, iniciales: string, logo: ?string, puedeCambiarEstado: bool, puedeConfigurar: bool}|null $panelRestaurante
     */
    $panelUsuario ??= ['nombre' => 'Invitado', 'rol' => '', 'iniciales' => '?'];
    $panelRestaurante ??= null;
    $navCounts ??= [];
    $alertasRecientes ??= [];
    $navActivo ??= 'inicio';
    $navPedidosNuevos ??= null;

    /** @var list<array{grupo: string, items: list<array{clave: string, label: string, icon: string, href: string, count?: int, countLabel?: string}>}> $menu */
    $menu = [
        ['grupo' => 'Operación', 'items' => [
            ['clave' => 'inicio', 'label' => 'Inicio', 'icon' => 'home', 'href' => route('panel.inicio')],
            ['clave' => 'pedidos', 'label' => 'Pedidos', 'icon' => 'receipt', 'href' => route('panel.pedidos.index'), 'count' => $navPedidosNuevos ?? ($navCounts['pedidos'] ?? 0), 'countLabel' => 'pedidos nuevos', 'vivo' => true],
            ['clave' => 'alertas', 'label' => 'Alertas', 'icon' => 'bell', 'href' => '#', 'count' => $navCounts['alertas'] ?? 0, 'countLabel' => 'alertas sin resolver'],
            ['clave' => 'reservas', 'label' => 'Reservas', 'icon' => 'calendar', 'href' => '#', 'count' => $navCounts['reservas'] ?? 0, 'countLabel' => 'reservas por confirmar'],
            ['clave' => 'mesas', 'label' => 'Mesas', 'icon' => 'table', 'href' => '#'],
        ]],
        ['grupo' => 'Gestión', 'items' => [
            ['clave' => 'carta', 'label' => 'Carta', 'icon' => 'book', 'href' => route('panel.carta.productos.index')],
            ['clave' => 'promociones', 'label' => 'Promociones', 'icon' => 'tag', 'href' => '#'],
            ['clave' => 'actividad', 'label' => 'Actividad', 'icon' => 'activity', 'href' => '#'],
        ]],
    ];

    $alertasPendientes = $navCounts['alertas'] ?? 0;
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#faf8fd">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') · FOORA</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh">
    <a href="#contenido" class="sr-only z-50 rounded-control bg-brand-700 px-4 py-2 font-medium text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
        Saltar al contenido
    </a>

    {{-- Fondo del panel lateral en celular y tablet --}}
    <div
        class="sidebar-overlay invisible fixed inset-0 z-30 bg-ink/40 opacity-0 lg:hidden"
        data-nav-overlay
        aria-hidden="true"
    ></div>

    {{-- Barra lateral --}}
    <aside
        id="panel-sidebar"
        class="sidebar invisible fixed inset-y-0 left-0 z-40 flex w-[17rem] max-w-[85vw] -translate-x-full flex-col border-r border-line bg-ground shadow-float lg:visible lg:translate-x-0 lg:shadow-none"
        data-sidebar
        aria-label="Navegación del panel"
    >
        <div class="flex h-16 shrink-0 items-center justify-between px-5">
            <a href="{{ route('panel.inicio') }}" class="press rounded-chip text-xl font-bold tracking-tight text-brand-700" aria-label="FOORA, ir al inicio">
                FOORA
            </a>
            <x-button variant="ghost" size="icon" class="-mr-2 lg:hidden" data-nav-close aria-label="Cerrar menú">
                <x-icon name="x" size="20" />
            </x-button>
        </div>

        {{-- Restaurante y estado del local --}}
        <div class="mx-3 rounded-card border border-line bg-surface p-3">
            @if ($panelRestaurante)
                <div class="flex items-center gap-3">
                    @if ($panelRestaurante['logo'])
                        <img src="{{ $panelRestaurante['logo'] }}" alt="" class="size-10 shrink-0 rounded-full object-cover">
                    @else
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800" aria-hidden="true">
                            {{ $panelRestaurante['iniciales'] }}
                        </span>
                    @endif
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-ink">{{ $panelRestaurante['nombre'] }}</p>
                        <p class="truncate text-xs text-muted">
                            {{ $panelRestaurante['ciudad'] }}@if ($panelRestaurante['plan']) · Plan {{ $panelRestaurante['plan'] }}@endif
                        </p>
                    </div>
                </div>

                {{-- Estado del local: admin y cajero lo cambian aquí (resources/js/estado-local.js) --}}
                @if ($panelRestaurante['puedeCambiarEstado'] ?? false)
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $panelRestaurante['abierto'] ? 'true' : 'false' }}"
                        aria-label="Local abierto"
                        data-estado-local
                        data-url="{{ route('panel.estado-local') }}"
                        data-abierto="{{ $panelRestaurante['abierto'] ? '1' : '0' }}"
                        class="estado-local press mt-3 flex h-11 w-full items-center justify-between rounded-control bg-raised px-3 text-sm hover:bg-active/60 lg:h-10"
                    >
                        <span class="flex items-center gap-2">
                            <span class="estado-local-punto size-2.5 rounded-full" aria-hidden="true"></span>
                            <span class="font-medium text-ink" data-estado-local-texto>{{ $panelRestaurante['abierto'] ? 'Abierto' : 'Cerrado' }}</span>
                        </span>
                        <span class="estado-local-pista relative h-5 w-9 rounded-full" aria-hidden="true">
                            <span class="estado-local-perilla absolute left-0.5 top-0.5 flex size-4 items-center justify-center rounded-full bg-white shadow-sm">
                                <span class="estado-local-carga hidden size-2.5 animate-spin-fast rounded-full border-[1.5px] border-brand-200 border-t-brand-700"></span>
                            </span>
                        </span>
                    </button>
                    <p class="mt-1.5 px-1 text-xs text-danger-ink" role="alert" data-estado-local-error hidden></p>
                @else
                    <p class="mt-3 flex h-10 items-center gap-2 rounded-control bg-raised px-3 text-sm">
                        <span @class(['size-2.5 rounded-full', 'bg-success-dot' => $panelRestaurante['abierto'], 'bg-neutral-dot' => ! $panelRestaurante['abierto']]) aria-hidden="true"></span>
                        <span class="font-medium text-ink">{{ $panelRestaurante['abierto'] ? 'Abierto' : 'Cerrado' }}</span>
                    </p>
                @endif
            @else
                <div class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">F</span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-ink">FOORA</p>
                        <p class="truncate text-xs text-muted">Superadmin · todos los locales</p>
                    </div>
                </div>
            @endif
        </div>

        {{-- Menú --}}
        <nav class="mt-2 flex-1 overflow-y-auto px-3 pb-4" aria-label="Principal">
            @foreach ($menu as $grupo)
                <p class="px-3 pb-2 pt-5 text-xs text-muted">{{ $grupo['grupo'] }}</p>
                <ul class="flex flex-col gap-0.5">
                    @foreach ($grupo['items'] as $item)
                        @php $esActivo = $item['clave'] === $navActivo; @endphp
                        <li>
                            <a
                                href="{{ $item['href'] }}"
                                @if ($esActivo) aria-current="page" @endif
                                @class([
                                    'press flex h-11 items-center gap-3 rounded-control px-3 text-sm lg:h-9',
                                    'bg-active font-medium text-brand-900' => $esActivo,
                                    'text-neutral-ink hover:bg-raised hover:text-ink' => ! $esActivo,
                                ])
                            >
                                <x-icon :name="$item['icon']" @class(['text-brand-700' => $esActivo, 'text-faint' => ! $esActivo]) />
                                <span class="flex-1">{{ $item['label'] }}</span>
                                @if (! empty($item['count']) || ! empty($item['vivo']))
                                    <span
                                        @if (! empty($item['vivo'])) data-nav-badge="{{ $item['clave'] }}" @endif
                                        @if (empty($item['count'])) hidden @endif
                                        class="num inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-700 px-1.5 text-xs font-semibold text-white"
                                    >
                                        <span data-nav-badge-numero>{{ $item['count'] ?? 0 }}</span><span class="sr-only"> {{ $item['countLabel'] }}</span>
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </nav>

        <div class="border-t border-line px-3 py-3">
            @if ($panelRestaurante['puedeConfigurar'] ?? false)
                <a
                    href="{{ route('panel.configuracion.general') }}"
                    @if ($navActivo === 'configuracion') aria-current="page" @endif
                    @class([
                        'press flex h-11 items-center gap-3 rounded-control px-3 text-sm lg:h-9',
                        'bg-active font-medium text-brand-900' => $navActivo === 'configuracion',
                        'text-neutral-ink hover:bg-raised hover:text-ink' => $navActivo !== 'configuracion',
                    ])
                >
                    <x-icon name="settings" @class(['text-brand-700' => $navActivo === 'configuracion', 'text-faint' => $navActivo !== 'configuracion']) />
                    Configuración
                </a>
            @endif
            <a href="#" class="press flex h-11 items-center gap-3 rounded-control px-3 text-sm text-neutral-ink hover:bg-raised hover:text-ink lg:h-9">
                <x-icon name="help" class="text-faint" />
                Ayuda
            </a>
        </div>
    </aside>

    <div class="flex min-h-dvh flex-col lg:pl-[17rem]">
        {{-- Barra superior --}}
        <header class="sticky top-0 z-20 border-b border-line bg-ground">
            <div class="mx-auto flex h-14 max-w-[90rem] items-center gap-2 px-4 sm:px-6 lg:h-16 lg:px-8">
                <x-button
                    variant="ghost"
                    size="icon"
                    class="-ml-2 lg:hidden"
                    data-nav-toggle
                    aria-controls="panel-sidebar"
                    aria-expanded="false"
                    aria-label="Abrir menú"
                >
                    <x-icon name="menu" size="20" />
                </x-button>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink lg:hidden">@yield('title', 'Panel')</p>
                    <p class="hidden truncate text-sm text-muted lg:block">
                        {{ $panelRestaurante['nombre'] ?? 'FOORA' }}
                        <x-icon name="chevron-right" size="12" class="mx-1 inline text-faint" />
                        <span class="text-ink">@yield('title', 'Panel')</span>
                    </p>
                </div>

                {{-- Campana de alertas --}}
                <div class="relative" data-dropdown>
                    <x-button
                        variant="ghost"
                        size="icon"
                        class="relative"
                        data-dropdown-trigger
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="menu-alertas"
                        aria-label="Alertas: {{ $alertasPendientes }} sin resolver"
                    >
                        <x-icon name="bell" size="20" />
                        @if ($alertasPendientes > 0)
                            <span class="num absolute right-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger-dot px-1 text-[0.6875rem] font-semibold leading-none text-white ring-2 ring-ground" aria-hidden="true">
                                {{ $alertasPendientes }}
                            </span>
                        @endif
                    </x-button>

                    <div
                        id="menu-alertas"
                        class="dropdown-menu absolute right-0 top-full z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-card border border-line bg-surface shadow-float"
                        data-dropdown-menu
                        hidden
                    >
                        <div class="flex items-center justify-between border-b border-rule px-4 py-3">
                            <p class="font-semibold">Alertas</p>
                            <span class="num text-xs text-muted">{{ $alertasPendientes }} sin resolver</span>
                        </div>
                        @forelse ($alertasRecientes as $alerta)
                            <a href="#" class="flex items-start gap-3 border-b border-rule px-4 py-3 last:border-0 hover:bg-raised">
                                <x-badge :status="$alerta['prioridad']" class="mt-0.5" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-ink">{{ $alerta['titulo'] }}</span>
                                    <span class="block text-xs text-muted">{{ $alerta['hace'] }}</span>
                                </span>
                            </a>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-muted">No hay alertas pendientes.</p>
                        @endforelse
                        <div class="border-t border-rule p-2">
                            <a href="#" class="press flex h-10 items-center justify-center rounded-control text-sm font-medium text-brand-700 hover:bg-brand-50">Ver todas las alertas</a>
                        </div>
                    </div>
                </div>

                {{-- Usuario --}}
                <div class="relative" data-dropdown>
                    <button
                        type="button"
                        class="press flex h-11 items-center gap-2 rounded-control pl-1 pr-1 hover:bg-raised sm:pr-2 lg:h-10"
                        data-dropdown-trigger
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="menu-usuario"
                    >
                        <span class="flex size-8 items-center justify-center rounded-full bg-[#e0f2fe] text-xs font-semibold text-[#0c4a6e]" aria-hidden="true">
                            {{ $panelUsuario['iniciales'] }}
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block text-sm font-medium leading-tight text-ink">{{ $panelUsuario['nombre'] }}</span>
                            <span class="block text-xs leading-tight text-muted">{{ $panelUsuario['rol'] }}</span>
                        </span>
                        <span class="sr-only sm:hidden">Menú de {{ $panelUsuario['nombre'] }}</span>
                        <x-icon name="chevron-down" size="14" class="hidden text-faint sm:block" />
                    </button>

                    <div
                        id="menu-usuario"
                        class="dropdown-menu absolute right-0 top-full z-30 mt-2 w-56 rounded-card border border-line bg-surface p-1.5 shadow-float"
                        data-dropdown-menu
                        hidden
                    >
                        <div class="px-3 py-2 sm:hidden">
                            <p class="text-sm font-medium">{{ $panelUsuario['nombre'] }}</p>
                            <p class="text-xs text-muted">{{ $panelUsuario['rol'] }}</p>
                        </div>
                        <a href="{{ route('panel.mi-cuenta') }}" class="flex h-10 items-center gap-2.5 rounded-control px-3 text-sm hover:bg-raised">
                            <x-icon name="user" class="text-faint" /> Mi cuenta
                        </a>
                        @if ($panelRestaurante['puedeConfigurar'] ?? false)
                            <a href="{{ route('panel.configuracion.general') }}" class="flex h-10 items-center gap-2.5 rounded-control px-3 text-sm hover:bg-raised">
                                <x-icon name="settings" class="text-faint" /> Configuración
                            </a>
                        @endif
                        <div class="my-1 border-t border-rule"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex h-10 w-full items-center gap-2.5 rounded-control px-3 text-sm text-danger-ink hover:bg-danger-bg/60">
                                <x-icon name="logout" /> Cerrar sesión
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="contenido" tabindex="-1" class="mx-auto w-full max-w-[90rem] flex-1 px-4 pb-16 pt-5 focus:outline-none sm:px-6 sm:pt-6 lg:px-8 lg:pt-8">
            @if (session('status'))
                <div class="mb-5 flex items-start gap-2.5 rounded-control bg-success-bg px-4 py-3 text-sm text-success-ink" role="status" data-flash>
                    <x-icon name="check" class="mt-0.5" />
                    <p class="flex-1">{{ session('status') }}</p>
                </div>
            @endif
            @if (session('error'))
                <div class="mb-5 flex items-start gap-2.5 rounded-control bg-danger-bg px-4 py-3 text-sm text-danger-ink" role="alert" data-flash>
                    <x-icon name="alert" class="mt-0.5" />
                    <p class="flex-1">{{ session('error') }}</p>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    @if ($panelRestaurante['puedeCambiarEstado'] ?? false)
        {{-- Confirmación para cerrar el local (resources/js/estado-local.js) --}}
        <x-modal id="modal-cerrar-local" title="¿Cerrar el local?" size="sm">
            <div class="flex flex-col gap-3 text-sm">
                <p>Tus clientes no podrán hacer pedidos nuevos hasta que lo vuelvas a abrir.</p>
                <p class="rounded-control bg-raised px-3 py-2.5" data-cerrar-local-activos></p>
            </div>
            <x-slot:footer>
                <x-button variant="secondary" data-modal-close>Seguir abierto</x-button>
                <x-button variant="primary" data-cerrar-local-confirmar>Cerrar el local</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    <p class="sr-only" aria-live="polite" data-anuncio-panel></p>

    @stack('modals')
</body>
</html>
