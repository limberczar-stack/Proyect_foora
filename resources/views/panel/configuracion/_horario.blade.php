@php
    /**
     * @var \Illuminate\Support\Collection<int, \App\Models\HorarioAtencion> $horarios  por dia_semana
     * @var array<int, string> $dias
     */
    $hora = fn (?string $valor): string => $valor ? substr($valor, 0, 5) : '';
    $sinHorarios = $horarios->isEmpty();
@endphp

<div data-config-horario>
    <form method="POST" action="{{ route('panel.configuracion.horario.update') }}" data-ajax-form novalidate>
        @csrf
        @method('PUT')

        @include('panel.configuracion._resumen', ['claves' => ['dias']])

        <section class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8" aria-labelledby="titulo-horario" data-form-campos>
            <div>
                <h2 id="titulo-horario" class="text-section font-semibold">Horario de atención</h2>
                <p class="mt-1 text-sm text-muted">Fuera de este horario la vitrina muestra el local como cerrado, aunque el interruptor diga «Abierto».</p>
                <p class="mt-3 text-sm text-muted">Si cierras pasada la medianoche, pon la hora de cierre del día siguiente: por ejemplo, de 18:00 a 02:00.</p>
            </div>

            <div class="rounded-card border border-line bg-surface">
                @if ($sinHorarios)
                    <p class="flex items-start gap-2 border-b border-rule px-4 py-3 text-sm text-info-ink sm:px-5">
                        <x-icon name="clock" class="mt-0.5" />
                        Todavía no tienes horario: mientras tanto, solo manda el interruptor «Abierto / Cerrado».
                    </p>
                @endif

                <ul class="divide-y divide-rule">
                    @foreach ($dias as $numero => $nombre)
                        @php
                            $guardado = $horarios->get($numero);
                            $cerrado = (bool) old("dias.{$numero}.cerrado", $guardado?->cerrado ?? false);
                            $abre = old("dias.{$numero}.abre", $hora($guardado?->abre) ?: ($sinHorarios ? '12:00' : ''));
                            $cierra = old("dias.{$numero}.cierra", $hora($guardado?->cierra) ?: ($sinHorarios ? '22:00' : ''));
                        @endphp
                        <li class="flex flex-col gap-3 px-4 py-3.5 sm:px-5 md:flex-row md:items-start md:gap-4" data-dia="{{ $numero }}" data-dia-nombre="{{ mb_strtolower($nombre) }}">
                            <div class="flex items-center justify-between gap-3 md:w-44 md:shrink-0 md:pt-2">
                                <p class="font-medium text-ink" id="dia-{{ $numero }}">{{ $nombre }}</p>
                                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-muted">
                                    <input type="hidden" name="dias[{{ $numero }}][cerrado]" value="0">
                                    <input
                                        type="checkbox"
                                        name="dias[{{ $numero }}][cerrado]"
                                        value="1"
                                        class="size-4 rounded border-control accent-brand-700"
                                        aria-describedby="dia-{{ $numero }}"
                                        data-dia-cerrado
                                        @checked($cerrado)
                                    >
                                    Cerrado
                                </label>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2" data-dia-horas>
                                    <label for="dia-{{ $numero }}-abre" class="sr-only">{{ $nombre }}: abre a las</label>
                                    <div class="w-32"><x-input type="time" name="dias[{{ $numero }}][abre]" id="dia-{{ $numero }}-abre" :value="$abre" step="300" data-dia-abre :disabled="$cerrado" /></div>
                                    <span class="text-sm text-muted" aria-hidden="true">a</span>
                                    <label for="dia-{{ $numero }}-cierra" class="sr-only">{{ $nombre }}: cierra a las</label>
                                    <div class="w-32"><x-input type="time" name="dias[{{ $numero }}][cierra]" id="dia-{{ $numero }}-cierra" :value="$cierra" step="300" data-dia-cierra :disabled="$cerrado" /></div>
                                    <span class="text-xs font-medium text-info-ink" data-dia-madrugada hidden>Cierra al día siguiente</span>
                                </div>
                                @foreach (['abre', 'cierra'] as $campo)
                                    <p id="error-dias-{{ $numero }}-{{ $campo }}" data-error-for="dias.{{ $numero }}.{{ $campo }}" class="mt-1.5 text-xs text-danger-ink" @unless ($errors->has("dias.{$numero}.{$campo}")) hidden @endunless>{{ $errors->first("dias.{$numero}.{$campo}") }}</p>
                                @endforeach
                            </div>

                            @if ($loop->first)
                                <button type="button" class="press inline-flex h-10 shrink-0 items-center gap-2 self-start rounded-control px-3 text-[0.8125rem] font-medium text-brand-700 hover:bg-brand-50 md:mt-0.5" data-copiar-dia aria-label="Copiar el horario del {{ mb_strtolower($nombre) }} a todos los días">
                                    <x-icon name="copy" size="14" /> Copiar a todos los días
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        @include('panel.configuracion._guardar', ['textoBoton' => 'Guardar horario'])
    </form>
</div>
