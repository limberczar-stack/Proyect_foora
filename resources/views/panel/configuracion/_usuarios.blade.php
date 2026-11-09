@php
    /**
     * @var \Illuminate\Support\Collection<int, \App\Models\User> $usuarios
     * @var array{activos: int, limite: ?int} $cupo
     * @var array<string, string> $roles
     * @var \App\Models\Restaurante $restaurante
     */
    $actor = auth()->user();
    $lleno = $cupo['limite'] !== null && $cupo['activos'] >= $cupo['limite'];
    $descripcionRoles = [
        'admin' => 'Todo el panel, incluida la configuración.',
        'cajero' => 'Pedidos, pagos, carta y abrir o cerrar el local.',
        'mesero' => 'Mesas y pedidos de salón.',
        'cocina' => 'Pedidos en preparación.',
        'repartidor' => 'Sus entregas.',
    ];
@endphp

<div data-config-usuarios>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-section font-semibold">Usuarios del local</h2>
            <p class="mt-1 text-sm text-muted">
                @if ($cupo['limite'] !== null)
                    <span class="num">{{ $cupo['activos'] }}</span> de <span class="num">{{ $cupo['limite'] }}</span> usuarios activos que permite tu plan {{ $restaurante->plan?->nombre }}.
                @else
                    <span class="num">{{ $cupo['activos'] }}</span> {{ $cupo['activos'] === 1 ? 'usuario activo' : 'usuarios activos' }}.
                @endif
                Cada persona entra con su propio correo.
            </p>
        </div>
        <x-button variant="primary" icon="plus" data-usuario-nuevo :disabled="$lleno" :aria-describedby="$lleno ? 'usuarios-lleno' : null">Nuevo usuario</x-button>
    </div>

    @if ($lleno)
        <p id="usuarios-lleno" class="mt-4 flex items-start gap-2 rounded-control bg-warning-bg px-4 py-3 text-sm text-warning-ink">
            <x-icon name="lock" class="mt-0.5" />
            Llegaste al límite de {{ $cupo['limite'] }} usuarios activos de tu plan {{ $restaurante->plan?->nombre }}. Desactiva a alguien o mejora tu plan para agregar más.
        </p>
    @endif

    <ul class="mt-5 divide-y divide-rule rounded-card border border-line bg-surface" aria-label="Usuarios del local">
        @foreach ($usuarios as $usuario)
            @php
                $esActor = $usuario->is($actor);
                $datosEdicion = [
                    'url' => route('panel.configuracion.usuarios.update', $usuario->id),
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                    'telefono' => $usuario->telefono,
                    'rol' => $usuario->rol,
                    'propio' => $esActor,
                ];
            @endphp
            <li @class(['flex flex-col gap-3 px-4 py-3.5 sm:px-5 md:flex-row md:items-center md:gap-4', 'bg-raised/50' => ! $usuario->activo])>
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                        'bg-brand-100 text-brand-800' => $usuario->activo,
                        'bg-neutral-bg text-neutral-ink' => ! $usuario->activo,
                    ]) aria-hidden="true">{{ $usuario->iniciales() }}</span>
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span @class(['truncate font-medium', 'text-ink' => $usuario->activo, 'text-muted' => ! $usuario->activo])>{{ $usuario->name }}</span>
                            @if ($esActor)
                                <x-badge tone="brand" :dot="false">Tú</x-badge>
                            @endif
                            @if (! $usuario->activo)
                                <x-badge tone="neutral">Desactivado</x-badge>
                            @elseif ($usuario->debe_cambiar_password)
                                <x-badge tone="warning">Contraseña temporal</x-badge>
                            @endif
                        </p>
                        <p class="truncate text-xs text-muted">
                            {{ $usuario->email }}@if ($usuario->telefono) · <span class="num">{{ $usuario->telefono }}</span>@endif
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1 pl-13 sm:flex-row sm:items-center sm:justify-between sm:gap-3 md:justify-end md:pl-0">
                    <p class="text-sm md:w-32">
                        <span class="font-medium text-ink">{{ $usuario->rolEtiqueta() }}</span>
                    </p>
                    <div class="-ml-3 flex flex-wrap gap-1 sm:ml-0 sm:justify-end md:w-[19rem]">
                        <x-button size="sm" variant="ghost" data-usuario-editar="{{ json_encode($datosEdicion) }}" aria-label="Editar a {{ $usuario->name }}">Editar</x-button>
                        @unless ($esActor)
                            <x-button size="sm" variant="ghost" icon="key"
                                data-usuario-accion="{{ json_encode([
                                    'url' => route('panel.configuracion.usuarios.password', $usuario->id),
                                    'metodo' => 'POST',
                                    'titulo' => 'Restablecer contraseña',
                                    'texto' => 'Se creará una contraseña temporal para '.$usuario->name.' y se cerrarán sus sesiones abiertas. Tendrá que cambiarla al entrar.',
                                    'boton' => 'Restablecer contraseña',
                                    'ocupado' => 'Restableciendo…',
                                ]) }}"
                                aria-label="Restablecer la contraseña de {{ $usuario->name }}">Contraseña</x-button>
                            @if ($usuario->activo)
                                <x-button size="sm" variant="ghost" class="text-danger-ink!"
                                    data-usuario-accion="{{ json_encode([
                                        'url' => route('panel.configuracion.usuarios.estado', $usuario->id),
                                        'metodo' => 'PATCH',
                                        'activo' => 0,
                                        'titulo' => 'Desactivar cuenta',
                                        'texto' => $usuario->name.' ya no podrá entrar al panel y se cerrarán sus sesiones abiertas. Puedes reactivarla cuando quieras.',
                                        'boton' => 'Desactivar',
                                        'ocupado' => 'Desactivando…',
                                    ]) }}"
                                    aria-label="Desactivar a {{ $usuario->name }}">Desactivar</x-button>
                            @else
                                <x-button size="sm" variant="ghost" class="text-success-ink!"
                                    data-usuario-accion="{{ json_encode([
                                        'url' => route('panel.configuracion.usuarios.estado', $usuario->id),
                                        'metodo' => 'PATCH',
                                        'activo' => 1,
                                        'titulo' => 'Activar cuenta',
                                        'texto' => $usuario->name.' podrá volver a entrar al panel con su contraseña.',
                                        'boton' => 'Activar',
                                        'ocupado' => 'Activando…',
                                    ]) }}"
                                    aria-label="Activar a {{ $usuario->name }}">Activar</x-button>
                            @endif
                        @endunless
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</div>

