@php
    /**
     * @var \App\Models\Restaurante $restaurante
     * @var \Illuminate\Support\Collection<int, \App\Models\Rubro> $rubros
     */
    $logo = $restaurante->logoUrl();
    $enlace = $restaurante->urlVitrina();
@endphp

<div data-config-general>
    <form
        method="POST"
        action="{{ route('panel.configuracion.general.update') }}"
        enctype="multipart/form-data"
        data-ajax-form
        novalidate
    >
        @csrf
        @method('PUT')
        <input type="hidden" name="quitar_logo" value="0">

        @include('panel.configuracion._resumen', ['claves' => []])

        <div class="flex flex-col gap-8" data-form-campos>
            <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-identidad">
                <div>
                    <h2 id="titulo-identidad" class="text-section font-semibold">Tu local</h2>
                    <p class="mt-1 text-sm text-muted">Así te ven tus clientes en la vitrina.</p>
                </div>
                <div class="flex flex-col gap-5 rounded-card border border-line bg-surface p-4 sm:p-5">
                    {{-- Logo --}}
                    <div>
                        <p class="mb-2 text-sm font-medium text-ink" id="logo-etiqueta">Logo</p>
                        <div class="flex items-center gap-4">
                            <div class="relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-card bg-brand-100">
                                <img src="{{ $logo }}" alt="Logo actual de {{ $restaurante->nombre }}" class="size-full object-cover" data-logo-imagen @unless ($logo) hidden @endunless>
                                <span class="text-xl font-semibold text-brand-800" data-logo-iniciales aria-hidden="true" @if ($logo) hidden @endif>{{ $restaurante->iniciales() }}</span>
                            </div>
                            <div class="flex min-w-0 flex-col gap-2">
                                <div class="flex flex-wrap gap-2">
                                    <x-button size="sm" icon="image" data-logo-elegir aria-describedby="logo-ayuda">{{ $logo ? 'Cambiar logo' : 'Subir logo' }}</x-button>
                                    <x-button size="sm" variant="ghost" data-logo-quitar :hidden="! $logo">Quitar</x-button>
                                </div>
                                <p id="logo-ayuda" class="text-xs text-muted">JPG, PNG o WEBP de hasta 2 MB. Mejor cuadrado.</p>
                                <p class="text-xs font-medium text-warning-ink" data-logo-pendiente hidden>Falta guardar para aplicar el cambio.</p>
                            </div>
                        </div>
                        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" aria-labelledby="logo-etiqueta" data-logo-archivo>
                        <p id="error-logo" data-error-for="logo" class="mt-1.5 text-xs text-danger-ink" @unless ($errors->has('logo')) hidden @endunless>{{ $errors->first('logo') }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-campo label="Nombre del local" for="general-nombre" error="nombre" class="sm:col-span-2">
                            <x-input name="nombre" id="general-nombre" :value="old('nombre', $restaurante->nombre)" maxlength="120" required autocomplete="organization" />
                        </x-campo>

                        <x-campo label="Rubro" for="general-rubro" error="rubro_id" optional>
                            <x-input as="select" name="rubro_id" id="general-rubro">
                                <option value="">Sin rubro</option>
                                @foreach ($rubros as $rubro)
                                    <option value="{{ $rubro->id }}" @selected((int) old('rubro_id', $restaurante->rubro_id) === $rubro->id)>{{ $rubro->nombre }}</option>
                                @endforeach
                            </x-input>
                        </x-campo>

                        <x-campo label="Descripción" for="general-descripcion" error="descripcion" optional hint="Una o dos frases: qué cocinas y qué te hace distinto." class="sm:col-span-2">
                            <x-input as="textarea" name="descripcion" id="general-descripcion" :value="old('descripcion', $restaurante->descripcion)" maxlength="1000" rows="3" :hint="true" />
                        </x-campo>
                    </div>
                </div>
            </section>

            <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-contacto">
                <div>
                    <h2 id="titulo-contacto" class="text-section font-semibold">Contacto y ubicación</h2>
                    <p class="mt-1 text-sm text-muted">El celular se usa para el botón de WhatsApp de la vitrina.</p>
                </div>
                <div class="grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 sm:p-5">
                    <x-campo label="Celular" for="general-telefono" error="telefono" optional hint="9 dígitos, por ejemplo 987654321.">
                        <x-input name="telefono" id="general-telefono" type="tel" inputmode="numeric" :value="old('telefono', $restaurante->telefono)" maxlength="15" autocomplete="tel-national" :hint="true" />
                    </x-campo>

                    <x-campo label="Correo" for="general-email" error="email" optional>
                        <x-input name="email" id="general-email" type="email" :value="old('email', $restaurante->email)" maxlength="150" autocomplete="email" />
                    </x-campo>

                    <x-campo label="Dirección" for="general-direccion" error="direccion" optional class="sm:col-span-2">
                        <x-input name="direccion" id="general-direccion" :value="old('direccion', $restaurante->direccion)" maxlength="255" autocomplete="street-address" placeholder="Ej.: Jr. Huallayco 845" />
                    </x-campo>

                    <x-campo label="Ciudad" for="general-ciudad" error="ciudad">
                        <x-input name="ciudad" id="general-ciudad" :value="old('ciudad', $restaurante->ciudad)" maxlength="80" required autocomplete="address-level2" />
                    </x-campo>
                </div>
            </section>

            <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-vitrina">
                <div>
                    <h2 id="titulo-vitrina" class="text-section font-semibold">Enlace de tu vitrina</h2>
                    <p class="mt-1 text-sm text-muted">Compártelo por WhatsApp o en tus redes.</p>
                </div>
                <div class="rounded-card border border-line bg-surface p-4 sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <p class="flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-control bg-raised px-3 text-sm lg:min-h-10">
                            <x-icon name="link" class="text-faint" />
                            <span id="enlace-vitrina" class="min-w-0 truncate text-ink">{{ $enlace }}</span>
                        </p>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" class="press inline-flex h-11 items-center gap-2 rounded-control border border-control bg-surface px-4 text-sm font-medium text-ink hover:bg-raised lg:h-10" data-copiar="{{ $enlace }}" data-copiar-respaldo="enlace-vitrina">
                                <x-icon name="copy" /><span data-copiar-texto>Copiar enlace</span>
                            </button>
                            <x-button variant="ghost" icon="external" :href="$enlace" target="_blank" rel="noopener">Abrir</x-button>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-muted">
                        La dirección <span class="font-medium text-ink">/{{ $restaurante->slug }}</span> no se puede cambiar: los enlaces que ya compartiste dejarían de funcionar.
                    </p>
                </div>
            </section>
        </div>

        @include('panel.configuracion._guardar')
    </form>
</div>