@push('modals')
    {{-- Crear o editar usuario --}}
    <x-modal id="modal-usuario" title="Nuevo usuario">
        <form
            method="POST"
            action="{{ route('panel.configuracion.usuarios.store') }}"
            data-url-crear="{{ route('panel.configuracion.usuarios.store') }}"
            data-ajax-form
            novalidate
            class="flex flex-col gap-4"
        >
            @csrf
            <input type="hidden" name="_method" value="POST">

            @include('panel.configuracion._resumen', ['claves' => ['usuario']])

            <x-campo label="Nombre" for="usuario-nombre" error="name">
                <x-input name="name" id="usuario-nombre" maxlength="120" required autocomplete="off" placeholder="Ej.: Rosa Huamán" />
            </x-campo>

            <div data-solo-nuevo>
                <x-campo label="Correo" for="usuario-email" error="email" hint="Con este correo entrará al panel.">
                    <x-input name="email" id="usuario-email" type="email" maxlength="150" required autocomplete="off" :hint="true" />
                </x-campo>
            </div>
            <div data-solo-edicion hidden>
                <p class="mb-2 text-sm font-medium text-ink">Correo</p>
                <p class="flex h-11 items-center rounded-control bg-raised px-3 text-sm text-muted lg:h-10" data-email-fijo></p>
            </div>

            <x-campo label="Celular" for="usuario-telefono" error="telefono" optional>
                <x-input name="telefono" id="usuario-telefono" type="tel" inputmode="numeric" maxlength="15" autocomplete="off" placeholder="987654321" />
            </x-campo>

            <x-campo label="Rol" for="usuario-rol" error="rol">
                <x-input as="select" name="rol" id="usuario-rol" required>
                    @foreach ($roles as $clave => $etiqueta)
                        <option value="{{ $clave }}" @selected($clave === 'cajero')>{{ $etiqueta }}: {{ $descripcionRoles[$clave] ?? '' }}</option>
                    @endforeach
                </x-input>
                <input type="hidden" name="rol" value="" data-rol-fijo disabled>
                <p class="mt-1.5 text-xs text-muted" data-rol-propio hidden>No puedes cambiar tu propio rol. Pide a otro administrador que lo haga.</p>
            </x-campo>

            <div class="sticky bottom-0 -mx-5 -mb-5 flex flex-col-reverse gap-3 border-t border-rule bg-surface px-5 py-4 sm:flex-row sm:items-center sm:justify-end">
                <div class="guardado-estado flex min-w-0 items-center gap-2 text-sm" role="status" aria-live="polite" data-form-estado hidden></div>
                <x-button variant="secondary" data-modal-close>Cancelar</x-button>
                <button type="submit" class="press inline-flex h-11 items-center justify-center gap-2 rounded-control bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800 disabled:bg-brand-200 disabled:text-brand-900 lg:h-10" data-guardar>
                    <span class="size-4 animate-spin-fast rounded-full border-2 border-brand-300 border-t-white" data-guardar-spinner aria-hidden="true" hidden></span>
                    <span data-guardar-texto>Crear usuario</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Contraseña temporal: llega en la respuesta JSON y se borra del DOM al cerrar --}}
    <x-modal id="modal-clave" title="Contraseña temporal" size="sm">
        <div class="flex flex-col gap-4 text-sm">
            <p data-clave-mensaje></p>
            <div class="rounded-card bg-brand-50 p-4">
                <p class="text-xs text-muted">Para <span class="font-medium text-ink" data-clave-nombre></span> · <span data-clave-email></span></p>
                <p id="clave-temporal-valor" class="clave-temporal mt-2 break-all text-2xl font-semibold text-brand-900" data-clave-valor></p>
            </div>
            <p class="flex items-start gap-2 rounded-control bg-warning-bg px-3 py-2.5 text-warning-ink">
                <x-icon name="alert" class="mt-0.5" />
                <span>Cópiala y entrégala ahora: <strong class="font-semibold">no se vuelve a mostrar</strong>. Al entrar, le pediremos cambiarla.</span>
            </p>
        </div>
        <x-slot:footer>
            <button type="button" class="press inline-flex h-11 items-center justify-center gap-2 rounded-control border border-control bg-surface px-4 text-sm font-medium text-ink hover:bg-raised lg:h-10" data-copiar="" data-copiar-respaldo="clave-temporal-valor">
                <x-icon name="copy" /><span data-copiar-texto>Copiar contraseña</span>
            </button>
            <x-button variant="primary" data-modal-close>Listo, ya la copié</x-button>
        </x-slot:footer>
    </x-modal>

    {{-- Confirmación de desactivar, activar o restablecer --}}
    <x-modal id="modal-confirmar-usuario" title="Confirmar" size="sm">
        <form method="POST" action="#" data-ajax-form novalidate class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="_method" value="POST">
            <input type="hidden" name="activo" value="">

            @include('panel.configuracion._resumen', ['claves' => []])
            <div data-error-for="activo" id="error-activo" class="rounded-control bg-danger-bg px-3 py-2.5 text-sm text-danger-ink" hidden></div>
            <div data-error-for="usuario" id="error-usuario-accion" class="rounded-control bg-danger-bg px-3 py-2.5 text-sm text-danger-ink" hidden></div>

            <p class="text-sm" data-confirmar-texto></p>

            <div class="-mx-5 -mb-5 flex flex-col-reverse gap-3 border-t border-rule px-5 py-4 sm:flex-row sm:items-center sm:justify-end">
                <div class="guardado-estado flex min-w-0 items-center gap-2 text-sm" role="status" aria-live="polite" data-form-estado hidden></div>
                <x-button variant="secondary" data-modal-close>Cancelar</x-button>
                <button type="submit" class="press inline-flex h-11 items-center justify-center gap-2 rounded-control bg-brand-700 px-4 text-sm font-medium text-white hover:bg-brand-800 disabled:bg-brand-200 disabled:text-brand-900 lg:h-10" data-guardar>
                    <span class="size-4 animate-spin-fast rounded-full border-2 border-brand-300 border-t-white" data-guardar-spinner aria-hidden="true" hidden></span>
                    <span data-guardar-texto>Confirmar</span>
                </button>
            </div>
        </form>
    </x-modal>
@endpush
